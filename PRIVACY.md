# Privacy and Data Processing in PrivacyVault

This document describes the data-processing behavior of the PrivacyVault component as implemented in this repository. It is technical documentation, not a legal privacy notice. Legal basis, controller information, processor agreements, retention obligations, and other deployment-specific legal requirements remain the responsibility of the system in which PrivacyVault is used.

PrivacyVault is specifically designed to reduce the amount of clear-text sensitive data that crosses from trusted application code into LLM or similar processing contexts. Its own alias mapping is nevertheless sensitive and must be protected accordingly.

## 1. Scope of this document

This document covers only PrivacyVault itself:

- alias creation
- alias token generation
- privacy-context creation
- database-backed alias storage
- direct-value aliases
- reference aliases
- hybrid aliases
- alias resolution
- final-text replacement
- the default cryptor
- the default policy provider
- the default reference-resolver composition

It does not describe the privacy behavior of an LLM provider, chatbot, reporting system, database domain, host application, or custom resolver except where PrivacyVault crosses that boundary.

## 2. Core privacy model

PrivacyVault implements reversible pseudonymization.

The intended processing flow is:

```text
trusted application data
-> selected sensitive values are replaced with alias tokens
-> downstream model or service receives aliases
-> downstream result preserves aliases
-> trusted server-side output code resolves permitted aliases
-> clear value is rendered only after context validation
```

This can reduce disclosure of selected values to model providers, model traces, and model-facing tool payloads.

It does not anonymize data. The alias can be reversed through the server-side mapping, and the mapping itself is additional identifying information.

## 3. Data categories PrivacyVault can process

Depending on how the component is used, PrivacyVault can process any string selected by the caller, including:

- names
- email addresses
- telephone numbers
- postal addresses
- streets and cities
- dates of birth
- ages
- user identifiers
- usernames
- IP addresses
- company or organization names
- free text
- free text containing personal data
- custom semantic value types

The service API does not restrict alias creation to the built-in type constants.

## 4. PrivacyVault does not classify data automatically

The current component does not automatically inspect a result and decide which fields contain personal data.

There is no automatic PII detector and no active policy-based result transformation in the current source.

The integrating component must decide which values require pseudonymization and must call `IPrivacyAliasService` before those values cross the relevant boundary.

## 5. Alias tokens are not anonymous data

The default alias token is a random semantic placeholder such as:

```text
[name_8c0b16f50f2a7d21]
```

The token does not contain the original value, but it remains linked to a database record that can be resolved under the correct context.

Alias tokens therefore remain pseudonymous identifiers. They should not be treated as anonymous merely because the clear value is absent from the token.

## 6. Default token generation

`RandomPrivacyAliasTokenGenerator`:

- normalizes the semantic type into a lowercase prefix
- generates 8 cryptographically secure random bytes with `random_bytes(8)`
- renders those bytes as 16 hexadecimal characters
- returns a bracketed token
- is explicitly non-deterministic

The clear value is not used to derive the random token suffix.

This prevents direct reverse calculation of the value from the token itself.

## 7. Token uniqueness

The database enforces a unique index on `alias_token`.

When insertion fails with a duplicate-key condition identified by MySQL error code `1062`, `PrivacyAliasService` generates a new token and retries up to five times.

The collision-retry mechanism is about uniqueness, not confidentiality.

## 8. Database persistence

The default storage is `DatabasePrivacyAliasStorage`.

It creates and uses:

```text
base3_privacy_alias
```

The storage is persistent. Alias data can survive the request and process that created it until it is physically deleted.

## 9. Database schema

The current table contains these fields:

