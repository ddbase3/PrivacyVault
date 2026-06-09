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

namespace PrivacyVault\Value;

/**
 * Common semantic alias types.
 */
final class PrivacyAliasType {

	public const NAME = 'NAME';
	public const EMAIL = 'EMAIL';
	public const PHONE = 'PHONE';
	public const ADDRESS = 'ADDRESS';
	public const STREET = 'STREET';
	public const CITY = 'CITY';
	public const BIRTHDATE = 'BIRTHDATE';
	public const AGE = 'AGE';
	public const USER_ID = 'USER_ID';
	public const COMPANY = 'COMPANY';
	public const IP_ADDRESS = 'IP_ADDRESS';
	public const USERNAME = 'USERNAME';
	public const FREE_TEXT = 'FREE_TEXT';
	public const FREE_TEXT_PII = 'FREE_TEXT_PII';

	public static function normalize(string $type): string {
		$type = strtoupper(trim($type));
		$type = preg_replace('/[^A-Z0-9_]+/', '_', $type) ?? 'VALUE';
		$type = trim($type, '_');

		return $type !== '' ? $type : 'VALUE';
	}
}
