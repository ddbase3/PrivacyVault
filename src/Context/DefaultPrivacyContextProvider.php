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

namespace PrivacyVault\Context;

use Base3\Accesscontrol\Api\IAccesscontrol;
use Base3\Session\Api\ISession;
use Base3\Usermanager\Api\IUsermanager;
use PrivacyVault\Api\IPrivacyContextProvider;

/**
 * Builds a minimal privacy context from BASE3 user and session services.
 */
class DefaultPrivacyContextProvider implements IPrivacyContextProvider {

	public function __construct(
		private readonly ?IAccesscontrol $accesscontrol = null,
		private readonly ?ISession $session = null,
		private readonly ?IUsermanager $usermanager = null
	) {}

	public static function getName(): string {
		return 'defaultprivacycontextprovider';
	}

	public function getContext(array $context = []): array {
		$userId = $this->readCurrentUserId();
		$sessionId = $this->readCurrentSessionId();

		if ($userId !== null && $userId !== '') {
			$context['user_id'] = $userId;
		}

		if ($sessionId !== null && $sessionId !== '') {
			$context['session_id'] = $sessionId;
		}

		return $context;
	}

	private function readCurrentUserId(): ?string {
		if ($this->accesscontrol !== null) {
			try {
				$userId = $this->scalarToString($this->accesscontrol->getUserId());

				if ($userId !== null && $userId !== '') {
					return $userId;
				}
			} catch (\Throwable) {}
		}

		if ($this->usermanager !== null) {
			try {
				return $this->extractUserId($this->usermanager->getUser());
			} catch (\Throwable) {}
		}

		return null;
	}

	private function readCurrentSessionId(): ?string {
		if ($this->session === null) {
			return null;
		}

		try {
			if (!$this->session->started() && !$this->session->start()) {
				return null;
			}

			return $this->scalarToString($this->session->getId());
		} catch (\Throwable) {
			return null;
		}
	}

	private function extractUserId(mixed $user): ?string {
		if (is_array($user)) {
			foreach (['id', 'user_id', 'usr_id'] as $key) {
				if (!array_key_exists($key, $user)) {
					continue;
				}

				$userId = $this->scalarToString($user[$key]);

				if ($userId !== null && $userId !== '') {
					return $userId;
				}
			}

			return null;
		}

		if (is_object($user) && isset($user->id)) {
			return $this->scalarToString($user->id);
		}

		return $this->scalarToString($user);
	}

	private function scalarToString(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		if (is_scalar($value) || $value instanceof \Stringable) {
			return trim((string) $value);
		}

		return null;
	}
}