| Field | Purpose | Privacy relevance |
| --- | --- | --- |
| `id` | Internal record identifier | Technical metadata |
| `alias_token` | Visible pseudonymous token | Linkable pseudonymous identifier |
| `alias_type` | Semantic value category | May reveal what kind of data is hidden |
| `storage_mode` | `value`, `reference`, or `hybrid` | Processing metadata |
| `value_format` | `plain`, `encrypted`, `reference_only`, or custom | Reveals protection format |
| `label` | Optional label field | Can contain personal data in custom storage use |
| `protected_value` | Stored direct or fallback value | Clear text with the default cryptor |
| `reference_json` | Domain reference | Can contain identifying source IDs |
| `context_json` | Resolution-binding context | Can contain user and session identifiers |
| `context_key` | SHA-256 hash of normalized context | Pseudonymous technical linkage |
| `metadata_json` | Generator and alias metadata | Technical and potentially application metadata |
| `created` | Creation timestamp | Usage metadata |
| `expires` | Expiry timestamp | Retention metadata |

This table must be treated as sensitive even when the visible aliases are safe to show to an LLM.

## 10. Direct value aliases currently store clear text

The plugin's default `IPrivacyValueCryptor` implementation is `NullPrivacyValueCryptor`.

Its behavior is:

```text
encrypt(value) -> value
decrypt(value) -> value
format -> plain
```

As a result, direct-value aliases currently store the original value unchanged in `protected_value`.

The word `protected_value` describes the architectural slot, not the protection strength of the current default implementation.

## 11. Hybrid fallback values currently store clear text

A hybrid reference alias stores both a domain reference and a fallback value.

The fallback passes through the configured cryptor. With `NullPrivacyValueCryptor`, it is stored unchanged.

Hybrid aliases therefore retain a persistent clear-text copy of the fallback label in the current default composition.

## 12. Reference-only aliases can avoid duplicate clear values

When `createReferenceAlias()` is called without a fallback label, PrivacyVault stores:

- reference type
- reference ID
- logical value key
- context
- metadata

It does not store a direct protected value for that alias.

This can reduce duplication of clear values, but the reference itself may still identify a person or record and must be protected accordingly.

## 13. Reference resolution is not active by default

The default plugin registers an empty `CompositePrivacyReferenceResolver`.

It contains no concrete child resolver and therefore cannot resolve a reference by itself.

A deployment that wants reference aliases to resolve must deliberately supply or attach an appropriate domain resolver.

## 14. Domain authorization belongs at the reference boundary

`CompositePrivacyReferenceResolver` selects a child resolver based on `supports()` and forwards the stored reference plus resolution context.

It does not enforce object-level permissions itself.

A concrete resolver must therefore enforce any domain-specific read permission required to retrieve the referenced value. PrivacyVault's context match does not replace authorization against the underlying source system.

## 15. Default privacy context

`DefaultPrivacyContextProvider` attempts to build a context from the active BASE3 runtime.

It can add:

```text
user_id
session_id
```

Callers can add more context fields.

The context is persisted with each stored alias and is later used to decide whether the alias may be resolved.

## 16. User identifier processing

The default context provider reads a user identifier from:

1. `IAccesscontrol::getUserId()`, if available and usable
2. `IUsermanager::getUser()` as a fallback

The resulting value is stored in clear form inside `context_json`.

Depending on the host, this can be an internal user ID or another stable identifier.

## 17. Session identifier processing

If `ISession` is available, the default context provider reads `ISession::getId()` and stores that value as `session_id` in `context_json`.

This is a particularly sensitive implementation detail. If the configured session service returns the live session identifier, the alias table contains that identifier in clear form for the lifetime of the alias record.

Database access to the table must therefore be restricted. Deployments with stricter session-security requirements should consider a context-provider design that binds aliases without persisting a reusable live session credential.

## 18. PrivacyVault can start the host session

When the context provider needs a session ID and `ISession` is not yet started, it calls `ISession::start()`.

PrivacyVault itself contains no cookie code, but starting the configured session can trigger the host session mechanism, including cookie behavior where applicable.

That behavior belongs to the host `ISession` implementation and must be documented by the deployment using it.

## 19. Caller-supplied context

Applications can add arbitrary context fields, for example a tenant ID or conversation ID.

Those fields are stored in `context_json` and become part of the resolution requirement.

