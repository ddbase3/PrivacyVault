# PrivacyVault

PrivacyVault is a BASE3 plugin for reversible pseudonymization of sensitive tool results before they enter LLM contexts.

It provides a small, DI-friendly privacy layer that replaces sensitive values such as names, email addresses, phone numbers or user IDs with semantic alias tokens like `[name_1f2a3b4c5d6e]`. The LLM receives only those aliases. After the LLM has generated its response, the server-side output pipeline can resolve the aliases back to clear values for the authorized user and session.

PrivacyVault is designed for MissionBay and other BASE3-based LLM agent systems, but it is not hard-coupled to MissionBay. The public integration point is `IPrivacyAliasService`.

## Status

Current version: `0.2.0`

This is the first useful MVP version.

It includes:

* BASE3 plugin structure
* DI registration through `PrivacyVaultPlugin`
* `IPrivacyAliasService` as public service interface
* Random alias token generation
* Database-backed alias storage
* Automatic table creation through `ensureStorage()` / `ensureTable()`
* User/session-bound context through `IPrivacyContextProvider`
* Current context integration through:

  * `IAccesscontrol::getUserId()`
  * `ISession::getId()`
  * fallback via `IUsermanager::getUser()`
* Direct value aliases
* Reference aliases
* Server-side alias resolution
* Server-side alias replacement in final output text
* Extension interfaces for token generation, reference resolution, cryptography and policies

The MVP intentionally uses `NullPrivacyValueCryptor`, meaning direct protected values are still stored as plain text. This is acceptable for the first integration path, but it should be replaced by a real cryptor before using PrivacyVault for long-lived or sensitive production chat storage.

## Why PrivacyVault exists

LLM agents often call tools that read database records. Those records may contain personal or confidential data:

* names
* email addresses
* phone numbers
* addresses
* birth dates
* user IDs
* usernames
* IP addresses
* organization or company names
* free text containing personal data

Without a privacy layer, these values may enter the LLM prompt context as clear text. That creates several risks:

* The LLM provider sees personal data.
* Prompt logs, traces or debugging systems may contain personal data.
* The model may accidentally reveal, mix or reuse sensitive information.
* Prompt injection may try to expose internal tool data.
* The operator loses control over which personal data reaches which model.

PrivacyVault solves the first and most important technical problem: tools can pseudonymize sensitive values before the LLM sees them.

The LLM sees this:

```json
{
	"missing_users": [
		{
			"name": "[name_8c0b16f50f2a7d21]",
			"email": "[email_0a2c91e0ff184a37]",
			"course": "Data Protection Basics"
		}
	]
}
```

Instead of this:

```json
{
	"missing_users": [
		{
			"name": "Hans Meyer",
			"email": "hans.meyer@example.org",
			"course": "Data Protection Basics"
		}
	]
}
```

The LLM can still produce a useful answer:

```text
The following user has not completed the course: [name_8c0b16f50f2a7d21].
```

Before rendering the final answer, the server-side output pipeline resolves the alias for the current user/session context:

```text
The following user has not completed the course: Hans Meyer.
```

## Important terminology

### PrivacyVault

The plugin and technical protection space. It contains storage, alias services, token generators, context providers, reference resolvers, value cryptors and future policy support.

### PrivacyAlias

A visible placeholder for a sensitive value.

Examples:

```text
[name_8c0b16f50f2a7d21]
[email_0a2c91e0ff184a37]
[user_id_a1b2c3d4e5f60708]
```

### IPrivacyAliasService

The public DI service interface used by tools, output filters and application services.

### Pseudonymization, not anonymization

PrivacyVault performs reversible pseudonymization. It is intentionally able to resolve aliases back to clear values for authorized output rendering.

This is not anonymization.

The alias mapping is additional identifying information and must be protected accordingly.

## Core flow

The intended flow is:

```text
User asks chatbot
-> Agent decides to call a tool
-> Tool reads data from database
-> Tool pseudonymizes sensitive values through IPrivacyAliasService
-> Tool result contains aliases instead of clear values
-> LLM receives only aliases
-> LLM answers using aliases unchanged
-> Output pipeline calls replaceAliasesForCurrentContext()
-> PrivacyVault checks context
-> PrivacyVault resolves known aliases
-> Final answer is rendered for the authorized user
```

The central security rule is:

```text
The resolver must not be exposed as a normal AgentTool.
```

The LLM may receive and reuse aliases, but it must not be able to resolve them itself.

## Repository structure

```text
PrivacyVault/
├── LICENSE
├── MANIFEST.txt
├── README.md
├── VERSION
└── src
	├── Api
	│	├── IPrivacyAliasPolicyProvider.php
	│	├── IPrivacyAliasService.php
	│	├── IPrivacyAliasStorage.php
	│	├── IPrivacyAliasTokenGenerator.php
	│	├── IPrivacyContextProvider.php
	│	├── IPrivacyReferenceResolver.php
	│	└── IPrivacyValueCryptor.php
	├── Context
	│	└── DefaultPrivacyContextProvider.php
	├── Cryptor
	│	└── NullPrivacyValueCryptor.php
	├── Dto
	│	└── PrivacyAliasRecord.php
	├── Reference
	│	└── CompositePrivacyReferenceResolver.php
	├── Service
	│	├── DefaultPrivacyAliasPolicyProvider.php
	│	└── PrivacyAliasService.php
	├── Storage
	│	└── DatabasePrivacyAliasStorage.php
	├── Token
	│	└── RandomPrivacyAliasTokenGenerator.php
	├── Value
	│	├── PrivacyAliasStorageMode.php
	│	├── PrivacyAliasType.php
	│	└── PrivacyValueFormat.php
	└── PrivacyVaultPlugin.php
```

