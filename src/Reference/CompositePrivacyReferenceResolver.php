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

namespace PrivacyVault\Reference;

use PrivacyVault\Api\IPrivacyReferenceResolver;

/**
 * Delegates reference resolution to registered resolver implementations.
 */
class CompositePrivacyReferenceResolver implements IPrivacyReferenceResolver {

	/** @var IPrivacyReferenceResolver[] */
	private array $resolvers = [];

	/**
	 * @param IPrivacyReferenceResolver[] $resolvers
	 */
	public function __construct(array $resolvers = []) {
		foreach ($resolvers as $resolver) {
			$this->addResolver($resolver);
		}
	}

	public static function getName(): string {
		return 'compositeprivacyreferenceresolver';
	}

	public function addResolver(IPrivacyReferenceResolver $resolver): void {
		if ($resolver === $this) {
			return;
		}

		$this->resolvers[] = $resolver;
	}

	public function supports(string $referenceType, string $valueKey): bool {
		foreach ($this->resolvers as $resolver) {
			if ($resolver->supports($referenceType, $valueKey)) {
				return true;
			}
		}

		return false;
	}

	public function resolve(
		string $referenceType,
		string $referenceId,
		string $valueKey,
		array $context = []
	): ?string {
		foreach ($this->resolvers as $resolver) {
			if (!$resolver->supports($referenceType, $valueKey)) {
				continue;
			}

			$value = $resolver->resolve($referenceType, $referenceId, $valueKey, $context);

			if ($value !== null) {
				return $value;
			}
		}

		return null;
	}
}
