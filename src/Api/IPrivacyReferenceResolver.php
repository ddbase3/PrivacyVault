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
 * Resolves stored domain references such as ilias_user:4711:fullname.
 */
interface IPrivacyReferenceResolver extends IBase {

	/**
	 * Checks whether this resolver can resolve the reference shape.
	 */
	public function supports(string $referenceType, string $valueKey): bool;

	/**
	 * Resolves a domain reference to the current display value.
	 */
	public function resolve(
		string $referenceType,
		string $referenceId,
		string $valueKey,
		array $context = []
	): ?string;
}