## BASE3 plugin registration

`PrivacyVaultPlugin` registers the services in the BASE3 DI container.

```php
<?php declare(strict_types=1);

namespace PrivacyVault;

use Base3\Accesscontrol\Api\IAccesscontrol;
use Base3\Api\IContainer;
use Base3\Api\IPlugin;
use Base3\Database\Api\IDatabase;
use Base3\Session\Api\ISession;
use Base3\Usermanager\Api\IUsermanager;
use PrivacyVault\Api\IPrivacyAliasPolicyProvider;
use PrivacyVault\Api\IPrivacyAliasService;
use PrivacyVault\Api\IPrivacyAliasStorage;
use PrivacyVault\Api\IPrivacyAliasTokenGenerator;
use PrivacyVault\Api\IPrivacyContextProvider;
use PrivacyVault\Api\IPrivacyReferenceResolver;
use PrivacyVault\Api\IPrivacyValueCryptor;
use PrivacyVault\Context\DefaultPrivacyContextProvider;
use PrivacyVault\Cryptor\NullPrivacyValueCryptor;
use PrivacyVault\Reference\CompositePrivacyReferenceResolver;
use PrivacyVault\Service\DefaultPrivacyAliasPolicyProvider;
use PrivacyVault\Service\PrivacyAliasService;
use PrivacyVault\Storage\DatabasePrivacyAliasStorage;
use PrivacyVault\Token\RandomPrivacyAliasTokenGenerator;

class PrivacyVaultPlugin implements IPlugin {

	public function __construct(private readonly IContainer $container) {}

	public static function getName(): string {
		return 'privacyvaultplugin';
	}

	public function init() {
		$this->container
			->set(self::getName(), $this, IContainer::SHARED)
			->set(IPrivacyContextProvider::class, fn($c) => new DefaultPrivacyContextProvider(
				$this->getOptionalAccesscontrol(),
				$this->getOptionalSession(),
				$this->getOptionalUsermanager()
			), IContainer::SHARED)
			->set(IPrivacyAliasTokenGenerator::class, fn($c) => new RandomPrivacyAliasTokenGenerator(), IContainer::SHARED)
			->set(IPrivacyValueCryptor::class, fn($c) => new NullPrivacyValueCryptor(), IContainer::SHARED)
			->set(IPrivacyReferenceResolver::class, fn($c) => new CompositePrivacyReferenceResolver(), IContainer::SHARED)
			->set(IPrivacyAliasPolicyProvider::class, fn($c) => new DefaultPrivacyAliasPolicyProvider(), IContainer::SHARED)
			->set(IPrivacyAliasStorage::class, fn($c) => new DatabasePrivacyAliasStorage(
				$c->get(IDatabase::class)
			), IContainer::SHARED)
			->set(IPrivacyAliasService::class, fn($c) => new PrivacyAliasService(
				$c->get(IPrivacyAliasStorage::class),
				$c->get(IPrivacyAliasTokenGenerator::class),
				$c->get(IPrivacyValueCryptor::class),
				$c->get(IPrivacyReferenceResolver::class),
				PrivacyAliasService::DEFAULT_TTL_SECONDS,
				$c->get(IPrivacyContextProvider::class)
			), IContainer::SHARED);
	}
}
```

## Main service interface

The main public interface is `IPrivacyAliasService`.

```php
<?php declare(strict_types=1);

namespace PrivacyVault\Api;

use Base3\Api\IBase;

interface IPrivacyAliasService extends IBase {

	public function ensureStorage(): void;

	public function getCurrentContext(array $context = []): array;

	public function createAlias(string $value, string $type, array $context = []): string;

	public function createAliasForCurrentContext(string $value, string $type, array $context = []): string;

	public function createReferenceAlias(
		string $referenceType,
		string $referenceId,
		string $valueKey,
		string $type,
		array $context = [],
		?string $label = null
	): string;

	public function createReferenceAliasForCurrentContext(
		string $referenceType,
		string $referenceId,
		string $valueKey,
		string $type,
		array $context = [],
		?string $label = null
	): string;

	public function resolveAlias(string $aliasToken, array $context = []): ?string;

	public function resolveAliasForCurrentContext(string $aliasToken, array $context = []): ?string;

	public function replaceAliases(string $text, array $context = []): string;

	public function replaceAliasesForCurrentContext(string $text, array $context = []): string;
}
```

## Getting the service

A typical BASE3 integration retrieves the service from the container.

```php
<?php declare(strict_types=1);

use PrivacyVault\Api\IPrivacyAliasService;

/** @var IPrivacyAliasService $privacyAliasService */
$privacyAliasService = $container->get(IPrivacyAliasService::class);
```

## Ensuring the database table

PrivacyVault does not require migrations for the MVP.

Before the storage is used, the service calls:

```php
$privacyAliasService->ensureStorage();
```

Internally, this delegates to `DatabasePrivacyAliasStorage::ensureTable()`.

The table is created with `CREATE TABLE IF NOT EXISTS`.

