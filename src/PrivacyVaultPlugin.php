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

namespace Base3\PrivacyVault;

use Base3\CoreFoundation\Accesscontrol\Api\IAccesscontrol;
use Base3\CoreFoundation\Api\IContainer;
use Base3\CoreFoundation\Api\IPlugin;
use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\CoreFoundation\Session\Api\ISession;
use Base3\CoreFoundation\Usermanager\Api\IUsermanager;
use Base3\PrivacyVault\Api\IPrivacyAliasPolicyProvider;
use Base3\PrivacyVault\Api\IPrivacyAliasService;
use Base3\PrivacyVault\Api\IPrivacyAliasStorage;
use Base3\PrivacyVault\Api\IPrivacyAliasTokenGenerator;
use Base3\PrivacyVault\Api\IPrivacyContextProvider;
use Base3\PrivacyVault\Api\IPrivacyReferenceResolver;
use Base3\PrivacyVault\Api\IPrivacyValueCryptor;
use Base3\PrivacyVault\Context\DefaultPrivacyContextProvider;
use Base3\PrivacyVault\Cryptor\NullPrivacyValueCryptor;
use Base3\PrivacyVault\Reference\CompositePrivacyReferenceResolver;
use Base3\PrivacyVault\Service\DefaultPrivacyAliasPolicyProvider;
use Base3\PrivacyVault\Service\PrivacyAliasService;
use Base3\PrivacyVault\Storage\DatabasePrivacyAliasStorage;
use Base3\PrivacyVault\Token\RandomPrivacyAliasTokenGenerator;

/**
 * BASE3 plugin entry point for PrivacyVault.
 */
class PrivacyVaultPlugin implements IPlugin {

	public function __construct(private readonly IContainer $container) {}

	public static function getName(): string {
		return 'privacyvaultplugin';
	}

	public function init() {
		$this->container
			->set(self::getName(), $this, IContainer::SHARED)
			->set(IPrivacyContextProvider::class, fn($c) => new DefaultPrivacyContextProvider(
				$this->getOptionalAccesscontrol(),
				$this->getOptionalSession(),
				$this->getOptionalUsermanager()
			), IContainer::SHARED)
			->set(IPrivacyAliasTokenGenerator::class, fn($c) => new RandomPrivacyAliasTokenGenerator(), IContainer::SHARED)
			->set(IPrivacyValueCryptor::class, fn($c) => new NullPrivacyValueCryptor(), IContainer::SHARED)
			->set(IPrivacyReferenceResolver::class, fn($c) => new CompositePrivacyReferenceResolver(), IContainer::SHARED)
			->set(IPrivacyAliasPolicyProvider::class, fn($c) => new DefaultPrivacyAliasPolicyProvider(), IContainer::SHARED)
			->set(IPrivacyAliasStorage::class, fn($c) => new DatabasePrivacyAliasStorage(
				$c->get(IDatabase::class)
			), IContainer::SHARED)
			->set(IPrivacyAliasService::class, fn($c) => new PrivacyAliasService(
				$c->get(IPrivacyAliasStorage::class),
				$c->get(IPrivacyAliasTokenGenerator::class),
				$c->get(IPrivacyValueCryptor::class),
				$c->get(IPrivacyReferenceResolver::class),
				PrivacyAliasService::DEFAULT_TTL_SECONDS,
				$c->get(IPrivacyContextProvider::class)
			), IContainer::SHARED);
	}

	private function getOptionalAccesscontrol(): ?IAccesscontrol {
		$service = $this->getOptionalService(IAccesscontrol::class);

		return $service instanceof IAccesscontrol ? $service : null;
	}

	private function getOptionalSession(): ?ISession {
		$service = $this->getOptionalService(ISession::class);

		return $service instanceof ISession ? $service : null;
	}

	private function getOptionalUsermanager(): ?IUsermanager {
		$service = $this->getOptionalService(IUsermanager::class);

		return $service instanceof IUsermanager ? $service : null;
	}

	private function getOptionalService(string $serviceName): ?object {
		try {
			$service = $this->container->get($serviceName);

			return is_object($service) ? $service : null;
		} catch (\Throwable) {
			return null;
		}
	}
}
