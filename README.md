# PrivacyVault for BASE3 Framework

PrivacyVault is a small BASE3 plugin that provides a reversible alias layer for sensitive values before they enter an LLM context.

Version 0.2.0 implements the first useful MVP path:

- `IPrivacyAliasService` as the public DI-facing service interface
- random alias tokens such as `[name_3f2a9b10c1aa]`
- database-backed mapping in `base3_privacy_alias`
- `ensureStorage()` / `ensureTable()` so no migration step is required for the first version
- strict context checking before aliases are resolved
- automatic current context through `IPrivacyContextProvider`
- current context built from BASE3 `IAccesscontrol`, `ISession` and, as fallback, `IUsermanager`
- server-side replacement through `replaceAliases()` or `replaceAliasesForCurrentContext()`
- extension points for token generators, reference resolvers, value cryptors, context providers and policy providers

## BASE3 DI registration

The plugin registers these services in `PrivacyVaultPlugin::init()`:

- `PrivacyVault\Api\IPrivacyAliasService`
- `PrivacyVault\Api\IPrivacyAliasStorage`
- `PrivacyVault\Api\IPrivacyAliasTokenGenerator`
- `PrivacyVault\Api\IPrivacyContextProvider`
- `PrivacyVault\Api\IPrivacyValueCryptor`
- `PrivacyVault\Api\IPrivacyReferenceResolver`
- `PrivacyVault\Api\IPrivacyAliasPolicyProvider`

## Direct usage with current user/session context

For the first integration path, tools do not need to build `user_id` and `session_id` manually. The service asks `IPrivacyContextProvider` for the current BASE3 context and stores it with the alias.

```php
use PrivacyVault\Api\IPrivacyAliasService;
use PrivacyVault\Value\PrivacyAliasType;

/** @var IPrivacyAliasService $privacyAliasService */
$privacyAliasService = $container->get(IPrivacyAliasService::class);

$nameAlias = $privacyAliasService->createAliasForCurrentContext(
	$row['firstname'] . ' ' . $row['lastname'],
	PrivacyAliasType::NAME
);

$emailAlias = $privacyAliasService->createAliasForCurrentContext(
	$row['email'],
	PrivacyAliasType::EMAIL
);

$toolResultForLlm = [
	'missing_users' => [
		[
			'name' => $nameAlias,
			'email' => $emailAlias,
			'course' => $row['course_title'],
		],
	],
];

$finalAnswer = $privacyAliasService->replaceAliasesForCurrentContext($llmAnswer);
```

The stored `context_json` contains values like this when the host system exposes them:

```json
{
	"user_id": "4711",
	"session_id": "s8f2c0..."
}
```

User IDs are stored as strings. The host system decides whether user IDs are numeric or textual.

## Explicit context is still supported

The original explicit path remains available for tests, jobs or integrations that already have their own context.

```php
$context = [
	'user_id' => (string) $userId,
	'session_id' => $sessionId,
];

$nameAlias = $privacyAliasService->createAlias(
	$row['firstname'] . ' ' . $row['lastname'],
	PrivacyAliasType::NAME,
	$context
);

$answer = $privacyAliasService->replaceAliases($llmAnswer, $context);
```

## Storage

The table is created automatically by `DatabasePrivacyAliasStorage::ensureTable()`, which is called lazily through the service before the storage is used.

The MVP stores direct values through `NullPrivacyValueCryptor`, so `protected_value` is still plain text. This is intentional for the first version and isolated behind `IPrivacyValueCryptor` so a server-key or conversation-key cryptor can replace it later.

## Security boundary

Do not expose `resolveAlias()` or `resolveAliasForCurrentContext()` as an AgentTool. The LLM should only see and reuse aliases. Alias resolution belongs to the server-side output pipeline or a separately secured client-side resolver endpoint.