```sql
CREATE TABLE IF NOT EXISTS base3_privacy_alias (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## Context model

Aliases are bound to a context.

The MVP context normally contains:

```json
{
	"user_id": "4711",
	"session_id": "s8f2c0..."
}
```

User IDs are always normalized to strings. The host system decides whether user IDs are numeric or textual.

The context is stored as JSON in `context_json`.

A technical `context_key` may also be stored. It is a hash over the canonicalized context and can be used for indexing and later optimization.

The context model is intentionally open. Later versions can add:

```json
{
	"user_id": "4711",
	"session_id": "s8f2c0...",
	"chatbot_id": "course_assistant",
	"conversation_id": "conv_2026_0001",
	"turn_id": "turn_0007",
	"tool_call_id": "tool_0019"
}
```

## Current context provider

The current context is created by `IPrivacyContextProvider`.

The default implementation is `DefaultPrivacyContextProvider`.

It reads:

1. Current user ID from `IAccesscontrol::getUserId()`
2. Current session ID from `ISession::getId()`
3. User fallback from `IUsermanager::getUser()`

This allows tool code to use PrivacyVault without manually constructing `user_id` and `session_id`.

```php
<?php declare(strict_types=1);

use PrivacyVault\Api\IPrivacyAliasService;

/** @var IPrivacyAliasService $privacyAliasService */
$privacyAliasService = $container->get(IPrivacyAliasService::class);

$context = $privacyAliasService->getCurrentContext();
```

Possible result:

```php
[
	'user_id' => '4711',
	'session_id' => 's8f2c0...',
]
```

Additional context can be merged in by the caller:

```php
$context = $privacyAliasService->getCurrentContext([
	'chatbot_id' => 'course_assistant',
	'conversation_id' => 'conv_2026_0001',
]);
```

Result:

```php
[
	'chatbot_id' => 'course_assistant',
	'conversation_id' => 'conv_2026_0001',
	'user_id' => '4711',
	'session_id' => 's8f2c0...',
]
```

## Creating a direct alias

Use `createAliasForCurrentContext()` for the normal MVP path.

```php
<?php declare(strict_types=1);

use PrivacyVault\Api\IPrivacyAliasService;
use PrivacyVault\Value\PrivacyAliasType;

/** @var IPrivacyAliasService $privacyAliasService */
$privacyAliasService = $container->get(IPrivacyAliasService::class);

$nameAlias = $privacyAliasService->createAliasForCurrentContext(
	'Hans Meyer',
	PrivacyAliasType::NAME
);

$emailAlias = $privacyAliasService->createAliasForCurrentContext(
	'hans.meyer@example.org',
	PrivacyAliasType::EMAIL
);
```

Example result:

```php
$nameAlias = '[name_8c0b16f50f2a7d21]';
$emailAlias = '[email_0a2c91e0ff184a37]';
```

The LLM receives the aliases, not the clear values.

## Creating aliases with explicit context

Explicit context is useful for tests, CLI jobs, queue workers or integrations that already know the user/session/conversation scope.

```php
<?php declare(strict_types=1);

use PrivacyVault\Api\IPrivacyAliasService;
use PrivacyVault\Value\PrivacyAliasType;

/** @var IPrivacyAliasService $privacyAliasService */
$privacyAliasService = $container->get(IPrivacyAliasService::class);

$context = [
	'user_id' => (string) $userId,
	'session_id' => $sessionId,
];

$nameAlias = $privacyAliasService->createAlias(
	'Hans Meyer',
	PrivacyAliasType::NAME,
	$context
);
```

## Protecting a tool result manually

In the MVP, tools can manually pseudonymize fields before returning results to the agent.

```php
<?php declare(strict_types=1);

use PrivacyVault\Api\IPrivacyAliasService;
use PrivacyVault\Value\PrivacyAliasType;

class CourseCompletionTool {

	public function __construct(
		private readonly IPrivacyAliasService $privacyAliasService
	) {}

	public function getMissingUsers(int|string $courseId): array {
		$rows = $this->loadMissingUsers($courseId);
		$result = [];

		foreach ($rows as $row) {
			$result[] = [
				'user_id' => $this->privacyAliasService->createAliasForCurrentContext(
					(string) $row['user_id'],
					PrivacyAliasType::USER_ID
				),
				'name' => $this->privacyAliasService->createAliasForCurrentContext(
					$row['firstname'] . ' ' . $row['lastname'],
					PrivacyAliasType::NAME
				),
				'email' => $this->privacyAliasService->createAliasForCurrentContext(
					$row['email'],
					PrivacyAliasType::EMAIL
				),
				'course_title' => $row['course_title'],
				'completion_status' => $row['completion_status'],
			];
		}

		return [
			'missing_users' => $result,
		];
	}

	private function loadMissingUsers(int|string $courseId): array {
		// Load rows from the domain database.
		return [];
	}
}
```

The tool result sent to the LLM might look like this:

```json
{
	"missing_users": [
		{
			"user_id": "[user_id_6820baf3512d6d48]",
			"name": "[name_8c0b16f50f2a7d21]",
			"email": "[email_0a2c91e0ff184a37]",
			"course_title": "Data Protection Basics",
			"completion_status": "missing"
		}
	]
}
```

## Replacing aliases in final output

After the LLM response is available, call `replaceAliasesForCurrentContext()`.

```php
<?php declare(strict_types=1);

