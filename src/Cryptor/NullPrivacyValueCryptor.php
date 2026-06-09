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

namespace PrivacyVault\Cryptor;

use PrivacyVault\Api\IPrivacyValueCryptor;
use PrivacyVault\Value\PrivacyValueFormat;

/**
 * MVP cryptor that stores values unchanged behind the cryptor interface.
 */
class NullPrivacyValueCryptor implements IPrivacyValueCryptor {

	public static function getName(): string {
		return 'nullprivacyvaluecryptor';
	}

	public function encrypt(string $plainValue, array $context = []): string {
		return $plainValue;
	}

	public function decrypt(string $protectedValue, array $context = []): ?string {
		return $protectedValue;
	}

	public function getFormat(): string {
		return PrivacyValueFormat::PLAIN;
	}
}
