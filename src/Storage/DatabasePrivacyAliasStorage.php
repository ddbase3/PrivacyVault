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

namespace PrivacyVault\Storage;

use Base3\Database\Api\IDatabase;
use PrivacyVault\Api\IPrivacyAliasStorage;
use PrivacyVault\Dto\PrivacyAliasRecord;

/**
 * Database-backed alias storage for the MVP.
 */
class DatabasePrivacyAliasStorage implements IPrivacyAliasStorage {

	private bool $ensured = false;

	public function __construct(private readonly IDatabase $database) {}

	public static function getName(): string {
		return 'databaseprivacyaliasstorage';
	}

	public function ensureTable(): void {
		if ($this->ensured) {
			return;
		}

		$this->database->connect();
		$this->database->nonQuery("CREATE TABLE IF NOT EXISTS base3_privacy_alias (
			id BIGINT NOT NULL AUTO_INCREMENT,
			alias_token VARCHAR(150) NOT NULL,
			alias_type VARCHAR(50) NOT NULL,
			storage_mode VARCHAR(30) NOT NULL DEFAULT 'value',
			value_format VARCHAR(30) NOT NULL DEFAULT 'plain',
			label LONGTEXT DEFAULT NULL,
			protected_value LONGTEXT DEFAULT NULL,
			reference_json LONGTEXT DEFAULT NULL,
			context_json LONGTEXT DEFAULT NULL,
			context_key VARCHAR(150) DEFAULT NULL,
			metadata_json LONGTEXT DEFAULT NULL,
			created DATETIME NOT NULL,
			expires DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY ux_alias_token (alias_token),
			KEY ix_alias_type (alias_type),
			KEY ix_storage_mode (storage_mode),
			KEY ix_context_key (context_key),
			KEY ix_expires (expires)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->throwOnError('Could not ensure base3_privacy_alias table.');
		$this->ensured = true;
	}

	public function insert(PrivacyAliasRecord $record): void {
		$this->ensureTable();

		$query = "INSERT INTO base3_privacy_alias (
			alias_token,
			alias_type,
			storage_mode,
			value_format,
			label,
			protected_value,
			reference_json,
			context_json,
			context_key,
			metadata_json,
			created,
			expires
		) VALUES (" . implode(', ', [
			$this->quote($record->aliasToken),
			$this->quote($record->aliasType),
			$this->quote($record->storageMode),
			$this->quote($record->valueFormat),
			$this->quote($record->label),
			$this->quote($record->protectedValue),
			$this->quote($this->encodeJson($record->reference)),
			$this->quote($this->encodeJson($record->context)),
			$this->quote($record->contextKey),
			$this->quote($this->encodeJson($record->metadata)),
			$this->quote($record->created),
			$this->quote($record->expires),
		]) . ")";

		$this->database->nonQuery($query);
		$this->throwOnError('Could not insert privacy alias record.');
	}

	public function findByToken(string $aliasToken): ?PrivacyAliasRecord {
		$this->ensureTable();

		$row = $this->database->singleQuery(
			"SELECT * FROM base3_privacy_alias WHERE alias_token = " . $this->quote($aliasToken) . " LIMIT 1"
		);
		$this->throwOnError('Could not read privacy alias record.');

		if ($row === null) {
			return null;
		}

		return $this->recordFromRow($row);
	}

	public function deleteExpired(): int {
		$this->ensureTable();

		$this->database->nonQuery("DELETE FROM base3_privacy_alias WHERE expires IS NOT NULL AND expires < NOW()");
		$this->throwOnError('Could not delete expired privacy aliases.');

		return $this->database->affectedRows();
	}

	private function recordFromRow(array $row): PrivacyAliasRecord {
		return new PrivacyAliasRecord(
			isset($row['id']) ? (int) $row['id'] : null,
			(string) $row['alias_token'],
			(string) $row['alias_type'],
			(string) $row['storage_mode'],
			(string) $row['value_format'],
			isset($row['label']) ? (string) $row['label'] : null,
			isset($row['protected_value']) ? (string) $row['protected_value'] : null,
			$this->decodeJson($row['reference_json'] ?? null),
			$this->decodeJson($row['context_json'] ?? null) ?? [],
			isset($row['context_key']) ? (string) $row['context_key'] : null,
			$this->decodeJson($row['metadata_json'] ?? null) ?? [],
			(string) $row['created'],
			isset($row['expires']) ? (string) $row['expires'] : null
		);
	}

	private function encodeJson(?array $data): ?string {
		if ($data === null) {
			return null;
		}

		return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	private function decodeJson(mixed $json): ?array {
		if ($json === null || $json === '') {
			return null;
		}

		$data = json_decode((string) $json, true);

		return is_array($data) ? $data : null;
	}

	private function quote(?string $value): string {
		if ($value === null) {
			return 'NULL';
		}

		return "'" . $this->database->escape($value) . "'";
	}

	private function throwOnError(string $message): void {
		if (!$this->database->isError()) {
			return;
		}

		throw new \RuntimeException($message . ' Database error ' . $this->database->errorNumber() . ': ' . $this->database->errorMessage());
	}
}