$llmAnswer = 'The following user has not completed the course: [name_8c0b16f50f2a7d21].';

$finalAnswer = $privacyAliasService->replaceAliasesForCurrentContext($llmAnswer);
```

If the alias exists and the context matches, the final answer becomes:

```text
The following user has not completed the course: Hans Meyer.
```

Unknown aliases remain unchanged.

This is important because the LLM may hallucinate a token or reuse a token from another context.

## Resolving a single alias

Single-token resolution is available through:

```php
$value = $privacyAliasService->resolveAliasForCurrentContext('[name_8c0b16f50f2a7d21]');
```

However, this method is intended for internal output rendering only.

Do not expose it as a normal LLM tool.

## Context check behavior

PrivacyVault resolves an alias only when the stored context matches the provided context.

For example, an alias created with this context:

```php
[
	'user_id' => '4711',
	'session_id' => 'session-a',
]
```

will not resolve with this context:

```php
[
	'user_id' => '4711',
	'session_id' => 'session-b',
]
```

And it will not resolve with this context:

```php
[
	'user_id' => '9999',
	'session_id' => 'session-a',
]
```

This prevents cross-user or cross-session alias resolution in the MVP.

## Alias token format

The default token generator creates semantic random tokens.

Examples:

```text
[name_8c0b16f50f2a7d21]
[email_0a2c91e0ff184a37]
[phone_bbd56b6145eb29a1]
[user_id_6820baf3512d6d48]
```

The prefix helps the LLM use the token grammatically.

The random suffix prevents direct reverse calculation from the clear value.

The MVP deliberately does not derive the suffix from the protected value.

## Supported alias types

`PrivacyAliasType` defines common semantic types.

```php
<?php declare(strict_types=1);

namespace PrivacyVault\Value;

final class PrivacyAliasType {

	public const NAME = 'NAME';
	public const EMAIL = 'EMAIL';
	public const PHONE = 'PHONE';
	public const ADDRESS = 'ADDRESS';
	public const STREET = 'STREET';
	public const CITY = 'CITY';
	public const BIRTHDATE = 'BIRTHDATE';
	public const AGE = 'AGE';
	public const USER_ID = 'USER_ID';
	public const COMPANY = 'COMPANY';
	public const IP_ADDRESS = 'IP_ADDRESS';
	public const USERNAME = 'USERNAME';
	public const FREE_TEXT = 'FREE_TEXT';
	public const FREE_TEXT_PII = 'FREE_TEXT_PII';
}
```

Recommended default behavior:

| Type            | Typical examples                    | Recommendation                |
| --------------- | ----------------------------------- | ----------------------------- |
| `NAME`          | first name, last name, display name | Always alias                  |
| `EMAIL`         | email address                       | Always alias or mask          |
| `PHONE`         | phone, mobile                       | Always alias or mask          |
| `ADDRESS`       | full address                        | Alias                         |
| `STREET`        | street and house number             | Alias                         |
| `CITY`          | city                                | Configurable                  |
| `BIRTHDATE`     | date of birth                       | Alias or generalize           |
| `AGE`           | exact age                           | Generalize if possible        |
| `USER_ID`       | internal user identifier            | Alias if not required by LLM  |
| `COMPANY`       | organization or employer            | Configurable                  |
| `IP_ADDRESS`    | IPv4 or IPv6 address                | Alias or shorten              |
| `USERNAME`      | login name                          | Alias                         |
| `FREE_TEXT_PII` | free text with personal data        | Later policy or PII detection |

## Storage modes

PrivacyVault already models several storage modes.

### `value`

The alias points to a stored protected value.

This is the current MVP default.

```json
{
	"alias_token": "[name_8c0b16f50f2a7d21]",
	"alias_type": "NAME",
	"storage_mode": "value",
	"value_format": "plain",
	"protected_value": "Hans Meyer"
}
```

Good for:

* quick integration
* temporary sessions
* short-lived chat flows

Risk:

* in the MVP, `protected_value` is still plain text because `NullPrivacyValueCryptor` is used

### `reference`

The alias points to a domain reference.

```json
{
	"alias_token": "[name_8c0b16f50f2a7d21]",
	"alias_type": "NAME",
	"storage_mode": "reference",
	"value_format": "reference_only",
	"reference_json": {
		"type": "ilias_user",
		"id": "4711",
		"value_key": "fullname"
	}
}
```

Good for:

* stored chats
* avoiding duplicated clear values
* resolving current values from the source system

Requires:

* `IPrivacyReferenceResolver`
* a domain resolver or database view

### `hybrid`

The alias stores a reference plus fallback label.

```json
{
	"alias_token": "[name_8c0b16f50f2a7d21]",
	"alias_type": "NAME",
	"storage_mode": "hybrid",
	"reference_json": {
		"type": "ilias_user",
		"id": "4711",
		"value_key": "fullname"
	},
	"protected_value": "Hans Meyer"
}
```

Good for:

* robust stored chats
* old messages where the source record may change or disappear

Risk:

* fallback labels may contain personal data and should be encrypted in production

### `stateless`

A future mode where the token itself contains encrypted or deterministic payload data.

This is not part of the MVP.

It may be useful later for special cases, but it requires careful key management, token length management, replay handling and privacy review.

## Reference aliases

Reference aliases are useful when the clear value should not be stored permanently in the PrivacyVault table.

Example:

```php
<?php declare(strict_types=1);

