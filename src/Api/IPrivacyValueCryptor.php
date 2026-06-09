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

namespace PrivacyVault\Api;

use Base3\Api\IBase;

/**
 * Protects values before they are written to alias storage.
 */
interface IPrivacyValueCryptor extends IBase {

	/**
	 * Protects a plain value for storage.
	 */
	public function encrypt(string $plainValue, array $context = []): string;

	/**
	 * Restores a protected value for output rendering.
	 */
	public function decrypt(string $protectedValue, array $context = []): ?string;

	/**
	 * Returns the storage format written to value_format.
	 */
	public function getFormat(): string;
}
