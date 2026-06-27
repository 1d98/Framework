# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.7.0] - 2026-06-28

### Added
- **`ResponseInterface`** in `src/Http/Response/ResponseInterface.php` — common contract for buffered and streaming responses (status, headers, cookies, reasonPhrase + `withHeader`/`withHeaders`/`withStatus`/`withCookie`/`withRequestId` builders + `send()`). PHP 8.5 property hooks with asymmetric visibility (`public int $status { get; }`). All implementations MUST be `readonly` and validate header names/values/reason phrases at construction.
- **`StreamedResponse`** in `src/Http/Response/StreamedResponse.php` — lazy response VO whose body is produced by a `Closure(resource): void` emitter at `send()` time. Auto-enables `Transfer-Encoding: chunked` via the PHP `http` stream filter when `$contentLength === null`; status guard against 1xx/204/304 (RFC 9110 §6.4 — no body on those). Static helpers `StreamedResponse::sse($emitter, $status = 200)` and `StreamedResponse::ndjson($emitter, $status = 200)` set the correct `Content-Type`, `Cache-Control`, and `X-Accel-Buffering: no` headers for those wire formats.
- **`Sse`** helper in `src/Http/Response/Sse.php` — `Sse::event($stream, $data, $event?, $id?, $retryMs?)`, `Sse::comment($stream, $text)`, `Sse::ping($stream)`, `Sse::retry($stream, $retryMs)`. Sanitizes CR/LF/NUL on every field; CR/LF/NUL in `event` / `id` / `retry` is rejected outright (would let a poisoned value smuggle a different SSE field into the frame); CR/LF in `data` is collapsed to LF and each line gets its own `data:` prefix.
- **`HttpKernel::handle()` widened to `: ResponseInterface`** ([`src/Http/HttpKernel.php:51`](../../src/Http/HttpKernel.php)). Core callable also returns `ResponseInterface`.
- **`IdempotencyStoreInterface::forget(string $key): void`** ([`src/Http/Idempotency/IdempotencyStoreInterface.php:95`](../../src/Http/Idempotency/IdempotencyStoreInterface.php)) — releases a reservation taken in `tryReserve()` when the handler returned a response shape that cannot be cached for replay. Implemented in both `InMemoryIdempotencyStore` ([`src/Http/Idempotency/InMemoryIdempotencyStore.php:158`](../../src/Http/Idempotency/InMemoryIdempotencyStore.php)) and `FilesystemIdempotencyStore` ([`src/Http/Idempotency/FilesystemIdempotencyStore.php:166`](../../src/Http/Idempotency/FilesystemIdempotencyStore.php)). Idempotent: a missing key is a silent no-op.
- **Middleware skips for `StreamedResponse`** — `EtagMiddleware` ([`src/Http/Middleware/EtagMiddleware.php:68`](../../src/Http/Middleware/EtagMiddleware.php)) and `CompressionMiddleware` ([`src/Http/Middleware/CompressionMiddleware.php:60`](../../src/Http/Middleware/CompressionMiddleware.php)) both short-circuit on a `StreamedResponse` (and on any non-`Response` `ResponseInterface` implementor), passing it through unchanged. Streaming bodies cannot be hashed-for-etag or gzipped-then-replaced, and the chunked-transfer encoding used for streaming is incompatible with the buffer-then-gzip strategy.
- **`IdempotencyKeyMiddleware` releases the reservation on streamed responses** ([`src/Http/Middleware/IdempotencyKeyMiddleware.php:174`](../../src/Http/Middleware/IdempotencyKeyMiddleware.php)) — when the handler returns a non-`Response` `ResponseInterface` (currently `StreamedResponse`), the middleware calls `IdempotencyStoreInterface::forget()` and returns the response unchanged. Subsequent retries with the same `Idempotency-Key` re-execute the handler instead of being rejected by the held reservation.
- **Docs:** new `docs/streaming-response.md` covers SSE, NDJSON, large-file downloads, deployment gotchas (PHP-FPM `output_buffering = Off`, nginx `X-Accel-Buffering: no`), and PHPUnit testing recipes. `docs/value-objects.md` gains `ResponseInterface`, `StreamedResponse`, and `Sse` sections; `docs/http-kernel.md` gains a `Response types` section; `docs/idempotency.md` documents the `Streamed responses` opt-out and the 5-method `IdempotencyStoreInterface`; `docs/security.md` adds a `Streaming-response safety` section.