use PrivacyVault\Api\IPrivacyAliasService;
use PrivacyVault\Value\PrivacyAliasType;

/** @var IPrivacyAliasService $privacyAliasService */
$privacyAliasService = $container->get(IPrivacyAliasService::class);

$nameAlias = $privacyAliasService->createReferenceAliasForCurrentContext(
	'ilias_user',
	(string) $row['usr_id'],
	'fullname',
	PrivacyAliasType::NAME,
	label: $row['firstname'] . ' ' . $row['lastname']
);
```

This stores:

```json
{
	"type": "ilias_user",
	"id": "4711",
	"value_key": "fullname"
}
```

The `value_key` is a logical display value. It does not need to be a real database column.

For example, ILIAS may have `firstname` and `lastname`, but no `fullname` field. A resolver or view can provide `fullname`.

## Example reference view

A future ILIAS integration may provide a database view like this:

```sql
CREATE VIEW base3_privacy_ref_ilias_user AS
SELECT
	usr_id AS reference_id,
	CONCAT(firstname, ' ', lastname) AS fullname,
	email AS email,
	login AS username
FROM usr_data
```

A generic resolver could then resolve:

```text
ilias_user:4711:fullname
```

to:

```text
Hans Meyer
```

## Implementing a custom reference resolver

A resolver implements `IPrivacyReferenceResolver`.

```php
<?php declare(strict_types=1);

namespace Acme\Privacy;

use Base3\Database\Api\IDatabase;
use PrivacyVault\Api\IPrivacyReferenceResolver;

class IliasUserPrivacyReferenceResolver implements IPrivacyReferenceResolver {

	public function __construct(private readonly IDatabase $database) {}

	public static function getName(): string {
		return 'iliasuserprivacyreferenceresolver';
	}

	public function supports(string $referenceType, string $valueKey): bool {
		return $referenceType === 'ilias_user'
			&& in_array($valueKey, ['fullname', 'email', 'username'], true);
	}

	public function resolve(
		string $referenceType,
		string $referenceId,
		string $valueKey,
		array $context = []
	): ?string {
		if (!$this->supports($referenceType, $valueKey)) {
			return null;
		}

		$this->database->connect();

		$row = $this->database->singleQuery(
			"SELECT " . $this->database->escape($valueKey) . " AS value FROM base3_privacy_ref_ilias_user WHERE reference_id = '" . $this->database->escape($referenceId) . "' LIMIT 1"
		);

		if ($row === null || !array_key_exists('value', $row)) {
			return null;
		}

		return (string) $row['value'];
	}
}
```

A production resolver should not blindly interpolate column names. Prefer a whitelist map:

```php
$columns = [
	'fullname' => 'fullname',
	'email' => 'email',
	'username' => 'username',
];

$column = $columns[$valueKey] ?? null;

if ($column === null) {
	return null;
}
```

## Value cryptor

`IPrivacyValueCryptor` protects values before they are stored.

```php
<?php declare(strict_types=1);

namespace PrivacyVault\Api;

use Base3\Api\IBase;

interface IPrivacyValueCryptor extends IBase {

	public function encrypt(string $plainValue, array $context = []): string;

	public function decrypt(string $protectedValue, array $context = []): ?string;

	public function getFormat(): string;
}
```

The MVP uses `NullPrivacyValueCryptor`.

```php
<?php declare(strict_types=1);

namespace PrivacyVault\Cryptor;

use PrivacyVault\Api\IPrivacyValueCryptor;
use PrivacyVault\Value\PrivacyValueFormat;

class NullPrivacyValueCryptor implements IPrivacyValueCryptor {

	public static function getName(): string {
		return 'nullprivacyvaluecryptor';
	}

	public function encrypt(string $plainValue, array $context = []): string {
		return $plainValue;
	}

	public function decrypt(string $protectedValue, array $context = []): ?string {
		return $protectedValue;
	}

	public function getFormat(): string {
		return PrivacyValueFormat::PLAIN;
	}
}
```

This keeps the architecture simple for the MVP, but it does not provide encryption.

## Planned cryptor implementations

Future implementations may include:

### `ServerKeyPrivacyValueCryptor`

Encrypts `protected_value` with a server-side key.

Good for:

* production MVP
* server-side output resolution
* simple operational model

Requires:

* secure key storage
* key rotation concept
* backup and recovery concept

### `UserScopedPrivacyValueCryptor`

Encrypts values with a user- or tenant-scoped key.

Good for:

* stronger isolation
* multi-tenant systems
* reduced blast radius

Requires:

* user key management
* tenant key management
* recovery strategy

### `ConversationKeyPrivacyValueCryptor`

Encrypts values per conversation.

Good for:

* stored chats
* conversation-level retention
* future sharing rules

Requires:

* conversation key lifecycle
* key rotation
* access control for old conversations

### Client-side or E2E cryptor

If clear values should not be decryptable server-side, alias resolution may need to move to the client.

This requires a different architecture:

* server stores only encrypted values
* client holds or derives decrypt keys
* output stream may show aliases first
* client resolves visible aliases asynchronously

This is not part of the MVP.

## Token generator

`IPrivacyAliasTokenGenerator` defines how visible tokens are created.

```php
<?php declare(strict_types=1);

namespace PrivacyVault\Api;

use Base3\Api\IBase;

