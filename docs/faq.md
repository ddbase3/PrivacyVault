# PrivacyVault FAQ

PrivacyVault is a BASE3 plugin for reversible pseudonymization. It replaces sensitive values with semantic alias tokens before those values enter an LLM or another less trusted processing boundary, and it can resolve the aliases again later in trusted server-side output code.

This FAQ describes the implementation contained in this repository. It distinguishes current behavior from interfaces or ideas that exist only as extension points or roadmap items.

## What problem does PrivacyVault solve?

PrivacyVault helps applications keep selected clear-text values out of LLM tool results and similar downstream payloads.

A consuming service can replace a value such as a name or email address with an alias such as:

```text
[name_8c0b16f50f2a7d21]
[email_0a2c91e0ff184a37]
```

The downstream model or service can work with the alias as a semantic placeholder. Trusted server-side code can later resolve the alias for the permitted context.

## Is PrivacyVault an anonymization system?

No. PrivacyVault performs reversible pseudonymization.

The alias can be resolved back to the underlying value when the required server-side context is available. The alias mapping and the alias token therefore remain sensitive data and must not be treated as anonymous information.

## Is PrivacyVault tied to a specific AI runtime?

No. The public integration contract is `IPrivacyAliasService`. A tool, service, output pipeline, or other trusted component can use that interface without depending on a particular agent runtime.

PrivacyVault itself does not call an LLM provider and does not implement an agent loop.

## What is the main public API?

The primary service interface is:

```text
PrivacyVault\Api\IPrivacyAliasService
```

It supports:

- ensuring the storage backend exists
- obtaining the current privacy context
- creating direct value aliases
- creating reference aliases
- resolving one alias
- replacing resolvable aliases in text
- current-context convenience methods for creation and resolution

## Which services does the plugin register?

`PrivacyVaultPlugin` registers these shared services:

| Interface | Default implementation |
| --- | --- |
| `IPrivacyContextProvider` | `DefaultPrivacyContextProvider` |
| `IPrivacyAliasTokenGenerator` | `RandomPrivacyAliasTokenGenerator` |
| `IPrivacyValueCryptor` | `NullPrivacyValueCryptor` |
| `IPrivacyReferenceResolver` | `CompositePrivacyReferenceResolver` |
| `IPrivacyAliasPolicyProvider` | `DefaultPrivacyAliasPolicyProvider` |
| `IPrivacyAliasStorage` | `DatabasePrivacyAliasStorage` |
| `IPrivacyAliasService` | `PrivacyAliasService` |

The plugin also registers its own plugin instance under `privacyvaultplugin`.

## Does PrivacyVault require a database?

The default plugin composition does. `DatabasePrivacyAliasStorage` depends on BASE3 `IDatabase` and stores alias records in `base3_privacy_alias`.

The storage contract is replaceable, so another implementation can be supplied in a different composition.

## How is the database table created?

The current database storage uses a private technical `ensureTable()` mechanism. `IPrivacyAliasService::ensureStorage()` delegates to the storage implementation, which creates `base3_privacy_alias` with `CREATE TABLE IF NOT EXISTS`.

There is no versioned migration provider in this plugin.

## What does `base3_privacy_alias` contain?

The current table contains:

- numeric record ID
- visible alias token
- semantic alias type
- storage mode
- value format
- optional label
- optional protected value
- optional reference JSON
- context JSON
- context hash key
- metadata JSON
- creation timestamp
- expiry timestamp

The table is security-sensitive because these fields can contain clear values, domain identifiers, user identifiers, session identifiers, and data that permits re-identification.

## Are protected values encrypted by default?

No.

The default `IPrivacyValueCryptor` is `NullPrivacyValueCryptor`. Its `encrypt()` method returns the input unchanged, and its format is `plain`.

This means direct aliases currently store the original value in clear text in `protected_value`. Hybrid aliases with fallback values also store the fallback value in clear text.

The cryptor interface exists so a deployment can provide a real protection mechanism, but no production cryptor is included in this package.

## What is the default alias lifetime?

`PrivacyAliasService::DEFAULT_TTL_SECONDS` is `86400`, which is 24 hours.

The plugin wiring uses that default. Each newly created stored alias therefore receives an expiry timestamp 24 hours after creation.

## Are expired aliases automatically deleted?

No.

Resolution rejects expired records, but the records remain in the database until `IPrivacyAliasStorage::deleteExpired()` is called.

The plugin does not ship a background job or worker that calls `deleteExpired()`. A deployment that relies on expiry for retention must schedule cleanup outside this plugin or add an appropriate maintenance component.

## Does an expired alias still resolve?

No. `PrivacyAliasService` checks the record's `expires` value before returning a value. An expired record resolves to `null`, and text replacement leaves the token unchanged.

Expiry and physical deletion are separate operations.

## What is the privacy context?

A privacy context is an associative array stored with an alias and required again during resolution.

The default context provider attempts to add:

```text
user_id
session_id
```

A caller can also add additional fields such as a conversation identifier or tenant identifier.

## Where does the default `user_id` come from?

`DefaultPrivacyContextProvider` tries the available services in this order:

1. `IAccesscontrol::getUserId()`
2. `IUsermanager::getUser()` as a fallback

If no usable user identifier can be obtained, no `user_id` is added.

## Where does the default `session_id` come from?

If `ISession` is available, the context provider ensures the session is started and reads `ISession::getId()`.

The resulting value is stored in `context_json` when a current-context alias is created.

## Can obtaining the current context start a session?

Yes. If a session service is present but not yet started, `DefaultPrivacyContextProvider` calls `ISession::start()` before reading the session ID.

Any cookie or transport behavior caused by starting the session belongs to the configured session implementation, not to PrivacyVault itself.

## Can callers add their own context fields?

Yes. `getCurrentContext()` merges caller context with the current user and session values.

The default provider writes the current `user_id` and `session_id` after receiving the caller context. Therefore, when those values are available from BASE3 services, they replace caller-supplied values under the same keys.

## How does context matching work during resolution?

Every key stored in the alias context must also exist in the provided resolution context and must have the same value. Nested arrays are checked recursively.

The resolution context may contain additional keys that were not present when the alias was created.

## What happens if an alias is created with an empty context?

An empty stored context matches any provided context.

This is an important integration boundary. In multi-user or multi-session applications, use the current-context creation methods or supply an explicit restrictive context. Creating sensitive aliases with an empty context removes the user/session binding provided by the default flow.

## What is `context_key`?

PrivacyVault sorts the context recursively, JSON-encodes it, hashes it with SHA-256, and stores the result as:

```text
sha256:<hash>
```

The database indexes this value. The current resolver does not use `context_key` to authorize or select an alias. Authorization uses the stored `context_json` and the explicit field-by-field comparison.

`context_key` should not be considered an encryption or secrecy mechanism.

## What does an alias token look like?

The default generator creates a semantic prefix plus 8 random bytes represented as 16 hexadecimal characters.

Example:

```text
[name_8c0b16f50f2a7d21]
```

The token does not contain the clear value.

## Are tokens deterministic?

No. `RandomPrivacyAliasTokenGenerator::isDeterministic()` returns `false`, and each token uses `random_bytes(8)`.

Creating two aliases for the same value normally produces two different tokens.

## How are token collisions handled?

`PrivacyAliasService` attempts creation up to five times. If storage reports a duplicate-key error identified by MySQL error code `1062`, a new token is generated and insertion is retried.

Other storage errors are propagated.

## Which semantic alias types are defined?

`PrivacyAliasType` defines common names including:

- `NAME`
- `EMAIL`
- `PHONE`
- `ADDRESS`
- `STREET`
- `CITY`
- `BIRTHDATE`
- `AGE`
- `USER_ID`
- `COMPANY`
- `IP_ADDRESS`
- `USERNAME`
- `FREE_TEXT`
- `FREE_TEXT_PII`

These are convenience constants, not a closed allowlist. Arbitrary type strings are normalized to uppercase letters, digits, and underscores before use.

## What storage modes exist?

`PrivacyAliasStorageMode` defines:

- `value`
- `reference`
- `hybrid`
- `stateless`

The current service actively creates `value`, `reference`, and `hybrid` records. `stateless` is modeled as a constant but is not implemented by the current service.

## How does `value` mode work?

A direct value alias stores the value returned by the configured cryptor in `protected_value`.

With the default null cryptor, that value is clear text.

During resolution, the stored value is passed back through the cryptor's `decrypt()` method.

## How does `reference` mode work?

A reference alias stores a reference structure containing:

```text
type
id
value_key
```

If no fallback label is supplied, no protected value is stored and the record uses `reference_only` as its value format.

During resolution, PrivacyVault asks the configured reference resolver for the current value.

## How does `hybrid` mode work?

If a reference alias is created with a fallback label, the record uses `hybrid` mode.

Resolution first attempts the reference resolver. If that returns no value, PrivacyVault falls back to the stored protected value.

With the default null cryptor, the fallback label is stored in clear text.

## Does the default reference resolver resolve anything by itself?

No. `CompositePrivacyReferenceResolver` starts with an empty resolver list in the default plugin wiring.

A pure reference alias therefore cannot resolve until a concrete `IPrivacyReferenceResolver` is supplied or added by the application composition. A hybrid alias can still fall back to its stored value.

## Does PrivacyVault automatically discover reference resolvers?

No. The current plugin creates `CompositePrivacyReferenceResolver()` with no child resolvers.

Applications that need domain-reference resolution must wire that capability deliberately.

## Who is responsible for authorization inside a reference resolver?

The concrete reference resolver is responsible for obtaining the referenced value safely.

PrivacyVault passes the current resolution context into the resolver, but the composite resolver itself does not perform domain authorization. If resolving a reference requires an object-level permission check, that check belongs in the concrete resolver or the domain service it calls.

## Does PrivacyVault automatically transform complete tool results?

No.

The current public service works on values, references, and final text. `DefaultPrivacyAliasPolicyProvider::getPolicy()` returns `null` for every tool, and there is no implemented `protectToolResult()` service method in this package.

Consumers must currently decide which fields to pseudonymize and call the alias service explicitly.

## What does the default policy provider do?