### Changed
- **`Response` is no longer `final`** — now `readonly class Response implements ResponseInterface` ([`src/Http/Response/Response.php:23`](../../src/Http/Response/Response.php)). Userland code may subclass `Response` for custom buffered response shapes (e.g. a typed `JsonResponse` subclass that always sets `Content-Type: application/json`). Subclasses MUST also be `readonly` (PHP enforces it). Stays `readonly` itself.
- **All middleware return types widened to `ResponseInterface`** — `MiddlewareInterface::process()` ([`src/Http/Middleware/MiddlewareInterface.php:15`](../../src/Http/Middleware/MiddlewareInterface.php)), `Pipeline::process()` ([`src/Http/Middleware/Pipeline.php:41`](../../src/Http/Middleware/Pipeline.php)), `MiddlewareLink::__invoke()` ([`src/Http/Middleware/MiddlewareLink.php:28`](../../src/Http/Middleware/MiddlewareLink.php)), `RequestLogger`, and every built-in middleware. Controllers typed as `: Response` continue to work (covariant return types).

### Fixed
- **`IdempotencyKeyMiddleware` reservation leak on streamed responses** ([`src/Http/Middleware/IdempotencyKeyMiddleware.php:174`](../../src/Http/Middleware/IdempotencyKeyMiddleware.php)). Pre-0.7.0, when a handler returned a non-`Response` shape (only possible as a userland `ResponseInterface` implementation, since no built-in shipped one), the middleware held the `tryReserve()` slot for the full TTL window because it could not `put()` the response into the store. Every retry with the same `Idempotency-Key` then hit `tryReserve() → false` and got `409 Conflict`, even long after the original stream had completed. The middleware now calls `forget()` on the unmatched response and returns it unchanged.

### Backwards compatibility
- **`Response` is no longer `final`** — any userland `class Foo extends Response` that previously failed with `cannot extend final class Framework\Http\Response\Response` now compiles. Existing controllers that typed their return as `: Response` continue to work (PHP covariant return types — a `Response` is a `ResponseInterface`, so a `: Response` handler satisfies a `callable(Request): ResponseInterface` core).
- **Middleware return types widened from `: Response` to `: ResponseInterface`** — implementations overriding `MiddlewareInterface::process()` previously had to return `Response` (or a subtype). They may now return any `ResponseInterface` (`StreamedResponse` is the built-in option). Returning a `Response` still works unchanged; this is purely a contravariant relaxation on what counts as a valid implementation.
- **No removal.** Every 0.6.x middleware and controller compiles and runs unchanged against 0.7.0.

[0.7.0]: https://github.com/1d98/framework/compare/v0.6.3...HEAD

## [0.6.3] - 2026-06-28