interface IPrivacyAliasTokenGenerator extends IBase {

	public function createToken(string $type, array $payload, array $context = []): string;

	public function isDeterministic(): bool;
}
```

The MVP uses `RandomPrivacyAliasTokenGenerator`.

Characteristics:

* random suffix
* semantic type prefix
* server-side mapping required
* low linkability across sessions
* good for temporary aliases

Example:

```text
[name_8c0b16f50f2a7d21]
```

## Planned token generator strategies

### Random token generator

Current MVP.

```text
[name_8c0b16f50f2a7d21]
```

Good for:

* short-lived sessions
* low linkability
* simple implementation

Limitations:

* requires stored mapping
* persistent chats need persistent mappings

### HMAC token generator

Future deterministic strategy.

Possible input:

```text
NAME|ilias_user|4711|fullname|conversation_abc
```

Possible output:

```text
[name_d9c7f2a1b8e3]
```

Good for:

* stable tokens inside a defined scope
* avoiding duplicate aliases
* reproducible aliases

Risks:

* linkability if scope is too broad
* key management
* privacy review required

Do not calculate deterministic tokens from clear values alone.

Avoid:

```text
HMAC(secret, "Hans Meyer")
```

Prefer:

```text
HMAC(secret, "NAME|ilias_user|4711|fullname|conversation_abc")
```

### Reference-based token generator

A deterministic token based on reference type, reference ID, value key and scope.

Good for:

* stored chats
* stable domain references
* less duplication

Risks:

* references are still personal data
* resolver access must be strict
* scope must be chosen carefully

### Encrypted payload token generator

A future stateless strategy where the token contains encrypted payload.

Good for:

* avoiding DB mappings in special cases
* stateless systems

Risks:

* long tokens
* key rotation complexity
* replay handling
* payload leakage if encryption is misused

## Policy provider

Policies are intended for future automatic pseudonymization of structured tool results.

The current MVP contains `IPrivacyAliasPolicyProvider`, but automatic policy application is not yet implemented.

A future policy may look like this:

```json
{
	"tool_key": "course_completion_tool",
	"rules": [
		{
			"field_path": "users.*.name",
			"privacy_type": "NAME",
			"mode": "alias",
			"storage_mode": "reference",
			"reference_type": "ilias_user",
			"reference_id_path": "users.*.user_id",
			"value_key": "fullname"
		},
		{
			"field_path": "users.*.email",
			"privacy_type": "EMAIL",
			"mode": "alias"
		},
		{
			"field_path": "users.*.course_title",
			"privacy_type": "NONE",
			"mode": "keep"
		}
	]
}
```

Planned policy modes:

| Mode         | Example                                        | Description                             |
| ------------ | ---------------------------------------------- | --------------------------------------- |
| `alias`      | `Hans Meyer` -> `[name_x]`                     | Default for personal values             |
| `mask`       | `hans.meyer@example.org` -> `h***@example.org` | Keeps partial information               |
| `drop`       | remove field                                   | Strong protection with information loss |
| `keep`       | keep `course_title`                            | For non-sensitive required data         |
| `generalize` | `1981-04-12` -> `1980s`                        | Useful for statistics                   |

## Future protectToolResult API

A later version may add a convenience method like:

```php
$protectedResult = $privacyAliasService->protectToolResult(
	'course_completion_tool',
	$toolResult,
	$privacyAliasService->getCurrentContext()
);
```

This would allow tools to declare or reference privacy policies instead of manually aliasing each field.

Possible future interface:

```php
public function protectToolResult(string $toolKey, array $result, array $context = []): array;
```

This is intentionally not part of the MVP to avoid premature complexity.

## Prompt instruction for LLMs

PrivacyVault provides a prompt instruction through `IPrivacyAliasPolicyProvider`.

Current default text:

```text
You may receive privacy aliases such as [name_x], [email_x], [birthdate_x], [address_x] or [company_x]. Treat them as real values of the indicated type. Keep aliases unchanged in your answer. Never try to resolve, modify, invent or decode privacy aliases.
```

This instruction helps the LLM use aliases correctly.

It is not a security boundary.

The actual security boundary is:

* clear values are not sent to the LLM
* resolver is not exposed as an AgentTool
* alias resolution checks context server-side

## Output resolution and streaming

### Server-side resolution

The MVP assumes server-side resolution.

The final text is passed through:

```php
$finalText = $privacyAliasService->replaceAliasesForCurrentContext($llmText);
```

Advantages:

* client does not need a resolve endpoint
* access control stays server-side
* simple integration

Challenges:

* streaming output must handle token boundaries
* partial tokens may arrive across chunks

### Streaming-safe replacement

For token streaming, the output filter should buffer enough characters to detect full tokens.

Alias pattern:

```regex
\[[A-Za-z][A-Za-z0-9_]*_[A-Za-z0-9]{6,128}\]
```

A simple streaming strategy:

1. Append each LLM chunk to a buffer.
2. Replace complete aliases in the safe part of the buffer.
3. Keep the last N characters in the buffer in case they are part of an incomplete token.
4. Flush the remaining buffer at stream end.

Pseudo-code:

```php
<?php declare(strict_types=1);

class PrivacyStreamingOutputFilter {

	private string $buffer = '';

	public function __construct(
		private readonly IPrivacyAliasService $privacyAliasService
	) {}

