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
 * Supported storage modes for alias records.
 */
final class PrivacyAliasStorageMode {

	public const VALUE = 'value';
	public const REFERENCE = 'reference';
	public const HYBRID = 'hybrid';
	public const STATELESS = 'stateless';
}