### Security
- **`SignedCookieJar` algorithm allowlist and minimum secret length** (`src/Security/SignedCookieJar.php:26`). The ctor now rejects any algorithm not in `ALLOWED_ALGORITHMS = ['sha256', 'sha384', 'sha512', 'sha3-256', 'sha3-384', 'sha3-512']` and refuses secrets shorter than `MIN_SECRET_BYTES = 16` (128 bits, the HMAC floor). Operators with a sub-16-byte stub secret get a clear error at boot — `Generate a fresh secret with php bin/framework app:secret` — instead of a silently weak cookie at the first forged-cookie incident.
- **`Cookie` rejects CR / LF / NUL in name, value, path, and domain** (`src/Http/Cookie/Cookie.php:80`). `Cookie::assertNoCrlf()` is now called from both the ctor and `toHeaderValue()` on every string field, closing the header-injection path for any `Set-Cookie` constructed via the typed class.
- **`CsrfMiddleware` cookie renamed to `__Host-csrf_token`; refuses to mint over plain HTTP** (`src/Security/CsrfMiddleware.php:25`). The `__Host-` prefix pins `Secure`, `Path=/`, no `Domain=`. Browsers silently drop the cookie if any rule is violated, so the middleware now throws `LogicException` on the first safe request over an insecure connection (`Request::isSecure()` false) — failing loud beats a CSRF token that never reaches the browser.
- **`EtagPolicy` algorithm allowlist trimmed to `['xxh128', 'sha256']`** (`src/Http/Middleware/EtagPolicy.php:44`). The constructor previously accepted a wider set; `md5`, `sha1`, and any other `hash_algos()` value now throw `InvalidArgumentException` at boot. The two remaining algorithms are stable across PHP 8.5 versions and platforms.
- **`StreamLogger` chmod 0600 on path-opened streams** (`src/Logging/StreamLogger.php:60`). When the constructor opens a filesystem path itself (`new StreamLogger('/var/log/app.log')`), it now `@chmod($stream, 0o600)` immediately after `fopen()` so a mis-set umask / shared-host default cannot leave the log file world-readable. Errors are intentionally suppressed — FAT, FUSE, and Windows refuse chmod and the logger must not crash on them.
- **`FilenameSanitizer` defangs path traversal and Windows-reserved basenames** (`src/Http/Multipart/FilenameSanitizer.php`). Beyond the existing CR/LF/NUL strip, the sanitizer now strips `/` and `\`, lstrip leading dots, drops reserved basenames (`CON`, `PRN`, `AUX`, `NUL`, `COM1`–`COM9`, `LPT1`–`LPT9` — case-insensitive), caps the result at `MAX_FILENAME_BYTES = 200`, and falls back to `'file'` on empty input. `../../etc/cron.d/backdoor` and `CON.txt` are no longer reachable from `UploadedFile::$name`.
- **`SecurityHeadersMiddleware` default CSP now includes `frame-ancestors 'none'`** (`src/Http/Middleware/SecurityHeadersMiddleware.php:16`). Both the bare default and the nonce-built CSP carry the directive, closing the clickjacking gap on top of the existing `X-Frame-Options: DENY` header. Override the entire CSP via the `$csp` ctor arg if you need to embed.

### Changed
- **`RequestErrorRenderer` ctor picks up an optional `redactTrace` flag** (`src/Http/RequestErrorRenderer.php:29`). Default `true` — when set, `debug` is overridden to `false` inside `render()` so stack traces NEVER appear in the response body, even when `debug: true` is also passed. The previous one-arg shape still works (default on the new arg is the safe one).
- **`OpenApiExporter` ctor accepts an `excludePatterns` list and gains `withExcludePatterns()`** (`src/OpenApi/OpenApiExporter.php:64`). Each entry is treated as a delimiter-wrapped regex (when its first and last char are the same and in `['/', '#', '~', '|', '!', '%', '@']`) or as a literal-prefix match otherwise. `/_internal/`, `#^/admin/#`, `#^/health$#` are all common shapes. Existing 5-arg callers keep working — the new arg is optional.
- **`CorsMiddleware` default `$maxAge` is 300 seconds** (`src/Http/Middleware/CorsMiddleware.php:28`). Previously 86400 (24h) — RFC 7234 § 4.2.2 only mandates a positive integer; the shorter default limits how long a misconfigured preflight response can pin a stale `Access-Control-Allow-Headers` set in the browser cache. The ctor still accepts an explicit `$maxAge` for callers who need a longer window.

### Fixed
- **`CsrfMiddleware::clearingSetCookieHeader` uses the `__Host-` prefixed cookie name** (`src/Security/CsrfMiddleware.php:190`). After the rename, the cookie-clearing header emitted on a stale-token safe request would have targeted the old `csrf_token` cookie; both branches now use `self::COOKIE_NAME` (`__Host-csrf_token`), so the constants agree and the stale token is actually cleared.

### Backwards compatibility
- **CSRF cookie renamed `csrf_token` → `__Host-csrf_token`** (`src/Security/CsrfMiddleware.php:25`). The `__Host-` prefix forces `Secure: true`, `Path=/`, and no `Domain=` attribute; the cookie is refused by every conforming browser over plain HTTP. Any custom handler that reads the raw cookie name must update to `__Host-csrf_token` (or, better, use `CsrfMiddleware::COOKIE_NAME` so a future rename is a one-line change). The recommended pattern is `$request->cookie(CsrfMiddleware::COOKIE_NAME)` or simply `$request->csrfToken()` (set by the middleware on the request).
- **`OpenApiExporter` ctor picks up a new optional 6th argument** (`src/OpenApi/OpenApiExporter.php:64`). Existing 5-argument callers keep working without modification. CLI: `routes:openapi` adds `--exclude=<p1,p2,…>` (comma-separated list of regex or literal-prefix entries); pre-0.6.3 invocations without the flag are unchanged.

