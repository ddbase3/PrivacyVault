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

namespace PrivacyVault\Service;

use PrivacyVault\Api\IPrivacyAliasPolicyProvider;

/**
 * Empty MVP policy provider with a reusable LLM instruction fragment.
 */
class DefaultPrivacyAliasPolicyProvider implements IPrivacyAliasPolicyProvider {

	public static function getName(): string {
		return 'defaultprivacyaliaspolicyprovider';
	}

	public function getPolicy(string $toolKey): ?array {
		return null;
	}

	public function getPromptInstruction(): string {
		return 'You may receive privacy aliases such as [name_x], [email_x], [birthdate_x], [address_x] or [company_x]. Treat them as real values of the indicated type. Keep aliases unchanged in your answer. Never try to resolve, modify, invent or decode privacy aliases.';
	}
}