The default provider overwrites caller-supplied `user_id` and `session_id` values with the values obtained from the active runtime when those runtime values are available.

## 20. Context matching rules

Resolution checks every stored context field against the supplied resolution context.

A value must:

- exist under the same key
- compare equal after scalar string conversion
- match recursively when the stored value is an array

Additional fields in the resolution context are allowed.

The comparison prevents an alias created with one stored user or session context from resolving under a different context when those fields are present.

## 21. Empty context is permissive

If an alias record contains an empty context, `contextMatches()` returns `true` for any provided context.

This means an empty-context alias is not user-bound or session-bound by PrivacyVault.

Sensitive multi-user integrations should use `createAliasForCurrentContext()` or otherwise ensure a restrictive context is present. The explicit-context API is intended for trusted server-side use and can deliberately create unbound aliases.

## 22. `context_key` is not an authorization secret

PrivacyVault also stores a `context_key` generated by:

1. recursively sorting the context
2. JSON encoding it
3. hashing it with SHA-256

The current resolver does not use that hash for permission checking. It uses the clear structured context from `context_json`.

The hash exists as a technical key and index. It is not encryption and must not be treated as a security token.

## 23. Expiry

The default service lifetime is:

```text
86400 seconds
```

which is 24 hours.

A newly created alias receives an `expires` timestamp based on the PHP runtime clock.

A custom service composition can use a different TTL, including a non-positive value that results in no expiry timestamp.

## 24. Expired records do not resolve

Before returning a value, `PrivacyAliasService` checks the stored expiry timestamp.

If the timestamp is in the past, resolution returns `null`.

For text replacement, the unresolved token remains visible in the text rather than being replaced with clear data.

## 25. Expiry does not delete data

The expiry check is logical only. It does not remove the database row.

`DatabasePrivacyAliasStorage` provides:

```text
deleteExpired()
```

which physically deletes expired rows.

## 26. No automatic cleanup job is included

PrivacyVault does not include an `IJob`, cron handler, worker, hook, or other scheduled cleanup component.

Therefore, expired data remains physically stored until another trusted component calls `deleteExpired()`.

A production retention design must explicitly schedule cleanup if physical deletion is required.

## 27. Deletion semantics

`deleteExpired()` executes a hard database delete for records whose `expires` value is older than the database's current time.

The plugin does not provide:

- soft deletion
- restore functionality
- per-user deletion
- per-session deletion
- per-token deletion through the public service API
- delete-by-context operations

If a deployment must fulfill targeted deletion requests, it needs an appropriate storage operation at the same storage boundary rather than relying only on TTL cleanup.

## 28. Backups and replicas

Deleting a live alias row does not automatically delete copies from:

- database backups
- snapshots
- replication archives
- infrastructure logs

The deployment's backup-retention process must account for the sensitivity of `base3_privacy_alias`.

This is especially important while the default null cryptor stores clear values.

## 29. Automatic table creation

`DatabasePrivacyAliasStorage::ensureTable()` creates the technical table on first use if it does not exist.

This requires the configured database identity to have the required DDL permission at that time.

The plugin does not run a versioned migration sequence for this table.

## 30. Database backend characteristics

The storage is injected through BASE3 `IDatabase`, but the SQL in this implementation uses MySQL/MariaDB-specific syntax such as:

- `AUTO_INCREMENT`
- `ENGINE=InnoDB`
- MySQL index declarations
- `NOW()`

Deployments using another database backend need another `IPrivacyAliasStorage` implementation.

## 31. Database error information

When `IDatabase` reports an error, `DatabasePrivacyAliasStorage` throws a `RuntimeException` containing:

- a component-level error description
- the database error number
- the database error message

If an integrating application exposes that exception directly to a user or remote client, operational database details could be disclosed.

Exception handling and client-safe error translation belong at the calling application boundary.

## 32. Policy provider behavior

`DefaultPrivacyAliasPolicyProvider::getPolicy()` currently returns `null` for every tool key.

The plugin therefore has no active automatic field policy for:

- aliasing
- masking
- dropping fields
- generalization
- field-path selection

The provider does return a model instruction describing how an LLM should preserve alias tokens.

## 33. Model instructions are advisory

The prompt instruction tells a model not to resolve, modify, invent, or decode aliases.

This can help preserve tokens across model output, but it is not an authorization control.

The actual privacy boundary is architectural:

- do not send the clear value to the model-facing payload
- do not expose the alias resolver as a model-callable tool
- enforce context on server-side resolution

## 34. No automatic LLM communication

PrivacyVault itself does not:

- select a model
- send prompts
- call an AI provider
- create embeddings
- perform web search
- call an MCP server
- transmit aliases over the network

A consuming component decides whether and where an alias-protected payload is transmitted.

That external transfer must be documented by the consuming component and deployment.

## 35. Protecting only selected values

PrivacyVault protects only values the caller explicitly aliases.

It does not automatically remove personal data from:

- conversation history
- system prompts
- user prompts
- tool arguments
- unprocessed tool-result fields
- exception messages
- logs
- metadata
- attachments
- retrieved documents

An integration must apply pseudonymization before each relevant boundary. PrivacyVault does not compensate for clear data entering the model through another path.

## 36. Final output resolution

`replaceAliases()` scans text for alias-shaped tokens and resolves each candidate under the supplied context.

The current implementation leaves a token unchanged when it is:

- unknown
- malformed
- expired
- context-mismatched
- a reference that cannot be resolved and has no fallback

This avoids converting an unresolved token into unrelated clear data.

## 37. Streaming

PrivacyVault does not provide a streaming-aware output filter.

If model output arrives in chunks, one alias token can be split across multiple chunks. The caller must buffer enough data to detect complete tokens before resolution.

The existing README contains an integration example, but that example is not an installed runtime component.

## 38. No HTTP or browser interface

The current source contains no:

- `IOutput`
- `IDisplay`
- controller
- AJAX endpoint
- browser JavaScript
- form
- administration display

PrivacyVault therefore does not directly expose alias records to the browser.

Any future or external management interface needs its own authentication, authorization, CSRF, output-encoding, and audit controls.

## 39. No direct browser storage

PrivacyVault does not directly use:

- cookies
- Local Storage
- Session Storage
- IndexedDB

The only browser-related side effect that can occur indirectly is through the configured host session if obtaining the current context starts that session.

## 40. No component-level audit log

The current component does not log:

- alias creation
- alias resolution
- failed context checks
- expired-alias access
- cleanup actions

This minimizes additional payload duplication but provides no accountability trail by itself.

A deployment that requires resolution auditing must implement it at the trusted resolution boundary and must avoid reintroducing unnecessary clear-text values into general logs.

## 41. No component-level application logging

The current source does not inject or call BASE3 `ILogger`.

PrivacyVault therefore does not intentionally write clear values, tokens, contexts, or mappings into framework logs.

However, consuming components can still log data before or after calling PrivacyVault. Their logging behavior must be reviewed separately.

## 42. Alias lookup

The storage looks up a record by the visible alias token.

Knowing a valid token alone is not sufficient to resolve a context-bound alias through `PrivacyAliasService`; the provided context must also match the stored context.

However, database-level access bypasses the service boundary entirely and can expose the stored mapping. Database authorization is therefore a critical security control.

## 43. Explicit-context resolution is trusted API surface

`resolveAlias()` and `replaceAliases()` accept caller-supplied context directly.

They do not independently authenticate the caller. They are server-side service methods intended for trusted code.

An untrusted endpoint must not simply accept arbitrary context data from a client and pass it into these methods. Authentication and derivation of trusted context belong before this service call.

## 44. Current-context convenience methods

For normal authenticated request flows, these methods reduce the risk of using an arbitrary caller-supplied user or session binding:

```text
createAliasForCurrentContext()
createReferenceAliasForCurrentContext()
resolveAliasForCurrentContext()
replaceAliasesForCurrentContext()
```