	public function push(string $chunk): string {
		$this->buffer .= $chunk;

		if (strlen($this->buffer) < 160) {
			return '';
		}

		$safeLength = strlen($this->buffer) - 160;
		$safePart = substr($this->buffer, 0, $safeLength);
		$this->buffer = substr($this->buffer, $safeLength);

		return $this->privacyAliasService->replaceAliasesForCurrentContext($safePart);
	}

	public function finish(): string {
		$output = $this->privacyAliasService->replaceAliasesForCurrentContext($this->buffer);
		$this->buffer = '';

		return $output;
	}
}
```

This is only a simple example. A production streaming filter should be tested carefully with chunk boundaries, markdown, HTML escaping and multi-byte content.

### Client-side resolution

A later version may support client-side resolution through AJAX.

Flow:

```text
LLM stream contains aliases
-> Client renders placeholder tokens
-> JavaScript detects aliases
-> Client calls secured resolve endpoint
-> Endpoint checks auth, session and context
-> Client replaces tokens in DOM
```

Rules for a future AJAX resolver:

* resolve only explicitly provided tokens
* never list all aliases
* require authentication
* require CSRF protection
* check session and context
* rate-limit requests
* audit resolve access
* never expose resolver to the LLM

## Logging and debugging

PrivacyVault only helps if logs do not reintroduce clear values.

Recommended production behavior:

| Log mode               | Description                                | Recommendation                   |
| ---------------------- | ------------------------------------------ | -------------------------------- |
| `alias_only`           | Store aliases only                         | Default                          |
| `masked`               | Store partially masked values              | Special debug cases              |
| `cleartext_admin_only` | Show clear values only to privileged users | Requires audit                   |
| `no_payload`           | Do not store tool payloads                 | Strong privacy, weaker debugging |

Tool logs should usually store the alias-protected result, not the original database rows.

Example:

```php
$protectedResult = [
	'missing_users' => [
		[
			'name' => '[name_8c0b16f50f2a7d21]',
			'email' => '[email_0a2c91e0ff184a37]',
			'course_title' => 'Data Protection Basics',
		],
	],
];

$logger->info('Tool result', [
	'tool' => 'course_completion_tool',
	'payload' => $protectedResult,
]);
```

Avoid:

```php
$logger->debug('Raw user rows', [
	'rows' => $rows,
]);
```

## Security boundaries

PrivacyVault relies on several boundaries.

### Do not send clear values to the LLM

Tools must alias sensitive values before returning tool results to the agent loop.

### Do not expose resolver as AgentTool

The LLM must not be able to call:

```php
resolveAlias()
resolveAliasForCurrentContext()
replaceAliases()
replaceAliasesForCurrentContext()
```

as a normal tool.

These methods belong to trusted server-side output code.

### Check context before resolution

The service checks stored context against the provided context.

This prevents cross-user and cross-session resolution.

### Treat the alias table as sensitive

Even if aliases are shown to the LLM, the mapping table is sensitive.

The table may contain:

* clear values in MVP mode
* encrypted values in later versions
* domain references
* user/session context
* metadata

Access to this table should be restricted.

### Use TTL and cleanup

Temporary aliases should expire.

The MVP uses a default TTL of 24 hours.

A cleanup job can call:

```php
$storage->deleteExpired();
```

Future versions may expose this through a service method or maintenance task.

## Example integration in a MissionBay-style tool

```php
<?php declare(strict_types=1);

namespace Acme\CourseAgent\Tool;

use PrivacyVault\Api\IPrivacyAliasService;
use PrivacyVault\Value\PrivacyAliasType;

class MissingCourseUsersTool {

	public function __construct(
		private readonly IPrivacyAliasService $privacyAliasService
	) {}

	public static function getName(): string {
		return 'missingcourseuserstool';
	}

	public function run(array $input): array {
		$courseId = $input['course_id'] ?? null;

		if ($courseId === null) {
			return [
				'error' => 'Missing course_id.',
			];
		}

		$rows = $this->loadRows((string) $courseId);
		$users = [];

		foreach ($rows as $row) {
			$users[] = [
				'user_ref' => $this->privacyAliasService->createReferenceAliasForCurrentContext(
					'ilias_user',
					(string) $row['usr_id'],
					'fullname',
					PrivacyAliasType::NAME,
					label: $row['firstname'] . ' ' . $row['lastname']
				),
				'email' => $this->privacyAliasService->createAliasForCurrentContext(
					(string) $row['email'],
					PrivacyAliasType::EMAIL
				),
				'course_title' => (string) $row['course_title'],
				'status' => 'missing',
			];
		}

		return [
			'course_id' => (string) $courseId,
			'missing_users' => $users,
		];
	}

	private function loadRows(string $courseId): array {
		// Load data from the host system.
		return [];
	}
}
```

The agent receives:

```json
{
	"course_id": "123",
	"missing_users": [
		{
			"user_ref": "[name_58c874dc9308aa10]",
			"email": "[email_53115a5121be9d67]",
			"course_title": "Data Protection Basics",
			"status": "missing"
		}
	]
}
```

The LLM may answer:

```text
The user [name_58c874dc9308aa10] has not completed Data Protection Basics.
```

The output pipeline resolves:

```php
$final = $privacyAliasService->replaceAliasesForCurrentContext($llmAnswer);
```

Final output:

```text
The user Hans Meyer has not completed Data Protection Basics.
```

## Example output filter

A simple server-side output filter can be implemented like this:

```php
<?php declare(strict_types=1);

