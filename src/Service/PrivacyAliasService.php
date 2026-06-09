<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of PrivacyVault for BASE3 Framework.
 *
 * PrivacyVault provides alias-based pseudonymization helpers for LLM
 * tool results and server-side output rendering.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 **********************************************************************/

namespace PrivacyVault\Service;

use PrivacyVault\Api\IPrivacyAliasService;
use PrivacyVault\Api\IPrivacyAliasStorage;
use PrivacyVault\Api\IPrivacyAliasTokenGenerator;
use PrivacyVault\Api\IPrivacyContextProvider;
use PrivacyVault\Api\IPrivacyReferenceResolver;
use PrivacyVault\Api\IPrivacyValueCryptor;
use PrivacyVault\Context\DefaultPrivacyContextProvider;
use PrivacyVault\Dto\PrivacyAliasRecord;
use PrivacyVault\Value\PrivacyAliasStorageMode;
use PrivacyVault\Value\PrivacyAliasType;
use PrivacyVault\Value\PrivacyValueFormat;

/**
 * Default alias service used by tools and output pipelines.
 */
class PrivacyAliasService implements IPrivacyAliasService {

	public const DEFAULT_TTL_SECONDS = 86400;

	private readonly IPrivacyAliasStorage $storage;
	private readonly IPrivacyAliasTokenGenerator $tokenGenerator;
	private readonly IPrivacyValueCryptor $valueCryptor;
	private readonly IPrivacyReferenceResolver $referenceResolver;
	private readonly int $defaultTtlSeconds;
	private readonly IPrivacyContextProvider $contextProvider;

	public function __construct(
		IPrivacyAliasStorage $storage,
		IPrivacyAliasTokenGenerator $tokenGenerator,
		IPrivacyValueCryptor $valueCryptor,
		IPrivacyReferenceResolver $referenceResolver,
		int $defaultTtlSeconds = self::DEFAULT_TTL_SECONDS,
		?IPrivacyContextProvider $contextProvider = null
	) {
		$this->storage = $storage;
		$this->tokenGenerator = $tokenGenerator;
		$this->valueCryptor = $valueCryptor;
		$this->referenceResolver = $referenceResolver;
		$this->defaultTtlSeconds = $defaultTtlSeconds;
		$this->contextProvider = $contextProvider ?? new DefaultPrivacyContextProvider();
	}

	public static function getName(): string {
		return 'privacyaliasservice';
	}

	public function ensureStorage(): void {
		$this->storage->ensureTable();
	}

	public function getCurrentContext(array $context = []): array {
		return $this->contextProvider->getContext($context);
	}

	public function createAlias(string $value, string $type, array $context = []): string {
		$type = PrivacyAliasType::normalize($type);
		$payload = [
			'storage_mode' => PrivacyAliasStorageMode::VALUE,
		];

		return $this->createStoredAlias(
			$type,
			PrivacyAliasStorageMode::VALUE,
			$this->valueCryptor->getFormat(),
			null,
			$this->valueCryptor->encrypt($value, $context),
			null,
			$context,
			$payload
		);
	}

	public function createAliasForCurrentContext(string $value, string $type, array $context = []): string {
		return $this->createAlias($value, $type, $this->getCurrentContext($context));
	}

	public function createReferenceAlias(
		string $referenceType,
		string $referenceId,
		string $valueKey,
		string $type,
		array $context = [],
		?string $label = null
	): string {
		$type = PrivacyAliasType::normalize($type);
		$reference = [
			'type' => $referenceType,
			'id' => $referenceId,
			'value_key' => $valueKey,
		];
		$storageMode = $label === null ? PrivacyAliasStorageMode::REFERENCE : PrivacyAliasStorageMode::HYBRID;
		$valueFormat = $label === null ? PrivacyValueFormat::REFERENCE_ONLY : $this->valueCryptor->getFormat();
		$protectedValue = $label === null ? null : $this->valueCryptor->encrypt($label, $context);

		return $this->createStoredAlias(
			$type,
			$storageMode,
			$valueFormat,
			null,
			$protectedValue,
			$reference,
			$context,
			[
				'storage_mode' => $storageMode,
				'reference_type' => $referenceType,
				'value_key' => $valueKey,
			]
		);
	}

	public function createReferenceAliasForCurrentContext(
		string $referenceType,
		string $referenceId,
		string $valueKey,
		string $type,
		array $context = [],
		?string $label = null
	): string {
		return $this->createReferenceAlias(
			$referenceType,
			$referenceId,
			$valueKey,
			$type,
			$this->getCurrentContext($context),
			$label
		);
	}