They call the configured context provider and use the current BASE3 user/session values when available.

They still depend on the correctness of the configured access-control, session, and user-manager services.

## 45. Availability of host identity services

`PrivacyVaultPlugin` treats `IAccesscontrol`, `ISession`, and `IUsermanager` as optional when creating `DefaultPrivacyContextProvider`.

If these services are missing or throw during lookup, the provider may produce a partial or empty context.

This makes the component usable in different BASE3 compositions, but it also means deployments must verify that the intended context boundary is actually available. A missing identity service must not silently be assumed to provide user isolation.

## 46. Context data itself is personal or security-relevant data

`context_json` can contain:

- user IDs
- session IDs
- tenant IDs
- conversation IDs
- caller-defined context values

The context must therefore receive the same privacy and access review as the aliased value. Pseudonymizing a name does not make the stored context harmless.

## 47. Reference data itself can identify a person

`reference_json` can contain a stable source-system identifier.

Even without the clear display value, a reference ID can be personal data when it points to a person, account, record, or other identifiable entity.

Reference-only mode reduces duplication of clear values but does not automatically make the alias record anonymous.

## 48. Metadata

The current service stores metadata including:

```text
storage_mode
generator
created_by
```

Reference aliases also store:

```text
reference_type
value_key
```

Custom storage or future extensions can add more metadata. Such metadata should not contain clear personal data unless required and intentionally protected.

## 49. Value formats

`PrivacyValueFormat` models:

- `plain`
- `encrypted`
- `reference_only`
- `custom`

Only `plain` and `reference_only` arise from the default implementations in this repository. The presence of an `encrypted` constant does not mean encryption is currently implemented.

## 50. Stateless mode

`PrivacyAliasStorageMode` includes `stateless`, but `PrivacyAliasService` does not currently create or resolve a stateless alias implementation.

Stateless privacy tokens should therefore not be assumed to exist in this version.

## 51. Cryptor replacement boundary

`IPrivacyValueCryptor` is the designated architectural boundary for protecting stored direct values.

A real cryptor should define:

- key source and ownership
- encryption algorithm
- nonce handling
- integrity protection
- key rotation
- failure behavior
- context binding where appropriate
- backup and recovery behavior

Those properties are not provided by `NullPrivacyValueCryptor`.

## 52. Encryption would not automatically protect every table field

Even after replacing the null cryptor, the current cryptor interface protects only values passed through `encrypt()` into `protected_value`.

The current storage still writes these fields separately:

- `alias_token`
- `alias_type`
- `reference_json`
- `context_json`
- `context_key`
- `metadata_json`
- timestamps

A deployment must not assume that installing an encrypted value cryptor encrypts the entire alias record.

## 53. Data minimization choices

For a given use case, the integrating component should choose the least data-bearing alias mode that still supports the required behavior.

Examples:

- use a reference-only alias when the source value can safely be resolved later and duplication is unnecessary
- avoid storing a hybrid fallback when no fallback is needed
- avoid additional caller context fields that are not required for isolation
- do not alias non-sensitive fields unnecessarily merely because aliasing is available

Data minimization should be applied at the source of the tool or service result rather than by creating additional post-processing copies.

## 54. Separation from the model-facing system

PrivacyVault's security goal depends on a strict direction of information flow:

```text
trusted clear value
-> alias creation
-> model sees alias
-> model returns alias
-> trusted resolver restores value
```

The resolver must not become a model capability. Doing so would remove the intended boundary rather than strengthen it.

## 55. Prompt injection considerations

PrivacyVault can reduce the value of prompt injection that asks a model to reveal selected clear-text tool-result fields because the model may never receive those values.

It does not prevent prompt injection generally. A model can still expose any other data that reaches its context, and it can still manipulate unprotected tool calls or output according to the surrounding agent architecture.

Prompt-injection controls remain a responsibility of the agent and tool system.

## 56. Access to the alias table

Direct database readers can bypass PrivacyVault's context checks.

Database permissions should therefore restrict access to `base3_privacy_alias` to the minimum application and maintenance identities that require it.

