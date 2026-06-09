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
 * Strategy API for creating visible alias tokens.
 */
interface IPrivacyAliasTokenGenerator extends IBase {

	/**
	 * Creates a token for the given semantic type and payload.
	 */
	public function createToken(string $type, array $payload, array $context = []): string;

	/**
	 * Returns true when the same input always produces the same token.
	 */
	public function isDeterministic(): bool;
}