	public function resolveAlias(string $aliasToken, array $context = []): ?string {
		if (!$this->looksLikeAliasToken($aliasToken)) {
			return null;
		}

		$record = $this->storage->findByToken($aliasToken);

		if ($record === null || $this->isExpired($record) || !$this->contextMatches($record->context, $context)) {
			return null;
		}

		if ($record->storageMode === PrivacyAliasStorageMode::VALUE) {
			return $this->decryptProtectedValue($record, $context);
		}

		if ($record->storageMode === PrivacyAliasStorageMode::REFERENCE || $record->storageMode === PrivacyAliasStorageMode::HYBRID) {
			$resolvedReference = $this->resolveReferenceValue($record, $context);

			if ($resolvedReference !== null) {
				return $resolvedReference;
			}

			return $this->decryptProtectedValue($record, $context);
		}

		return null;
	}

	public function resolveAliasForCurrentContext(string $aliasToken, array $context = []): ?string {
		return $this->resolveAlias($aliasToken, $this->getCurrentContext($context));
	}

	public function replaceAliases(string $text, array $context = []): string {
		return preg_replace_callback('/\[[A-Za-z][A-Za-z0-9_]*_[A-Za-z0-9]{6,128}\]/', function (array $matches) use ($context): string {
			$resolved = $this->resolveAlias($matches[0], $context);

			return $resolved ?? $matches[0];
		}, $text) ?? $text;
	}

	public function replaceAliasesForCurrentContext(string $text, array $context = []): string {
		return $this->replaceAliases($text, $this->getCurrentContext($context));
	}

	private function createStoredAlias(
		string $type,
		string $storageMode,
		string $valueFormat,
		?string $label,
		?string $protectedValue,
		?array $reference,
		array $context,
		array $metadata
	): string {
		$this->ensureStorage();

		for ($i = 0; $i < 5; $i++) {
			$aliasToken = $this->tokenGenerator->createToken($type, $metadata, $context);
			$record = new PrivacyAliasRecord(
				null,
				$aliasToken,
				$type,
				$storageMode,
				$valueFormat,
				$label,
				$protectedValue,
				$reference,
				$context,
				$this->createContextKey($context),
				array_merge($metadata, [
					'generator' => $this->tokenGenerator::getName(),
					'created_by' => self::getName(),
				]),
				$this->now(),
				$this->createExpiryDate()
			);

			try {
				$this->storage->insert($record);

				return $aliasToken;
			} catch (\RuntimeException $exception) {
				if (!str_contains($exception->getMessage(), '1062')) {
					throw $exception;
				}
			}
		}

		throw new \RuntimeException('Could not create a unique privacy alias token.');
	}

	private function resolveReferenceValue(PrivacyAliasRecord $record, array $context): ?string {
		if (!is_array($record->reference)) {
			return null;
		}

		$referenceType = isset($record->reference['type']) ? (string) $record->reference['type'] : '';
		$referenceId = isset($record->reference['id']) ? (string) $record->reference['id'] : '';
		$valueKey = isset($record->reference['value_key']) ? (string) $record->reference['value_key'] : '';

		if ($referenceType === '' || $referenceId === '' || $valueKey === '') {
			return null;
		}

		if (!$this->referenceResolver->supports($referenceType, $valueKey)) {
			return null;
		}

		return $this->referenceResolver->resolve($referenceType, $referenceId, $valueKey, $context);
	}

	private function decryptProtectedValue(PrivacyAliasRecord $record, array $context): ?string {
		if ($record->protectedValue === null) {
			return null;
		}

		return $this->valueCryptor->decrypt($record->protectedValue, $context);
	}

	private function looksLikeAliasToken(string $aliasToken): bool {
		return preg_match('/^\[[A-Za-z][A-Za-z0-9_]*_[A-Za-z0-9]{6,128}\]$/', $aliasToken) === 1;
	}

	private function isExpired(PrivacyAliasRecord $record): bool {
		if ($record->expires === null || $record->expires === '') {
			return false;
		}

		return strtotime($record->expires) < time();
	}

	private function contextMatches(array $storedContext, array $providedContext): bool {
		if ($storedContext === []) {
			return true;
		}

		foreach ($storedContext as $key => $storedValue) {
			if (!array_key_exists($key, $providedContext)) {
				return false;
			}

			$providedValue = $providedContext[$key];

			if (is_array($storedValue)) {
				if (!is_array($providedValue) || !$this->contextMatches($storedValue, $providedValue)) {
					return false;
				}

				continue;
			}

			if ((string) $storedValue !== (string) $providedValue) {
				return false;
			}
		}

		return true;
	}

	private function createContextKey(array $context): ?string {
		if ($context === []) {
			return null;
		}

		$context = $this->sortRecursive($context);

		return 'sha256:' . hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private function sortRecursive(array $data): array {
		ksort($data);

		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$data[$key] = $this->sortRecursive($value);
			}
		}

		return $data;
	}

	private function now(): string {
		return date('Y-m-d H:i:s');
	}

	private function createExpiryDate(): ?string {
		if ($this->defaultTtlSeconds <= 0) {
			return null;
		}

		return date('Y-m-d H:i:s', time() + $this->defaultTtlSeconds);
	}
}
