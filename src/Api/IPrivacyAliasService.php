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
 * Public service API used by tools and output pipelines.
 */
interface IPrivacyAliasService extends IBase {

	/**
	 * Ensures that the backing storage exists.
	 */
	public function ensureStorage(): void;

	/**
	 * Returns the current privacy context, normally user_id plus session_id.
	 */
	public function getCurrentContext(array $context = []): array;

	/**
	 * Creates an alias for a direct value with an explicitly supplied context.
	 */
	public function createAlias(string $value, string $type, array $context = []): string;

	/**
	 * Creates an alias for a direct value in the current user/session context.
	 */
	public function createAliasForCurrentContext(string $value, string $type, array $context = []): string;

	/**
	 * Creates an alias for a domain reference with an optional fallback label.
	 */
	public function createReferenceAlias(
		string $referenceType,
		string $referenceId,
		string $valueKey,
		string $type,
		array $context = [],
		?string $label = null
	): string;

	/**
	 * Creates a reference alias in the current user/session context.
	 */
	public function createReferenceAliasForCurrentContext(
		string $referenceType,
		string $referenceId,
		string $valueKey,
		string $type,
		array $context = [],
		?string $label = null
	): string;

	/**
	 * Resolves one alias token if it exists and the supplied context is allowed.
	 */
	public function resolveAlias(string $aliasToken, array $context = []): ?string;

	/**
	 * Resolves one alias token in the current user/session context.
	 */
	public function resolveAliasForCurrentContext(string $aliasToken, array $context = []): ?string;

	/**
	 * Replaces all resolvable alias tokens in a text.
	 */
	public function replaceAliases(string $text, array $context = []): string;

	/**
	 * Replaces all resolvable alias tokens in the current user/session context.
	 */
	public function replaceAliasesForCurrentContext(string $text, array $context = []): string;
}
