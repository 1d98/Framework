# Security roadmap — architectural risks (post-0.6.3, post-0.7.0)

This document captures the architectural-level security findings from the v0.6.3-era audit
that did not fit into a single small fix. Each proposal is below the "small fix" line —
they touch public contracts, require community input, or span multiple subsystems.

The 0.6.3 quick wins shipped in commit [`808c283`](#) ("Release v0.6.3: security hardening") and
landed in [`CHANGELOG.md`](../../CHANGELOG.md) under `## [0.6.3] - 2026-06-28` →
`### Security`. The 0.7.0 streaming work shipped in commit `3229ad1`. The remaining gaps below
are the ones the 0.6.3 release explicitly deferred — either because they needed a design
decision the maintainer (or community) had not yet made, or because the change touched a public
contract and required a migration plan.

## Proposals

1. [LogRedactor framework primitive](#1-logredactor-framework-primitive)
2. [CorsMiddleware permissive-echo opt-in](#2-corsmiddleware-permissive-echo-opt-in)
3. [`Container::autowire` deny-list](#3-containerautowire-deny-list)
4. [`IdempotencyKeyMiddleware` 5xx replay policy](#4-idempotencykeymiddleware-5xx-replay-policy)
5. [Boot warning for in-memory defaults in prod](#5-boot-warning-for-in-memory-defaults-in-prod)

## Status

All proposals are in `proposed` state. None have a release target. The maintainer
(or community) should review each proposal's trade-offs and decide which (if any)
to ship, and in what order.

| # | Proposal | Stage | Notes |
|---|---|---|---|
| 1 | `LogRedactor` framework primitive | proposed | Awaiting decision on `APP_LOG_REDACT` env knob and sensitive-key list scope |
| 2 | `CorsMiddleware` permissive-echo opt-in | proposed | Awaiting BC-vs-hardening decision (see Open questions) |
| 3 | `Container::autowire` deny-list | proposed | Awaiting decision on env-driven vs hard-coded deny-list |
| 4 | `IdempotencyKeyMiddleware` 5xx replay policy | proposed | Awaiting decision on legacy-compat flag |
| 5 | Boot warning for in-memory defaults in prod | proposed | Awaiting decision on warning surface (structured log vs `E_USER_WARNING`) and Redis-adapter scope |

---

## 1. LogRedactor framework primitive

### Problem

`StreamLogger` ([`src/Logging/StreamLogger.php`](../../src/Logging/StreamLogger.php)) writes
`$context` to the log file verbatim. The `write()` method body
([`src/Logging/StreamLogger.php:115`](../../src/Logging/StreamLogger.php)) builds the line
from `json_encode($context, ...)` via `encodeContext()` at
[`src/Logging/StreamLogger.php:166`](../../src/Logging/StreamLogger.php)) and emits no
sanitisation pass:

```php
// src/Logging/StreamLogger.php:130  (excerpt — current behaviour)
$line = "[{$timestamp}] {$levelName} {$message}{$contextPart}\n";
```

A handler that does `$logger->info('request', ['cookies' => $request->cookies()])` writes the
user's session cookie to the log file. Combined with the `chmod 0600` hardening shipped in
0.6.3 ([`src/Logging/StreamLogger.php:60`](../../src/Logging/StreamLogger.php) →
[`CHANGELOG.md`](../../CHANGELOG.md) § 0.6.3 fix #6), the file is no longer world-readable — but
the secret is still on disk in plaintext, and a sysadmin reading the log still sees it.

`RequestLogger` ([`src/Http/RequestLogger.php`](../../src/Http/RequestLogger.php)) has its own
specialised sanitiser at
[`src/Http/RequestLogger.php:74`](../../src/Http/RequestLogger.php) that strips control
characters and truncates messages to 256 bytes, but it does NOT redact `Authorization`,
`Cookie`, `Set-Cookie`, `X-CSRF-Token`, `Idempotency-Key`, or any `password` / `secret` /
`token`-shaped key that ends up in `$context`. It is also `RequestLogger`-local — every
component that builds its own log line is on its own.

The `$message` itself is not CRLF / ANSI-sanitised either; a `RuntimeException` whose message
contains `\n[ERROR] admin logged in` will produce a multi-line log entry that downstream
log-aggregators may mis-parse as two events.

### Proposed solution

Add a new primitive `Framework\Logging\LogRedactor` (`final readonly`). Primary method:

```php
public function redact(string $message, array $context = []): array;
```

- Walks `$context` recursively; replaces the value of any key whose **lowercased name**
  matches a sensitive-name list (substring match — `authorization`, `cookie`, `set-cookie`,
  `x-csrf-token`, `idempotency-key`, `password`, `secret`, `token`, `apikey`, `api_key`,
  `csrf`) with the literal `'***'`.
- Applies `AnsiSanitizer::sanitize()` from
  [`src/Console/Output/AnsiSanitizer.php:73`](../../src/Console/Output/AnsiSanitizer.php) to
  `$message` and to every string value in `$context`. (The cross-namespace dependency is
  acceptable: `Logging` is a leaf namespace and `AnsiSanitizer` is a static utility with no
  external imports.)
- Returns `[$message, $context]`.

Wire into `StreamLogger::write()` as the **first** step — before the timestamp / level-name
lookup, before `encodeContext()`. The redaction must run on the raw `$message` and `$context`
as the caller supplied them, so a value that survives `json_encode` round-tripping is the
one on disk.

Document that `RequestLogger` keeps its own specialised control-character sanitiser (because
it also truncates messages to 256 bytes and that limit is meaningful for `RequestLogger`'s
exception-message use case), but `RequestLogger::logContext()` output is
`LogRedactor`-compatible and benefits from the same key-name pass when the middleware
context array is forwarded to a `StreamLogger`.

### Trade-offs

- Adds a new symbol. Fully BC-compatible — `StreamLogger`'s public API is unchanged; the
  redaction is internal.
- Default sensitive-key list is hard-coded. Operators can pass a custom `LogRedactor`
  instance to `StreamLogger` once a constructor arg is added.
- Performance: O(N keys) per log call, but `AnsiSanitizer::sanitize()` short-circuits on the
  fast path at
  [`src/Console/Output/AnsiSanitizer.php:74`](../../src/Console/Output/AnsiSanitizer.php) when
  no trigger byte is present, so the typical log line (no escape sequences, no
  sensitive-named key) adds only a `strpbrk` check.

### Open questions

- Should redaction be disable-able via an `APP_LOG_REDACT=0` env var? (Tempting target for
  debugging; only the maintainer can decide whether "trust me, I know what I'm logging" is a
  real use case.)
- Should the sensitive-keys list extend to `credit_card`, `ssn`, `api_token`,
  `private_key`? The trade-off is "we redact what we ship defaults for" vs "operators with
  domain-specific PII add their own key list."
- Should value-pattern redaction (`/Bearer\s+\S+/i`, `/sk_live_[A-Za-z0-9]+/`) be in v1? It
  catches secrets that arrive under a generic-looking key but is much easier to false-positive
  on legitimate log content. Worth deferring to a follow-up.

### Estimated scope

| Bucket | Estimate |
|---|---|
| `src/Logging/LogRedactor.php` (new) | ~80 LOC |
| `StreamLogger` integration | 4-line insertion in `write()` + 1 new ctor arg (optional `LogRedactor`) |
| Tests | ~12 unit tests covering key-name match (case-insensitive, substring, recursive arrays), message sanitisation, fast-path no-op |
| Docs | NEW `docs/logging.md`; `docs/security.md` adds a sibling section to the OWASP A9 block at line 124; `docs/quickstart-web.md` cross-links from the log-line example |

---

## 2. CorsMiddleware permissive-echo opt-in

### Problem

`CorsMiddleware::resolveAllowHeaders()`
([`src/Http/Middleware/CorsMiddleware.php:142`](../../src/Http/Middleware/CorsMiddleware.php))
echoes the client's `Access-Control-Request-Headers` verbatim when `$this->headers` is `[]`:

```php
// src/Http/Middleware/CorsMiddleware.php:153
if ($configured === []) {
    return implode(', ', $requested);
}
```

The comment above the branch
([`src/Http/Middleware/CorsMiddleware.php:136`](../../src/Http/Middleware/CorsMiddleware.php))
documents this as the "permissive echo" path. Operators passing `headers: []` expecting
"deny all custom headers" instead get a permissive echo of every header the browser asked for
— including `X-Forwarded-For`, `Authorization` re-echoes on preflight, and any custom header
the origin page wishes to send on the actual request.

> **Source-of-truth note.** The **constructor default** for `$headers` is **not** `[]` — it
> is `['Content-Type', 'Authorization', 'X-CSRF-Token']` at
> [`src/Http/Middleware/CorsMiddleware.php:26`](../../src/Http/Middleware/CorsMiddleware.php).
> Operators only hit the permissive-echo path when they **explicitly** pass `headers: []` in
> the constructor. The proposal's "Default (`[]`) becomes 'deny all custom headers'" wording
> is therefore about the empty-array case, not the constructor default — but the operator
> mental model often blurs the two, so the hardening should make the behaviour obvious at the
> call site regardless.

### Proposed solution

Adopt the Symfony convention: the sentinel value `'*'` in `$headers` means "permissive echo"
(return the client's requested headers verbatim). The current "permissive echo on `[]`"
behaviour is removed; an empty `$headers` array now means "deny all custom headers" — the
intersection with the client's requested set is empty, so the fallback at
[`src/Http/Middleware/CorsMiddleware.php:165`](../../src/Http/Middleware/CorsMiddleware.php)
emits the empty allowlist. Operators currently relying on permissive echo via `[]` migrate
by appending `'*'` to their allowlist.

`Vary` accumulation logic at
[`src/Http/Middleware/CorsMiddleware.php:115`](../../src/Http/Middleware/CorsMiddleware.php)
is unchanged — that is a cache-keying concern, not a permission concern.

### Trade-offs

- **BC break.** Any operator currently passing `headers: []` and expecting permissive echo
  will see their custom preflight headers rejected. The migration is one symbol added to
  the array, but it is a behavioural change nonetheless. Document this in the changelog's
  `### Backwards compatibility` section.
- More secure default. The Symfony convention is widely documented and most operators
  coming from another framework will read `'*'` as "permissive echo" without prompting.
- Operator must now reason about what they want explicitly. The dual-meaning `[]` (deny
  custom headers vs. permissive echo) goes away.

### Open questions

- BC break with migration note vs. security hardening with one-line migration? The proposal
  recommends the latter (`'*'` sentinel, append-on-migration), but a less-disruptive
  alternative is to keep `[]` as permissive echo and add a new `'Deny: true` flag.
- Should the **constructor default** for `$headers` stay as
  `['Content-Type', 'Authorization', 'X-CSRF-Token']`
  ([`src/Http/Middleware/CorsMiddleware.php:26`](../../src/Http/Middleware/CorsMiddleware.php))
  — which is the safe-by-default 3-header allowlist — or should it switch to `[]` (deny all
  custom headers)? The current default is already safe for the common case; only operators
  who pass `headers: []` explicitly see the change.

### Estimated scope

| Bucket | Estimate |
|---|---|
| `src/Http/Middleware/CorsMiddleware.php` | ~30 LOC changes: sentinel detection in `resolveAllowHeaders()`, docblock on `$headers` |
| Tests | ~6 unit tests: `'*'` echoes verbatim, `[]` denies, `['Content-Type']` intersects, preflight `Vary` unchanged, credentialed-mode `'*'` rejected |
| Docs | 1 page touched — `docs/security.md` (CORS section if present, or a new entry under the CORS subsection) |

---

## 3. `Container::autowire` deny-list

### Problem

`Container::autowire()`
([`src/Container/Container.php:281`](../../src/Container/Container.php)) instantiates **any**
class via reflection:

```php
// src/Container/Container.php:283  (excerpt — current behaviour)
if (interface_exists($class) || trait_exists($class) || enum_exists($class) || !class_exists($class)) {
    throw new NotFoundException(...);
}
// ... falls through to newInstanceArgs() with no further gating
```

A handler that does `$container->get($request->query('class'))` and the user supplies
`?class=PDO` gets a live database connection opened against whatever DSN the environment
exposes — a configuration-leak path on shared hosts. Worse, `?class=Phar` enables phar
deserialization (any `file_exists('phar://...')` triggered by downstream code becomes an
attacker-controlled object-graph instantiation), `?class=DOMDocument` enables XXE, and
`?class=SimpleXMLElement` enables the same.

The deny-list target is the direct-class-name autowire path; an explicit `bind()` (which
takes precedence over autowire in
[`src/Container/Container.php:146`](../../src/Container/Container.php)) already overrides any
deny-list check.

### Proposed solution

Maintain a hard-coded deny-list of classes that should never be autowired:

```php
private const array UNSAFE_AUTOWIRE_DENYLIST = [
    'Phar', 'PharData',
    'DOMDocument',
    'SimpleXMLElement',
    'XMLReader', 'XMLWriter',
    'SQLite3',
    'PDO',
    'SplFileObject',
    'finfo',
];
```

`autowire()` throws `Framework\Container\ContainerException` (a
`Framework\Container\NotFoundException` would also work — same effect on the caller) if
asked for a denied class. Explicit `bind()` overrides the deny-list because the bind happens
before the autowire branch in `get()`.

The deny-list is keyed on the **direct FQCN**. A userland subclass `MyPDO extends PDO` is
NOT denied by the list — the deny-list is for the dangerous-base-class case, not for the
huge world of userland subclasses. Operators who want to deny a userland subclass must
register an explicit `bind()` to a safe substitute, which is the correct pattern anyway.

### Trade-offs

- Adds a deny-list to maintain. PHP-version upgrades occasionally add new dangerous classes
  (none recently — but `Random\Engine\Mt19937` was once flagged as a known weak source, for
  instance). The deny-list should be reviewed per PHP-minor release.
- Bypass via userland subclass is straightforward (`MyPDO extends PDO`). This is by design —
  the deny-list is for the direct-class-name autowire path. A "safe autowire" mode with an
  explicit allowlist is the proper defence for fully-untrusted-class-name inputs and is out
  of scope for this proposal.
- One-line BC behaviour change: an operator who is currently autowiring `PDO` via
  `$container->get(\PDO::class)` will start seeing `ContainerException`. The fix is to
  `$container->bind(\PDO::class, static fn() => new \PDO($dsn, ...))` — which is what they
  should be doing anyway, because PDO needs connection-string config.

### Open questions

- Configurable via env var (`APP_CONTAINER_AUTOWIRE_DENYLIST`)? Pro: lets operators add
  their own dangerous classes. Con: makes the behaviour runtime-dependent and harder to
  audit. The maintainer should decide whether the deny-list is part of the framework's
  safety contract (hard-coded) or a hook for operators to extend (env-driven).
- "Safe autowire" mode with an explicit allowlist instead of a denylist? This is a larger
  design — it would change the `Container` constructor signature and the
  `$container->get()` semantics. Out of scope for this proposal, but worth a follow-up
  design doc if the community signals demand.

### Estimated scope

| Bucket | Estimate |
|---|---|
| `src/Container/Container.php` | ~20 LOC: `UNSAFE_AUTOWIRE_DENYLIST` constant + a one-line `if (in_array($class, self::UNSAFE_AUTOWIRE_DENYLIST, true)) { throw ... }` after the class-exists check at line 283 |
| Tests | ~8 unit tests: each deny-listed class throws, each allowed class still autowires, `bind()` overrides deny-list, subclass bypass behaviour documented |
| Docs | `docs/container.md` adds a "Unsafe autowire deny-list" subsection next to the existing "Autowiring via reflection" block at line 52; `docs/security.md` adds an entry in the principle list at line 11 |

---

## 4. `IdempotencyKeyMiddleware` 5xx replay policy

### Problem

`IdempotencyKeyMiddleware::process()`
([`src/Http/Middleware/IdempotencyKeyMiddleware.php:112`](../../src/Http/Middleware/IdempotencyKeyMiddleware.php))
stores the response unconditionally after `$next($request)`:

```php
// src/Http/Middleware/IdempotencyKeyMiddleware.php:179  (excerpt — current behaviour)
$this->store->put(
    $key,
    $method,
    $request->path,
    $bodyHash,
    new \Framework\Http\Idempotency\IdempotencyEntry(
        status: $response->status,
        body: $response->body,
        headers: $response->headers,
        cookies: $response->cookies,
        createdAt: time(),
    ),
);
```

A transient 500 (DB blip, downstream API timeout, OOM) is captured and replayed for the full
`$ttl` window (default 86_400 s). Stripe's model — the de-facto reference for
`Idempotency-Key` — replays 5xx with a `Retry-After` indicator and clears the slot
afterwards, so the next retry re-executes the handler. The framework silently replays the
5xx for 24 hours, masking transient errors from the operator's monitoring and forcing every
retry to see the same stale failure.

4xx replay is correct: a `400 Bad Request` from a missing field is a deterministic
client-error response — replays of the same body should return the same error. Only 5xx
replay is the problem.

### Proposed solution

**Option B (safer, recommended):** On 5xx (`$response->status >= 500`), call
`$this->store->forget($key)` and return the actual error response. Subsequent retries
re-execute cleanly. Matches the new `StreamedResponse` behaviour at
[`src/Http/Middleware/IdempotencyKeyMiddleware.php:174`](../../src/Http/Middleware/IdempotencyKeyMiddleware.php)
(opportunistic replay, no error replay).

The store's `forget()` already exists and is idempotent (per the 0.7.0 changelog entry — see
[`CHANGELOG.md`](../../CHANGELOG.md) § 0.7.0 `### Added` bullet on
`IdempotencyStoreInterface::forget()`).

4xx continues to be replayed — a `422 Unprocessable Entity` for a body-hash mismatch is the
intended behaviour and is what the framework currently documents.

### Trade-offs

- **BC change.** A client that retries a 500 will now get a fresh execution each time. Most
  idempotency libraries (Stripe included) recommend NOT retrying 5xx automatically because
  the failure may be permanent on the server side; this change aligns the framework with
  that convention.
- Side-effects that DID happen are no longer masked by a stale replay. A 500 from a
  downstream payment API that *did* charge the card will now see the next retry attempt to
  charge again — but that is the correct outcome, because the first call should have reported
  the failure to the operator and let them reconcile manually.
- 4xx replay semantics unchanged. Tests that assert "same body → same 422 on retry" keep
  working.

### Open questions

- Should 4xx be replayed (current behaviour) — yes. 4xx is a deterministic
  client-error response: a `422 Unprocessable Entity` for a body-validation failure should
  be the same on every retry, because the input is the same. This proposal explicitly does
  NOT change 4xx replay.
- Operator opt-in via a `$replayServerErrors: true` flag for legacy compat? Worth a
  constructor arg so the migration is one flag-flip away for operators who depend on the
  current behaviour.
- Should `Idempotency-Key` be required (current default — `$requiredOn = ['POST', 'PUT']`)
  or optional? Out of scope for this proposal — but worth noting that an operator who
  *opts out* of the required-key check is also opting out of the replay-on-retry
  protection, so 5xx replay is only ever relevant when the key is present.

### Estimated scope

| Bucket | Estimate |
|---|---|
| `src/Http/Middleware/IdempotencyKeyMiddleware.php` | ~25 LOC: a `if ($response->status >= 500) { $this->store->forget($key); return $response; }` branch before `$this->store->put(...)` at line 179; one new optional ctor arg `$replayServerErrors = false` |
| Tests | ~10 unit tests + 1 integration test: 5xx replayed before fix, 5xx re-executes after fix, 4xx still replayed, `$replayServerErrors: true` flag restores legacy behaviour, `forget()` is called exactly once |
| Docs | `docs/idempotency.md` adds a "5xx replay policy" subsection next to the existing "Streamed responses" block at line 65; `docs/security.md` adds a one-line entry in the principle list at line 11 |

---

## 5. Boot warning for in-memory defaults in prod

### Problem

Three core subsystems ship with **in-process defaults** that are documented as such but easy
to miss:

- `RateLimitMiddleware` ([`src/Http/Middleware/RateLimitMiddleware.php`](../../src/Http/Middleware/RateLimitMiddleware.php)) — static `array<string, Bucket>` at
  [`src/Http/Middleware/RateLimitMiddleware.php:93`](../../src/Http/Middleware/RateLimitMiddleware.php) and
  [`src/Http/Middleware/RateLimitMiddleware.php:109`](../../src/Http/Middleware/RateLimitMiddleware.php)
  (`$buckets`, `$lastSweepAt`, `$hasSwept`). The class-level docblock at line 27 explicitly
  states: *"Not for production multi-instance deployments."*
- `IdempotencyKeyMiddleware` default ctor arg
  `$store = new InMemoryIdempotencyStore()` at
  [`src/Http/Middleware/IdempotencyKeyMiddleware.php:91`](../../src/Http/Middleware/IdempotencyKeyMiddleware.php).
  The middleware docblock at line 44 explicitly states: *"The default `InMemoryIdempotencyStore` is per-process."*
- `Container` static caches `$typeExistsCache` and `$reflectionCache` at
  [`src/Container/Container.php:105`](../../src/Container/Container.php) and
  [`src/Container/Container.php:124`](../../src/Container/Container.php). The class-level
  docblock at line 14 explicitly states these are *"process-wide"* and only invalidated by
  `wipeGlobalCaches()`.

The default [`public/index.php`](../../public/index.php) uses all three in-memory forms. A
prod deployment that forgets to swap to a shared store / Redis adapter will **silently not
enforce cross-process rate limits, not replay idempotency across PHP-FPM workers, and not
share the DI reflection cache across workers.** Each is a security-control degradation, not
a hard failure, and the failure mode is "things seem to work in testing, then a load test
shows the limits are 5x weaker than expected."

### Proposed solution

When `$appEnv === 'prod'` and the operator has wired an in-memory store / static cache, log
a warning at boot. The warning fires **once per worker boot** (the rate-limiter check fires
once per `process()` call, so it should be guarded by `self::$hasWarned` similar to
`$hasSwept` at
[`src/Http/Middleware/RateLimitMiddleware.php:111`](../../src/Http/Middleware/RateLimitMiddleware.php)).

Each warning points the operator to the swap-in store:

| Component | Warning target |
|---|---|
| `RateLimitMiddleware` | `RedisRateLimitStore` (to be added in a follow-up release; see Open questions) |
| `IdempotencyKeyMiddleware` | `RedisIdempotencyStore` (to be added in a follow-up release) |
| `Container` static caches | not a security control per se — informational note only |

A small shared helper detects `$appEnv === 'prod'` from `getenv('APP_ENV')` (or whatever
the project convention is — see [Configuration and environment variables](config.md)).

### Trade-offs

- Adds a `LoggerInterface` dependency to `RateLimitMiddleware` and to the
  `IdempotencyKeyMiddleware` boot path. Both already accept other collaborators via ctor
  args, so this fits the existing pattern.
- Fires once per worker boot. A 10-worker PHP-FPM pool will log 10 warnings at boot — that
  is the right behaviour, but the log line must be structured (key=value) so a log
  aggregator can dedupe.
- Operator using in-memory stores in dev / staging can suppress by setting
  `APP_ENV=dev`. This matches the existing `AppSecretValidator`
  ([`src/Security/AppSecretValidator.php`](../../src/Security/AppSecretValidator.php))
  pattern (well-known dev secret is rejected in prod only).

### Open questions

- Structured log line (PSR-3 `warning()` call) vs. `trigger_error(E_USER_WARNING)`? PSR-3 is
  consistent with the rest of the framework; `trigger_error` reaches the SAPI error log
  directly and is harder to silence. The proposal recommends PSR-3.
- Should the warning cover `Container::$typeExistsCache` and `Container::$reflectionCache`
  at [`src/Container/Container.php:105`](../../src/Container/Container.php) and
  [`src/Container/Container.php:124`](../../src/Container/Container.php)? These are not
  security controls but they do share the "process-wide static cache, not shared across
  workers" caveat. An informational-only log line at boot is probably right.
- Should Redis adapters (`RedisRateLimitStore`, `RedisIdempotencyStore`) ship in this same
  work, or in a follow-up release? Shipping them together gives the operator an actual
  one-step fix; shipping the warning first lets the operator plan the migration. The
  proposal recommends the warning first (this release), Redis adapters in the next minor
  release.

### Estimated scope

| Bucket | Estimate |
|---|---|
| `src/Http/Middleware/RateLimitMiddleware.php` | ~10 LOC: a `?LoggerInterface` ctor arg + a `self::$hasWarned` guard + the warning emission in `process()` (or a dedicated `boot()` method) |
| `src/Http/Middleware/IdempotencyKeyMiddleware.php` | ~10 LOC: same shape, warn when the default `InMemoryIdempotencyStore` is in use in prod |
| `src/Container/Container.php` | ~5 LOC: optional `boot($appEnv)` static method that warns when static caches will be used in a multi-worker prod env |
| Tests | ~4 unit tests: warning fires in prod, suppressed in dev, fires exactly once per worker, `APP_ENV=prod` detection matches existing conventions |
| Docs | 1 page touched — `docs/security.md` adds a "Boot-time warnings" subsection; the in-memory-store caveat in `docs/idempotency.md` line 48 and `docs/http-kernel.md` (rate-limit section) cross-link to it |

---

## Cross-cutting notes

### Files cited (for reviewers)

| Source | Cited for |
|---|---|
| [`src/Logging/StreamLogger.php`](../../src/Logging/StreamLogger.php) | Proposal 1 — log redaction target |
| [`src/Http/RequestLogger.php`](../../src/Http/RequestLogger.php) | Proposal 1 — pre-existing control-char sanitiser |
| [`src/Console/Output/AnsiSanitizer.php`](../../src/Console/Output/AnsiSanitizer.php) | Proposal 1 — sanitiser to be reused |
| [`src/Http/Middleware/CorsMiddleware.php`](../../src/Http/Middleware/CorsMiddleware.php) | Proposal 2 — permissive-echo target |
| [`src/Container/Container.php`](../../src/Container/Container.php) | Proposal 3 — autowire target; Proposal 5 — static caches |
| [`src/Http/Middleware/IdempotencyKeyMiddleware.php`](../../src/Http/Middleware/IdempotencyKeyMiddleware.php) | Proposal 4 — 5xx replay target |
| [`src/Http/Middleware/RateLimitMiddleware.php`](../../src/Http/Middleware/RateLimitMiddleware.php) | Proposal 5 — in-memory bucket store |
| [`src/Http/Idempotency/InMemoryIdempotencyStore.php`](../../src/Http/Idempotency/InMemoryIdempotencyStore.php) | Proposal 5 — in-memory idempotency store |
| [`src/Security/AppSecretValidator.php`](../../src/Security/AppSecretValidator.php) | Proposal 5 — precedent for "prod-only" hard fail |

### Doc-debt items noticed while writing this

- `docs/README.md` — the reading-order index has no entry for design proposals. The
  proposals are reference material for maintainers and security reviewers, not the linear
  "read me first" path — this document lives in `docs/design/` and should be linked from a
  new "Design proposals" section, not inserted into the reading order.
- `docs/security.md` line 11 — the principle list says "Redact in logs" and points at
  `RequestLogger`'s control-char sanitiser. After Proposal 1 ships, this line should be
  expanded to mention the new `LogRedactor` and its key-name pass.
- `docs/security.md` line 124 — the OWASP A9 redaction block points at `RequestLogger` as
  the framework's redaction surface. After Proposal 1 ships, this section needs a sibling
  "StreamLogger log-redaction" block; the two are complementary, not redundant.
- `docs/container.md` line 52 — "Autowiring via reflection" describes the autowire path but
  does not call out that any class-name can be autowired. After Proposal 3 ships, this
  section needs a "Deny-list" note that names the classes and points at the migration.
- `docs/idempotency.md` line 65 — "Streamed responses" is the only "exceptions to the
  replay rule" note today. After Proposal 4 ships, the "5xx replay policy" needs a sibling
  note that explains why 5xx is *also* an exception.

### Out-of-scope items noticed

- No `docs/logging.md` exists yet. `StreamLogger` is documented in
  `docs/security.md:289` and incidentally in `docs/quickstart-web.md`, but a dedicated
  logging page is missing. Proposal 1 will add it as a side effect.
- `RedisRateLimitStore` and `RedisIdempotencyStore` do not exist yet. The CHANGELOG
  refers to them at lines 33 (0.6.0) and 26 (0.7.0) as "the operator should switch to a
  Redis adapter." After Proposal 5 ships the boot warning, these become a documented gap
  rather than a documented TODO.
- `APP_LOG_REDACT`, `APP_CONTAINER_AUTOWIRE_DENYLIST`, and `APP_ENV` are referenced
  in this proposal as future or existing env vars. None requires a `.env.example` change
  before proposal acceptance — but the maintainer should confirm each is added to
  `.env.example` (and to [`docs/config.md`](config.md)) before the proposal that introduces
  it ships.