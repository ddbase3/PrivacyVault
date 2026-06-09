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
 * Provides the context used to bind aliases to the current user/session.
 */
interface IPrivacyContextProvider extends IBase {

	/**
	 * Returns the current privacy context and merges additional caller context.
	 */
	public function getContext(array $context = []): array;
}