namespace Acme\Privacy;

use PrivacyVault\Api\IPrivacyAliasService;

class PrivacyOutputFilter {

	public function __construct(
		private readonly IPrivacyAliasService $privacyAliasService
	) {}

	public function filterFinalAnswer(string $answer): string {
		return $this->privacyAliasService->replaceAliasesForCurrentContext($answer);
	}
}
```

In a chat controller:

```php
$llmAnswer = $agent->run($message);
$finalAnswer = $privacyOutputFilter->filterFinalAnswer($llmAnswer);

return $finalAnswer;
```

## Example manual test

```php
<?php declare(strict_types=1);

use PrivacyVault\Api\IPrivacyAliasService;
use PrivacyVault\Value\PrivacyAliasType;

/** @var IPrivacyAliasService $privacyAliasService */
$privacyAliasService = $container->get(IPrivacyAliasService::class);

$context = [
	'user_id' => 'user-abc',
	'session_id' => 'session-123',
];

$alias = $privacyAliasService->createAlias(
	'Hans Meyer',
	PrivacyAliasType::NAME,
	$context
);

$text = 'Hello ' . $alias . '.';

echo $privacyAliasService->replaceAliases($text, $context);
```

Expected output:

```text
Hello Hans Meyer.
```

Wrong context:

```php
$wrongContext = [
	'user_id' => 'user-xyz',
	'session_id' => 'session-123',
];

echo $privacyAliasService->replaceAliases($text, $wrongContext);
```

Expected output:

```text
Hello [name_...].
```

## Testing checklist

Recommended test areas:

### Token format

* token contains semantic prefix
* token matches expected regex
* token suffix is random
* no clear value appears in token

### Context checks

* alias resolves with same user/session
* alias does not resolve with different user
* alias does not resolve with different session
* alias remains unchanged when context is missing

### Unknown tokens

* unknown token remains unchanged
* hallucinated token remains unchanged
* malformed token remains unchanged

### Tool integration

* protected tool result contains no clear names
* protected tool result contains no clear emails
* non-sensitive fields remain usable
* course titles or status values are not accidentally aliased if they are needed

### Output replacement

* text with one token resolves correctly
* text with multiple tokens resolves correctly
* repeated token resolves consistently
* mixed known and unknown tokens behave correctly

### Storage

* table is created automatically
* records are inserted
* unique token collision is handled
* expired aliases can be deleted

### Reference aliases

* resolver supports known reference type
* resolver rejects unknown reference type
* `fullname` can be resolved even when it is not a real source field
* fallback label works in hybrid mode

### Cryptor

* null cryptor roundtrip works
* future encrypted cryptor roundtrip works
* wrong key or wrong context does not decrypt sensitive values

## Roadmap

### Phase 1: MVP

Implemented or prepared:

* `PrivacyVault` plugin
* `IPrivacyAliasService`
* `PrivacyAliasService`
* `IPrivacyAliasTokenGenerator`
* `RandomPrivacyAliasTokenGenerator`
* database table with `context_json`
* automatic storage ensure function
* direct value aliases
* current user/session context
* server-side output replacement
* resolver not exposed as AgentTool

### Phase 2: Policies

Planned:

* configurable tool policies
* `protectToolResult()`
* field-path matching such as `users.*.email`
* policy modes:

  * `alias`
  * `mask`
  * `drop`
  * `keep`
  * `generalize`
* optional admin UI for policies
* default policies for common tool result shapes

### Phase 3: Persistent chats

Planned:

* production-ready reference aliases
* ILIAS user resolver or view resolver
* persistent alias mappings
* encryption for labels and protected values
* conversation-level context
* retention and deletion concept
* audit logging for alias resolution

### Phase 4: Advanced privacy features

Possible later additions:

* automatic PII detection for free text
* address granularization
* birthdate generalization
* age grouping
* E2E encryption review
* client-side alias resolution
* tenant-aware cryptor
* role-aware resolver
* privacy reports and risk indicators

## Known limitations

The current MVP has these limitations:

* `NullPrivacyValueCryptor` stores direct values as plain text.
* There is no automatic policy-based result transformation yet.
* There is no built-in admin UI.
* There is no built-in audit log yet.
* There is no production cryptor yet.
* There is no generic database-view resolver yet.
* Streaming-safe output filtering must be implemented by the integrating output pipeline.
* Resolver methods must be kept out of the agent tool registry by convention and architecture.

## Recommended production hardening

Before production use with sensitive or persistent data, add:

* real `IPrivacyValueCryptor`
* cleanup job for expired aliases
* strict DB permissions for `base3_privacy_alias`
* audit log for alias resolution
* policy-based tool result protection
* clear logging policy
* tests for cross-user and cross-session resolution
* retention/deletion rules
* review of whether server-side or client-side resolution is appropriate
* privacy and legal review for the concrete deployment

## License

PrivacyVault is licensed under GPL-3.0.

## Design summary

PrivacyVault intentionally starts small:

* random aliases
* server-side mapping
* context-bound resolution
* manual tool integration
* output replacement after LLM response

At the same time, it already defines the extension points needed for later production scenarios:

* token generator strategies
* reference resolvers
* value cryptors
* policy providers
* context providers
* persistent chat support

This keeps the first version usable without locking the architecture into the MVP implementation.

