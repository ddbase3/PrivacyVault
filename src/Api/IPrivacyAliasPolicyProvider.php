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
 * Provides optional privacy policies for structured tool results.
 */
interface IPrivacyAliasPolicyProvider extends IBase {

	/**
	 * Returns a tool policy as an array or null when no policy exists.
	 */
	public function getPolicy(string $toolKey): ?array;

	/**
	 * Returns the prompt instruction fragment for models that receive aliases.
	 */
	public function getPromptInstruction(): string;
}