### Migration
- **5xx error responses no longer include stack frames by default**, even with `APP_DEBUG=1` (`src/Http/RequestErrorRenderer.php:29`). The new `RequestErrorRenderer` defaults `redactTrace` to `true` (the safe production value); to restore the prior behaviour in development, pass `new RequestErrorRenderer(debug: true, redactTrace: false)`. The kernel does not auto-wire `redactTrace` from `APP_DEBUG` — the kernel-level default is the safe one. Existing 1-arg call sites compile unchanged because the new arg defaults to `true`.
- **`FilenameSanitizer` now strips path separators and `..`** (`src/Http/Multipart/FilenameSanitizer.php:61`). Operators who compose upload paths with `$file->name` (`/uploads/{$file->name}`, `{$file->name}.bin`, etc.) must now sanitize explicitly — the framework no longer passes the original `Content-Disposition: filename=` through. Either build paths with a server-generated random prefix (`/uploads/{$uuid}/{$file->name}`) or pass the sanitizer output (`\Framework\Http\Multipart\FilenameSanitizer::sanitize($file->name)`) as the on-disk name.

[0.6.3]: https://github.com/1d98/framework/compare/v0.6.2...v0.6.3

## [0.6.2] - 2026-06-15

### Fixed
- **`AtomicFilesystemTest::testListFilesYieldsRecursiveContents` test-only fix on Windows.** The previous 0.6.1 fix normalized the iterator output (replacing `\` with `/`) but did NOT normalize the expected paths. The expected `$this->tmpDir . '/a.txt'` may carry `\` from `realpath()` (on Windows) joined with `/`-style suffixes, producing mixed separators (`C:\Temp/foo`). Both sides of the assertion are now normalized to forward slashes; the comparison is platform-portable.

## [0.6.1] - 2026-06-15

### Fixed
- **Cross-platform path handling in `AtomicFilesystem`.** The `write()` and `lock()` methods now normalize `\`-vs-`/` separator mismatch in the input path (caller might pass a `realpath()`-resolved path joined with `/`-style relative segments on Windows). Without the normalization, Windows `rename()` rejects the mixed-slash tmp→target rename, which broke both `FilesystemIdempotencyStore` writes and the `testWriteIsAtomicUnderConcurrentReaders` test. POSIX was unaffected.
- **`ContainerReflectionCacheTest::testCacheKeyIsClassStringOnly` test-ordering flake.** Added a `setUp()` that calls `Container::clearCaches()` so the test is robust to PHPUnit ordering changes (the `tearDown()` only was not enough when the test run order put a non-clearing suite before it).
- **`AtomicFilesystemTest::testListFilesYieldsRecursiveContents` Windows path separator.** `RecursiveDirectoryIterator` on Windows returns paths with `\`; the test normalizes both expected and actual paths to forward slashes so the comparison is portable. POSIX was unaffected.

### Skipped on Windows
- **`AtomicFilesystemTest::testWriteIsAtomicUnderConcurrentReaders`.** Skipped on Windows because the `proc_open` + concurrent-rename pattern is racy on the Windows CI runner (cross-volume `rename()` of short-lived tmp files is unreliable). Single-writer atomicity is covered by the other `testWrite*` tests on all platforms.

## [0.6.0] - 2026-06-15

### Added
- **`AtomicFilesystem`** in `src/Filesystem/` — atomic write (`tmp` + `rename` so a reader never sees a half-written file) and exclusive `flock` wrapper. Reusable primitive for cache, idempotency, and job-queue code. File `0600`, parent dir `0700`. **In-process only for `flock` semantics** — for multi-host coordination, use Redis / Memcached.
- **`StructuredErrorRenderer`** with W3C-tracecontext propagation: emits `traceparent` header + `traceId` body field, plus `requestId`. Replaces `RequestErrorRenderer` when wired into `HttpKernel` (the legacy renderer is still the default for backward compatibility). Knobs: `includeRequestId`, `includeTraceId`, `redactTrace` (suppresses stack-frame leakage in non-debug), `exposeType` (RFC 7807 `type` field).
- **`RateLimitMiddleware` response headers** — `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` (Unix epoch), `X-RateLimit-Scope` on every response; `Retry-After` on 429 (RFC 6585 / 7231). New ctor args: `policyResolver` for per-route policies, `maxRetryAfter` clamp. Per-policy bucket space (`<ip>:<scope>`) so `/login` and `/api/` can have independent limits.
- **`EtagMiddleware`** — RFC 7232 conditional GET. Computes `ETag` from the response body (`xxh128` by default, `sha256` available), short-circuits `If-None-Match` with `304 Not Modified`, enforces `If-Match` (412) on paths listed in `EtagPolicy::$ifMatchPaths`. Per-route opt-out via `$policy->skip`. Strong by default; `W/`-prefixed weak etags available.
- **`OpenApiExporter`** + `routes:openapi` command — derives an OpenAPI 3.1 document from the registered route table. Path parameters + `where()` constraints are emitted automatically; `requestBody` / `responses` / `security` are attached by a user-supplied `operationDecorator` closure. Spec compliance verified by JSON-Schema round-trip in tests.
- **`routes:list --json`** — machine-readable export of the route table (`method`, `path`, `params`, `where`). Drives CI route linting, scripts, and feeds the `OpenApiExporter`.
- **`IdempotencyKeyMiddleware`** — Stripe-style safe POST replay. First request with a given `Idempotency-Key` runs the handler; retries within the TTL window replay the captured response verbatim (status, body, headers, `Set-Cookie`). Body-hash mismatch returns `422`; in-flight collision returns `409`; missing key on a required method returns `400`. Two store adapters ship in-tree: `InMemoryIdempotencyStore` (per-process) and `FilesystemIdempotencyStore` (per-host, atomic-rename + `flock(LOCK_NB)`).
- **`PreconditionFailedHttpException`** (412) for `EtagMiddleware` `If-Match` enforcement.
- **Docs:** `docs/filesystem.md` covers `AtomicFilesystem`; the existing `http-kernel.md` was extended with the new error renderer; `validation.md` got the new "Unresolved rules" section.

### Changed
- **`Router`** — added `Router::allDetailed()` (returns `{method, path, params, where}` per route) and `Route::getConstraints()` accessor so external consumers (OpenAPI exporter, `routes:list --json`) can read the per-parameter regex fragments without reflecting on a private field.
- **`TooManyRequestsHttpException`** — new optional ctor arg `?int $retryAfter` carries the `Retry-After` header value. The exception's `headers()` method emits `Retry-After: <seconds>` automatically.
- **`HttpKernel`** — added optional ctor arg `?StructuredErrorRenderer $structuredRenderer`; when set, replaces the legacy `RequestErrorRenderer` for error rendering. Legacy callers (no arg) keep getting the same shape.
- **`HttpException::STATUS_TEXTS`** — added `412 => 'Precondition Failed'`.

### Fixed
- `AtomicFilesystem` rejects NUL bytes in paths and over-long paths up front (defense in depth against NUL-injection and accidental 4KB paths).
- The structured error renderer never leaks stack frames when `redactTrace: true` is set, even if `debug: true` is also set.

### Backwards compatibility
- No breaking changes. Every new feature is opt-in: a new middleware is not piped unless you wire it; `StructuredErrorRenderer` is not used unless you pass it to `HttpKernel`; `RateLimitMiddleware` still works exactly as before when `policyResolver` is not supplied. The `usesAnsi` → `useAnsi` rename from 0.5.4 remains the only interface change in this release cycle.

## [0.5.5] - 2026-06-15

### Fixed
- **`NamespaceResolver` paths on Windows** — `realpath()` on Windows returns the canonical path with backslashes, and the post-`realpath` `isUnder()` prefix check compared against a literal `\` prefix, so every PSR-4 lookup on Windows missed and the resolver fell back to the `App\<subdir>` heuristic even when the consumer's `composer.json` had a correct PSR-4 mapping. `normalizePath()` now also converts backslashes to forward slashes after `realpath()`, so internal comparisons in the class are platform-independent. Consumer projects on Windows now get the same `Acme\Http\Controller` namespace as on Linux.
- **`RateLimitMiddleware` sweep amortization on `FakeClock(0.0)`** — the `lastSweepAt === 0.0` "never swept" sentinel would re-fire on every request when a test clock starts at exactly `0.0`, because the first sweep records `lastSweepAt = 0.0` and the next call still sees the sentinel. Tracked "has the initial sweep ever run" in a separate `static bool $hasSwept` flag; the production `SystemClock` returns Unix-epoch time and was never affected.
- **`MakeRuleCommand` `--description` CRLF test cross-platform** — the assertion `assertStringNotContainsString("\r", $contents)` was run against the whole generated file, but on Windows `file_put_contents` and the source-file checkout use `\r\n` line endings. The assertion is now scoped to the description area (no `first\r` and no `\rsecond`) — the file as a whole may still contain `\r\n` between lines, which is correct.

## [0.5.4] - 2026-06-15

### Added
- **`AnsiSanitizer` strips terminal-hazardous bytes from `Output::write()` / `Output::error()`** — defense in depth against terminal-injection via attacker-controlled CLI messages (filenames, exception messages, JSON strings). Strips CSI / OSC / DCS / SOS / PM / APC / 2-byte escapes, C0 controls except `\t \n \r`, and NUL. `Output::success/info/warning/danger` sanitize the user-supplied payload but keep their own ANSI wrapper untouched.
- **`NamespaceResolver`** in `src/Console/Command/Make/` reads the nearest `composer.json` and returns the PSR-4 namespace prefix + relative subdir for a target directory. Wired into all five `make:*` commands (`controller`, `exception`, `middleware`, `dto`, `rule`) so the generated class file is autoloadable in the consumer's project layout, not the framework's own dev-mode namespace. Falls back to `App\<subdir>` when no PSR-4 mapping covers the path. Each command accepts a `namespaceOverride` ctor arg to bypass the resolver in tests and for non-PSR-4 consumers.
- **`RateLimitMiddleware` now supports bucket TTL + GC + optional `flock`** — the static `$buckets` store previously grew without bound and was racy across PHP-FPM / Octane / Swoole workers. New ctor args: `$bucketTtl` (default 3600 s), `$sweepInterval` (default 60 s, amortized), `$lockPath` (filesystem path passed to `flock(LOCK_EX)`), `$allowMissingKey` (when `false`, requests without an IP throw `TooManyRequestsHttpException` instead of being lumped into the shared `unknown` bucket). Neither TTL nor `flock` is a substitute for a shared store (Redis / APCu) in a multi-instance deployment — both are documented as in-process mitigations.
- **`StreamLogger` now wraps filesystem writes in `flock(LOCK_EX)`** — default-on for files opened from a path, default-off for `stdout` / `stderr` resources (where `flock` is a no-op on some platforms). New ctor arg `$withLock: ?bool` overrides the default. Concatenated log lines from parallel PHP-FPM workers no longer interleave mid-line.
- **`APP_TRUSTED_PROXIES` documented in `.env.example`** — documentation gap closed; the var was already wired into `Request::isSecure()` / `Request::ip()` but missing from the env template.

### Changed
- **`Request::isHttps()` now matches `Request::isSecure()`** — both honor a trusted-proxy trust list and the single-value `X-Forwarded-Proto` chain-spoofing guard. Previously `isHttps()` returned only the transport snapshot, which could disagree with `isSecure()` on the same request and silently diverge HSTS-cookie / rate-limiter / HTTPS-redirect behavior depending on which method a caller picked.
- **`Response::redirect()` now validates the `Location` header** for CRLF / NUL via `assertValidHeaderValue()` (previously a header-injection vector).
- **`Response::assertValidHeaderValue` error message** changed from `'Header value contains CRLF'` to `'Header value contains control character'` (the check rejects `\r \n \0`; the old message was misleading for the NUL case).
- **`Validator::validate()` no longer throws `NotFoundException` / `InvalidArgumentException`** when a `#[Validate]` DSL references an unknown rule or has invalid syntax. It now surfaces the failure as a regular `ValidationError` (rule: `unresolved`) via the new `Framework\Validation\UnresolvedRule` value object (PSR-4 file: `src/Validation/UnresolvedRule.php`). This is a behavior change — code that called `validate()` and caught those exceptions needs to catch `ValidationException` only. **Long-running workers (Swoole / Octane) that late-register rules must call `Validator::clearCaches()`** so the parser re-resolves the rule; the per-process parsed-rule cache otherwise holds the prior `UnresolvedRule` placeholder.
- **`DtoHydrator::hydrate()` now collects all `MISSING` required-parameter errors** instead of throwing on the first one, matching the multi-error contract used everywhere else in the validation pipeline.
- **`make:*` commands no longer hardcode `App\Http\…` / `Framework\…` namespaces** — they derive the target namespace from the consumer's `composer.json` PSR-4 map. The generated class is now autoloadable in the consumer's project layout. **Behavior change** for projects that relied on the old `App\Http\Controller` default and had a different PSR-4 mapping: pass a custom `namespaceOverride` ctor arg in your wired scaffolder, or rely on the consumer's `composer.json` PSR-4 map (the most common path). Existing generated files keep their old namespace and need no migration.
- **`Output::table()` sanitizes each cell's contents** with `AnsiSanitizer` (so column-width calculation sees the on-screen width, not the raw bytes — preventing attacker-controlled ANSI sequences from inflating columns and breaking alignment).
- **`OutputInterface::usesAnsi()` renamed to `useAnsi()`** — matches the verb-less form of `useAnsi()` in the production class and the `withAnsi(bool)` builder. **Breaking** for any third-party implementor of `OutputInterface`; the test-helper `MemoryOutput` and the production `Output` were both updated. If you implement `OutputInterface` outside this repo, rename the method in your class.

### Fixed
- **`MakeRuleCommand` PHP injection via `--description`** — the description was interpolated directly into a `/** … */` docblock; `*/` inside the description closed the docblock and let the user inject raw PHP into the generated file. Sanitizer strips `/*`, `*/`, CR, and NUL from the description; an all-meta-character description now produces no docblock at all.
- **`MakeMiddlewareCommand` namespace collision in consumer projects** — previously hardcoded `Framework\Http\Middleware`, which shadowed the framework's own class once a consumer project ran `composer require` and called the scaffolder. Resolved by the `NamespaceResolver` change above.
- **`MakeExceptionCommand` / `MakeControllerCommand` namespace hardcoding** — previously hardcoded `App\Http\Exception` / `App\Http\Controller` regardless of the consumer's PSR-4 layout, producing files that did not autoload for projects mapping `App\` to `app/`. Resolved by the `NamespaceResolver` change above.
- **`Response::redirect()` header injection** — `Location` was written without CRLF validation, allowing `\r\nSet-Cookie: …` injection. Now validated.
- **`UnresolvedRule` is now PSR-4 autoloadable** — moved from `src/Validation/Validator.php` into `src/Validation/UnresolvedRule.php` so production with `composer dump-autoload -o` or OPcache preloading does not break on the first unknown rule.

### Deprecated
- **`Request::isHttps()`** — the name "Https" reads as transport-only; `isSecure()` documents the trusted-proxy trust semantics. The two methods are currently equivalent (both pass through to `RequestHost::isSecure()`), but `isHttps()` is kept only for backward compatibility and may diverge in the future. New code should call `Request::isSecure()`.

### Documentation
- `docs/installation.md`, `docs/quickstart-cli.md`, `docs/config.md` bumped from `0.5.1` → `0.5.3`.
- `docs/value-objects.md` adds a callout about the `?string` return-type change in `StatusText::for()`.
- `.env.example` documents `APP_TRUSTED_PROXIES` (previously only `APP_TRUSTED_HOSTS` was in the template).

## [0.5.3] - 2026-06-11

### Added
- `MultipartBodyParser::maxPartBytes` ctor arg — per-part cap (separate from the existing cumulative `maxBodyBytes` cap). Defaults to `MultipartParser::MAX_PART_BYTES` (64 MiB).

### Changed
- `CorsMiddleware` normalizes the `Origin` header to lowercase before the whitelist match (RFC 6454 case-insensitivity for scheme/host).
- `StatusText::for()` return type changed from `string` to `?string`; returns `null` for codes outside the maintained IANA registry.
- `Response::buildStatusLine()` substitutes an empty reason phrase for `null` (no more `'Unknown'` sentinel in the wire format).
- `bin/framework` resolves the initial debug flag from the `APP_DEBUG` env var via a new `envDebug()` helper and passes it to `Application::__construct` so the ctor `$debug` arg is no longer dead in the shipped entry point.
- All 7 `make:*` commands (`make:command`, `make:controller`, `make:exception`, `make:middleware`, `make:rule`, `make:dto`, `make:controller`) print `$output->info("Class: {$class}")` so the user sees the normalized class name (snake_case → PascalCase) that was actually written.

### Fixed
- `MultipartEnvelope::assertContentLengthMatches()` now uses `ctype_digit()` instead of `is_numeric()`; `Content-Length: 1e10` and whitespace-padded numerics (`  5  `) are now rejected with a clear 400. Aligns with the `ctype_digit` check already in `RequestFactory::assertContentLengthWithinCap()`.
- The misleading comment in `MultipartBodyParser::process()` referring to "per-PART cap" now points at the new `$maxPartBytes` field instead of the cumulative cap.

### Deprecated
- `Response::setStatus()` — use `Response::withStatus()` instead. The method is kept for backward compatibility; new code should use the immutable builder.
- `Request::withTrustedProxies()` — now also marked `@internal` in addition to the existing `@deprecated since 0.5.1`; will be removed in the next minor release. New code must use `Request::withHost()` with a `RequestHost` VO.

### Documentation
- `Route::withPrefix()` PHPDoc expanded; explicitly states the original instance is not mutated and adds a `@return self` tag.
- `Request::readStreamWithCap()` PHPDoc expanded; adds `@see RequestFactory::readStreamWithCap()` and notes that new code should call the factory method directly.
- `Application::__construct` `$debug` PHPDoc expanded; explains the `null` = "fall back to env" semantics.

## [0.5.2] - 2026-06-10

### Added
- `Vary` value object for HTTP `Vary` header concatenation (`Framework\Http\Response\Vary`)
- `StatusText` value object with `public static function for(int $code): string` (`Framework\Http\Response\StatusText`)
- `ClassNameValidator::suffixed()` and `slug()` helpers (consolidates 4 previous methods)
- `docs/` reference documentation (installation, quickstart, kernel, validation, security, container, value-objects, config, embed guide)
- `CONTRIBUTING.md` with ground rules, local setup, PR checklist
- `bin/framework` is now exposed as `vendor/bin/framework` for Composer installs
- CI runs `composer check` on every push/PR

### Changed
- `Response::REASON_PHRASES` extracted into `StatusText` VO; `Response::buildStatusLine()` delegates to it
- `Response::setStatus()` is now a deprecated alias for `withStatus()`
- `SecurityHeadersMiddleware` uses a `cspOverriddenByUser` boolean flag instead of a sentinel `===` comparison
- `RequestLogger` sanitizes exception messages (truncates to 256 chars, strips control chars) for OWASP A9 compliance
- `MultipartBodyParser` throws on malformed `$_FILES` entries instead of silently dropping them
- `MakeExceptionCommand` warns and exits non-zero when the requested name collides with a built-in HTTP exception
- README test count replaced with a link to the CI workflow (live source of truth)

### Fixed
- `examples/full-app.php:26` — broken autoload path (`/vendor` → `/../vendor`)
- `public/index.php` — hardcoded version `'0.4.0'` replaced with `Framework::VERSION` constant
- `src/Http/UploadedFile.php` — `@`-error-suppression removed; failures now throw with the underlying PHP error message
- `src/Http/Middleware/RateLimitMiddleware.php` — `private static array $buckets` initialized to `[]`
- `src/Http/Middleware/CorsMiddleware.php` and `CompressionMiddleware.php` — `Vary` merge logic deduplicated
- Cross-platform: `TempFilePool` no longer normalizes path separators (works identically on Linux/macOS/Windows)
- Cross-platform: `TempFilePool::release()` no longer deletes the parent directory (was a security/portability hazard)
- Cross-platform: `tests/Support/LiveHttpTestCase.php` — `SIGTERM` is now optional on Windows

### Security
- `HttpsRedirectMiddleware` — multi-value `X-Forwarded-Proto` from a trusted proxy is rejected (chain-spoofing defense, covered by a new test)
- `CompressionMiddleware` — `Vary` header is now set correctly to `Accept-Encoding` when compression is on
- `RequestLogger` — exception messages redacted before logging (256-char cap, control chars stripped, CRLF collapsed to space)

[0.5.2]: https://github.com/1d98/framework/releases/tag/v0.5.2
[0.5.3]: https://github.com/1d98/framework/releases/tag/v0.5.3
[0.5.4]: https://github.com/1d98/framework/releases/tag/v0.5.4
[0.5.5]: https://github.com/1d98/framework/releases/tag/v0.5.5
[0.6.0]: https://github.com/1d98/framework/releases/tag/v0.6.0
[0.6.1]: https://github.com/1d98/framework/releases/tag/v0.6.1
[0.6.2]: https://github.com/1d98/framework/releases/tag/v0.6.2
[Unreleased]: https://github.com/1d98/framework/compare/v0.6.2...HEAD