It provides a reusable model instruction explaining that alias tokens must be treated as real typed values and preserved unchanged.

It does not provide field policies, masking rules, automatic PII detection, or access control.

## Is the model instruction a security boundary?

No. Model instructions can improve behavior but cannot guarantee data isolation.

The effective security boundary is that clear values are replaced before entering the model-facing payload and alias resolution remains in trusted server-side code.

## Should alias resolution be exposed as an agent tool?

No.

`resolveAlias()`, `resolveAliasForCurrentContext()`, `replaceAliases()`, and `replaceAliasesForCurrentContext()` are intended for trusted application code. Exposing them to the same model that receives the aliases would defeat the separation PrivacyVault is designed to provide.

## How does `replaceAliases()` work?

The service scans text for token-shaped strings matching the PrivacyVault alias pattern. Each candidate is looked up and resolved using the provided context.

Known and permitted aliases are replaced. Unknown, expired, malformed, or context-mismatched aliases remain unchanged.

## Does PrivacyVault provide a streaming output filter?

No. The repository README contains an example strategy, but the plugin does not ship a streaming-filter class.

A streaming integration must buffer partial chunks so a token split across chunks is not emitted or resolved incorrectly.

## Does PrivacyVault provide an AJAX alias resolver?

No. There is no HTTP endpoint, controller, display, or client-side resolver in the current source tree.

Any future endpoint must implement its own authentication, authorization, CSRF protection, rate limiting, and audit requirements at the appropriate application boundary.

## Does PrivacyVault log alias creation or resolution?

No. The current source does not use `ILogger` and does not create an audit trail.

A deployment that needs accountable access to resolved values must add auditing at the trusted application boundary without logging more clear-text data than necessary.

## Does PrivacyVault make network requests?

No. The current plugin contains no HTTP client, provider client, or external network call.

Network transfer can occur in consuming components after PrivacyVault returns an alias-protected payload, or in a custom reference resolver if that resolver uses a remote service.

## Does PrivacyVault use browser storage or cookies?

PrivacyVault has no browser-side code and does not directly create cookies, Local Storage, Session Storage, or IndexedDB entries.

Its default context provider can start and read the configured BASE3 session service, so the host session implementation may use a cookie or another session transport.

## Does PrivacyVault have an administration UI?

No. There are no `IOutput`, `IDisplay`, or admin display classes in this package.

Database administration, retention operations, key management for a future cryptor, and operational monitoring must therefore be handled by the surrounding deployment.

## Does PrivacyVault have a cleanup job?

No. The storage exposes `deleteExpired()`, but no worker or job calls it automatically.

## Does PrivacyVault implement audit logging?

No. The current package contains no audit repository or audit event stream for alias creation or resolution.

## What happens if database access fails?

`DatabasePrivacyAliasStorage` throws a `RuntimeException` when `IDatabase` reports an error. The exception text includes the database error number and database error message.

Applications should avoid exposing raw exceptions to untrusted clients because backend error text can contain operational details.

## Is the current database implementation backend-neutral?

The storage is typed against `IDatabase`, but its SQL uses MySQL/MariaDB-specific constructs such as `AUTO_INCREMENT`, `ENGINE=InnoDB`, MySQL index syntax, and `NOW()`.

A non-MySQL deployment should provide another `IPrivacyAliasStorage` implementation rather than assuming the current SQL is portable.

## What should be protected in backups?

Backups containing `base3_privacy_alias` must be treated as sensitive. With the default cryptor, they can contain the original clear values. They can also contain raw context, session identifiers, reference identifiers, and alias mappings.

Deleting expired live rows does not remove copies from existing backups unless the backup lifecycle handles that separately.

## What are the most important production requirements?

For sensitive production use, the core requirements are:

- replace `NullPrivacyValueCryptor` with an appropriate real cryptor when storing clear values is unacceptable
- keep alias resolution in trusted server-side code
- use context-bound alias creation
- protect database access to `base3_privacy_alias`
- schedule physical deletion of expired records
- define deletion behavior for backups
- make reference resolvers enforce the domain permissions they require
- avoid raw-value logging before alias creation
- audit sensitive resolution where accountability is required
- test cross-user and cross-session isolation

## Is automatic PII detection included?

No. `FREE_TEXT_PII` is a semantic alias type, but the plugin does not inspect arbitrary text and does not automatically detect personal data.

Classification and selection of values to pseudonymize remain the responsibility of the integrating component.

## Does PrivacyVault guarantee that an LLM never receives personal data?

No. PrivacyVault can only protect values that the integrating application actually routes through it before creating model-facing payloads.

Other prompt content, tool arguments, conversation history, metadata, logs, and unprotected fields are outside this plugin's control.

## Does the repository contain a version metadata mismatch?

Yes. In this package, the `VERSION` file contains `4.0.0`, while the existing README status section says `0.2.0`.

This FAQ documents the source code shipped in the package and does not attempt to reconcile that existing metadata difference.

## Where are the privacy-specific details documented?

See [PRIVACY.md](../PRIVACY.md) for the complete data-flow, persistence, retention, security-boundary, and deployment analysis for this component.
