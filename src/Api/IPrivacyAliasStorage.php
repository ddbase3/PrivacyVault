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
use PrivacyVault\Dto\PrivacyAliasRecord;

/**
 * Internal storage API for alias records.
 */
interface IPrivacyAliasStorage extends IBase {

	/**
	 * Ensures that the storage backend exists.
	 */
	public function ensureTable(): void;

	/**
	 * Inserts a new alias record.
	 */
	public function insert(PrivacyAliasRecord $record): void;

	/**
	 * Finds one alias record by its visible token.
	 */
	public function findByToken(string $aliasToken): ?PrivacyAliasRecord;

	/**
	 * Deletes expired aliases and returns the number of affected rows.
	 */
	public function deleteExpired(): int;
}