General reporting, analytics, support, and debug accounts should not receive access merely because the table belongs to the same application database.

## 57. Data subject access and deletion

PrivacyVault does not provide a user-facing export or deletion API.

Because records can contain user IDs and context JSON, a deployment may be able to locate aliases associated with a person, but no such query operation is included in `IPrivacyAliasStorage`.

If the deployment requires access, rectification, or deletion workflows, implement them at the storage owner boundary with explicit authorization and retention semantics.

## 58. Session termination

Ending a user session does not automatically delete aliases bound to that session ID.

They stop being useful through the normal current-context path when a different session ID is active, but the rows remain until expiry cleanup or another deletion process removes them.

## 59. User deletion

Deleting a user in the host system does not automatically delete PrivacyVault records that contain the user's ID in `context_json` or a user reference in `reference_json`.

There is no user-deletion event listener in this plugin.

A deployment that requires coordinated erasure must connect user-lifecycle deletion to the alias storage at the appropriate architecture boundary.

## 60. Reference changes and deletion

A reference-only alias resolves the current source value at resolution time through the configured resolver.

If the source record changes, a later resolution can therefore return the changed value. If the source disappears or the resolver denies access, resolution can fail.

A hybrid alias may then fall back to its stored fallback value, which can preserve old personal data even after the source changed or disappeared. That behavior must be considered in retention design.

## 61. Operational monitoring

PrivacyVault has no monitoring dashboard, health endpoint, or maintenance display.

Operators should monitor the relevant infrastructure directly, including:

- availability of the alias table
- cleanup execution
- database growth
- encryption configuration if a custom cryptor is used
- failure rates at the integrating output boundary

Monitoring should avoid copying clear alias mappings into telemetry.

## 62. Source package testing status

This package contains a detailed testing checklist in its README, but the uploaded package does not contain a `test/` directory or automated test files.

Deployments should therefore verify the critical isolation properties in their own integration, especially:

- same-context resolution
- cross-user rejection
- cross-session rejection
- empty-context behavior
- expiry behavior
- reference-resolver authorization
- cleanup behavior
- absence of clear data from model-facing tool results

## 63. Version metadata note

The package's `VERSION` file contains `4.0.0`, while the existing README status section identifies `0.2.0`.

This privacy document describes the shipped source code and does not resolve or reinterpret that existing metadata mismatch.

## 64. Production deployment checklist

Before using PrivacyVault for sensitive production data, verify at least the following:

- values that must stay out of the model are actually aliased before the model boundary
- no alternate prompt, tool, log, trace, or attachment path reintroduces those values
- the alias resolver is not available to the model
- sensitive aliases are always created with an appropriate context
- required identity and session services are present and reliable
- persistence of raw session identifiers is acceptable or the context provider has been replaced appropriately
- direct and hybrid values use a suitable cryptor if clear database storage is unacceptable
- the cryptor's key lifecycle is operationally defined
- database access to the alias table is restricted
- reference resolvers enforce their domain authorization rules
- expired aliases are physically cleaned on a defined schedule
- targeted deletion requirements are implemented if needed
- backup retention matches the retention policy
- exception handling does not expose database error details to clients
- integration logs do not capture clear values before pseudonymization
- resolution auditing is added where accountability requires it
- cross-user and cross-session isolation is tested

## 65. Security and privacy summary

PrivacyVault provides a focused mechanism for reversible pseudonymization. Its strongest protection is architectural separation: selected clear values can be replaced before a model-facing boundary and restored only in trusted output code after context validation.

The current default storage must nevertheless be treated as highly sensitive. The default cryptor stores direct values in clear text, the context can contain raw user and session identifiers, expired rows are not deleted automatically, and there is no built-in audit log or targeted erasure API.

The component is therefore a privacy-enabling building block, not a complete privacy compliance layer. Its protection depends on correct integration at the data source, output boundary, identity context, storage boundary, reference resolver, retention process, and surrounding application security controls.
