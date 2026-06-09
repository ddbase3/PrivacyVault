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

namespace PrivacyVault\Dto;

/**
 * Immutable storage record for one privacy alias.
 */
class PrivacyAliasRecord {

	public function __construct(
		public readonly ?int $id,
		public readonly string $aliasToken,
		public readonly string $aliasType,
		public readonly string $storageMode,
		public readonly string $valueFormat,
		public readonly ?string $label,
		public readonly ?string $protectedValue,
		public readonly ?array $reference,
		public readonly array $context,
		public readonly ?string $contextKey,
		public readonly array $metadata,
		public readonly string $created,
		public readonly ?string $expires
	) {}
}
