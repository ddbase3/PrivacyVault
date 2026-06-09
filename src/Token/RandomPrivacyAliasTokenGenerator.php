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

namespace PrivacyVault\Token;

use PrivacyVault\Api\IPrivacyAliasTokenGenerator;
use PrivacyVault\Value\PrivacyAliasType;

/**
 * Creates non-deterministic alias tokens backed by server-side storage.
 */
class RandomPrivacyAliasTokenGenerator implements IPrivacyAliasTokenGenerator {

	public static function getName(): string {
		return 'randomprivacyaliastokengenerator';
	}

	public function createToken(string $type, array $payload, array $context = []): string {
		$prefix = strtolower(PrivacyAliasType::normalize($type));
		$prefix = preg_replace('/[^a-z0-9_]+/', '_', $prefix) ?? 'value';
		$prefix = trim($prefix, '_');

		if ($prefix === '') {
			$prefix = 'value';
		}

		return '[' . $prefix . '_' . bin2hex(random_bytes(8)) . ']';
	}

	public function isDeterministic(): bool {
		return false;
	}
}
