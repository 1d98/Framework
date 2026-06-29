# Defense-in-depth checklist

What this is: every security control the framework ships, why it exists, and a worked `/login` example wiring CSRF, rate limiting, body cap, signed cookies, and redaction in one go.

## Principles the framework enforces

1. **Strict defaults.** `X-Forwarded-Proto` and `X-Forwarded-For` are NEVER trusted by default. CSP defaults to `default-src 'self'`. HSTS only on a real HTTPS connection.
2. **Fail loudly at boot.** `HttpsRedirectMiddleware` refuses to boot without a trusted-host list. `CsrfMiddleware` refuses `exemptPrefixes: ['/']`. `AppSecretValidator` refuses a well-known dev default in production.
3. **Immutable responses.** Header names, values, reason phrases, and cookies are CRLF-checked at construction — a poisoned value throws at the call site, not at `send()`.
4. **Bounded inputs.** Request body capped at 10 MiB. Query string caps key and nesting depth via `SafeParseStr`. JSON parses with depth limit 512.
5. **Redact in logs.** `RequestLogger` strips control characters and truncates exception messages to 256 bytes (OWASP A9-style).

## CSRF — `CsrfMiddleware`

`CsrfMiddleware` ([`src/Security/CsrfMiddleware.php`](../../src/Security/CsrfMiddleware.php)) implements the **signed-cookie double-submit** pattern:

- On safe methods (`GET`, `HEAD`, `OPTIONS`) with no `__Host-csrf_token` cookie, generate a 32-byte random token, attach to the request, `Set-Cookie: __Host-csrf_token=<signed>; Secure; Path=/; HttpOnly; SameSite=Lax`. The `__Host-` prefix pins the cookie to `Secure`, `Path=/`, and no `Domain=` (RFC 6265bis).
- On unsafe methods (`POST`, `PUT`, `PATCH`, `DELETE`), compare the `X-CSRF-Token` header (or `_token` form field) against the signed cookie. A mismatch throws `BadRequestHttpException` (400).

```php
$container->set(SignedCookieJar::class, static fn(): SignedCookieJar => new SignedCookieJar(
    secret: getenv('APP_SECRET') ?: 'dev-only-secret-change-in-prod',
));
$container->set(CsrfMiddleware::class, static fn(Container $c): CsrfMiddleware => new CsrfMiddleware(
    jar: $c->get(SignedCookieJar::class),
    exemptPrefixes: ['/api/'],     // bearer-token APIs don't need it
    exemptPaths: ['/health'],
    trustedProxies: $trustedProxies,
    ttl: 3600,                     // 0.7.2+: reject unsafe requests with a token older than 1 hour
    graceTtl: 604800,              // 0.7.2+: 7-day window for legacy pre-0.7.2 cookies
));
```

Reading the token in a handler: `$req->csrfToken()` returns the **plaintext** payload (the `SignedCookieJar` unwraps the HMAC). The `<version>:<token>:<issuedAt>` envelope is stripped on read — the bare hex is what you embed in `<input type="hidden" name="_token">` and what you compare on unsafe requests.

### Token TTL and versioned payload (0.7.2+)

As of 0.7.2, the signed cookie payload is the versioned format `1:<bare-hex>:<issuedAt-unix-seconds>` ([`src/Security/CsrfMiddleware.php:272`](../../src/Security/CsrfMiddleware.php)). The bare token still rides the form/header; the `<version>` and `<issuedAt>` live only inside the cookie. Tokens older than `$ttl` seconds (default `3600` = 1 hour) are rejected on unsafe requests with `BadRequestHttpException: CSRF token mismatch: token expired`. A token stamped in the future is also rejected — clock-skew on the cookie side is treated as expired rather than accepted (we have no replay store, so we cannot distinguish "clock drifted" from "forged timestamp").

This closes the long-lived-CSRF-token leak window: an XSS-leaked or log-exfiltrated token is now usable for at most `$ttl` seconds, not the entire session lifetime.

> **Note on plain-HTTP deployments.** A GET request that arrives with an expired v1 cookie over plain HTTP now throws `LogicException: refusing to mint a __Host-csrf_token cookie over an insecure connection` ([`src/Security/CsrfMiddleware.php:230`](../../src/Security/CsrfMiddleware.php), throwing at [`:268`](../../src/Security/CsrfMiddleware.php)). The expired-cookie path on a safe request calls `mintFreshCookie()`, which refuses plain HTTP because `Request::isSecure()` is `false` — the `__Host-` prefix requires `Secure`, which requires TLS. Previously the expired cookie was silently passed through (the next unsafe request would have hit the `token expired` 400 anyway, so this is a "fail-fast" tightening, not a new failure mode). Any plain-HTTP CSRF deployment is already broken at the wire level: even if the cookie *were* minted, the browser would drop the `Secure`-flagged cookie and every subsequent unsafe request would 400. Plain-HTTP dev use of `CsrfMiddleware` is gated by the dev shim — see below.

```php
$middleware = new CsrfMiddleware(
    jar: $c->get(SignedCookieJar::class),
    ttl: 15 * 60,                  // 15 minutes — tighter window for high-value endpoints
    graceTtl: 0,                   // hard cut-over: refuse pre-0.7.2 cookies immediately
);
```

### Migration window for pre-0.7.2 tokens

Legacy payloads (bare 64-char hex, no version, no timestamp) are accepted for `$graceTtl` seconds (default `604800` = 7 days) after the first legacy token is observed by this PHP-FPM / Octane worker ([`src/Security/CsrfMiddleware.php:415`](../../src/Security/CsrfMiddleware.php)). The grace cutoff is recorded process-locally — a `private static ?int $v0CutoffTimestamp` initialised lazily on first v0 observation — so the 7-day clock starts when the new code first serves traffic, not when the package was upgraded.

This is a smooth rollout: existing users are not logged out by a deploy, but operators can force a hard cut-over by setting `graceTtl: 0` (refuse v0 immediately) or shorten the window (e.g. `graceTtl: 86400` = 24 hours) to retire old tokens faster. The cutoff is **not** synchronised across hosts (deliberate — the 7-day default absorbs host-clock skew and per-worker initialisation races).

### Dev SAPI shim (0.7.2+)

The `php -S` dev server is plain HTTP, so `Request::isSecure()` returns `false` and `CsrfMiddleware::mintFreshCookie()` would refuse to mint the `__Host-csrf_token` cookie. `public/index.php` installs a dev-only SAPI shim that promotes `$_SERVER['HTTPS']` to `on` so the rest of the pipeline sees a "secure" request. Three guards keep the shim tight:

1. **`APP_ENV === 'dev'` is required.** The shim fires only on an explicit `dev` env. A misconfigured `staging`, `canary`, or any other non-prod value no longer triggers it — set `APP_ENV=dev` for the dev server, set `APP_ENV=prod` (and serve over HTTPS) for anything else.
2. **Loopback is matched by CIDR, not exact address.** The immediate `REMOTE_ADDR` is checked against `Request::TRUST_LOOPBACK` (`['127.0.0.0/8', '::1/128']`, [`src/Http/Request/Request.php:41`](../../src/Http/Request/Request.php)) via `CidrMatcher::matchesAny()` ([`src/Http/CidrMatcher.php`](../../src/Http/CidrMatcher.php)). Any address in `127.0.0.0/8` qualifies — not just the literal `127.0.0.1` — so a `php -S 127.0.0.5:8000` dev bind also receives the shim.
3. **No `X-Forwarded-Proto` / `X-Forwarded-For`.** If either forwarded header is present, the shim is skipped: the operator is in fact behind a proxy and the `trustedProxies` contract on `Request::isSecure()` should govern. The shim also does not run when `APP_TRUSTED_PROXIES` is non-empty, for the same reason — explicit trust configuration wins.

When the shim is active, the framework would also emit `Strict-Transport-Security: max-age=31536000; includeSubDomains` on every response (`SecurityHeadersMiddleware` only fires HSTS when `Request::isSecure()` is true, which the shim forces). That would pin HSTS on the dev browser's `localhost` cache for a year and break plain-HTTP dev sessions after the first one. The shim calls `header_remove('Strict-Transport-Security')` after `Response::send()` to drop the header before PHP flushes it.

> **The shim is a dev convenience, not a security boundary.** A `prod` build cannot accidentally inherit the shim because gate 1 fails; a dev build that is also behind a real proxy cannot accidentally emit `Secure` cookies for an attacker because gate 3 fails. The full shim block (comment + `if`) lives in [`public/index.php`](../../public/index.php) under "Dev-only SAPI shim".

### Exempt paths must end with `/`

```php
exemptPrefixes: ['/api/']  // GOOD — matches /api/users, /api/v1/echo
exemptPaths: ['/health']   // GOOD — exact path
exemptPrefixes: ['/']      // THROWS AT BOOT — would match every path
```

## Signed cookies — `SignedCookieJar`

`SignedCookieJar` ([`src/Security/SignedCookieJar.php`](../../src/Security/SignedCookieJar.php)) HMACs the cookie value with the configured secret:

```php
$jar = new SignedCookieJar(secret: getenv('APP_SECRET'));
$cookie = $jar->makeCookie('session', 'alice', expiresAt: time() + 3600);
$response->withCookie($cookie);
$payload = $jar->read($request, 'session');   // null if signature is invalid
```

The HMAC prevents forging (e.g. `session=admin`) without the secret. For opaque session ids, the value should already be a random token.

## CSP nonces — `SecurityHeadersMiddleware`

`SecurityHeadersMiddleware` ([`src/Http/Middleware/SecurityHeadersMiddleware.php`](../../src/Http/Middleware/SecurityHeadersMiddleware.php)) emits:

| Header | Default |
|---|---|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Content-Security-Policy` | `default-src 'self'; script-src 'self' 'nonce-<random>'; style-src 'self' 'nonce-<random>'; frame-ancestors 'none'` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (only on real HTTPS) |
| `X-CSP-Nonce` | the per-request nonce |

The nonce is generated once per request and stashed in `Request::$attributes['csp_nonce']`. Read it in your view:

```php
$nonce = $request->getAttribute(SecurityHeadersMiddleware::ATTR_CSP_NONCE);
echo '<script nonce="' . htmlspecialchars((string) $nonce, ENT_QUOTES) . '">...</script>';
```

Customising: pass `headers: ['Content-Security-Policy' => "default-src 'self'; img-src 'self' https://cdn.example.com"]` and HSTS knobs (`hstsMaxAge`, `hstsIncludeSubdomains`, `hstsPreload`). Setting any header to `null` **disables** it; missing headers use the default.

## HTTPS redirect — `HttpsRedirectMiddleware`

`HttpsRedirectMiddleware` ([`src/Http/Middleware/HttpsRedirectMiddleware.php`](../../src/Http/Middleware/HttpsRedirectMiddleware.php)) returns `301` (or `308`) to the same URL with `https://` when the request is not on TLS. Wired only in `prod`:

```php
$container->set(HttpsRedirectMiddleware::class, static fn(): HttpsRedirectMiddleware
    => new HttpsRedirectMiddleware(
        statusCode: 308,             // 301 or 308 only
        trustedHosts: ['example.com', '*.example.com'],
        trustedProxies: $trustedProxies,
    ));
```

`trustedHosts` is **required** — an empty list throws at construction. Patterns support `*.example.com` wildcards. The matching is case-insensitive, port-stripped, and CRLF-guarded. `trustedProxies` lets the middleware honor `X-Forwarded-Proto: https` from a known reverse proxy; without it, the header is ignored.

## Rate limiting — `RateLimitMiddleware`

`RateLimitMiddleware` ([`src/Http/Middleware/RateLimitMiddleware.php`](../../src/Http/Middleware/RateLimitMiddleware.php)) is a token-bucket per **request key** (default: client IP). Defaults: 60-token capacity, 1 token / second refill.

```php
$pipeline->pipe(new RateLimitMiddleware(capacity: 10, refillPerSecond: 0.5));
```

A 429 (`TooManyRequestsHttpException`) is thrown **before** the handler runs. Per-route buckets via `keyExtractor`: `static fn(Request $r): string => 'login:' . ($r->ip() ?? 'unknown')`.

> **Caveat:** the bucket store is `private static array $buckets` — per-process, not shared across PHP-FPM workers or hosts. For multi-instance, back it with Redis or APCu. The class is shipped as a reference implementation.

## Request body cap — `Request::MAX_BODY_BYTES`

`Request::MAX_BODY_BYTES` is 10 MiB ([`src/Http/Request/Request.php:24`](../../src/Http/Request/Request.php)). The factory enforces it in two places: `RequestFactory::assertContentLengthWithinCap()` rejects a declared `Content-Length` over the cap, and `RequestFactory::readStreamWithCap()` aborts after `cap+1` bytes from the stream. `Transfer-Encoding: chunked` is refused on non-multipart requests — clients must declare `Content-Length`. `multipart/form-data` is exempt because the per-part cap is enforced inside `MultipartParser`.

## Uploaded file — `UploadedFile`

`UploadedFile` ([`src/Http/UploadedFile.php`](../../src/Http/UploadedFile.php)) is the typed value the multipart parser yields:

```php
$avatar = $req->files()['avatar'] ?? null;
if ($avatar instanceof UploadedFile && $avatar->isValid()) {
    if ($avatar->size > 5 * 1024 * 1024) {
        throw new BadRequestHttpException('Avatar too large (5 MiB max)');
    }
    $avatar->moveTo($targetPath);   // uses is_uploaded_file / move_uploaded_file under the hood
}
```

`UploadedFile::moveTo()` uses `is_uploaded_file()` + `move_uploaded_file()` for SAPI uploads, `rename()` for test fixtures. **MIME sniffing:** the client-supplied `$file->type` is **not** authoritative. After the move, run `mime_content_type($file->tmpPath)` (or `finfo`) on the file on disk.

## OWASP A9 redaction — `RequestLogger`

`RequestLogger` ([`src/Http/RequestLogger.php`](../../src/Http/RequestLogger.php)) is what the kernel logs through. It strips control characters (`\r \n \t` and ASCII 0x00–0x1F minus printable whitespace) and truncates exception messages to 256 bytes — a defense-in-depth measure against log-line injection (`\n[ERROR] admin logged in`) and terminal-escape smuggling. The framework redacts the kernel-emitted log lines; your own `$logger->info(..., ['body' => $request->body])` is **not** redacted — never log the raw body. Log the request id, the validation errors, and the path.

## Streaming-response safety

The streaming-response value objects enforce wire-format invariants at the boundary between PHP and the network. None of the following is opt-in — the framework rejects the violation at the call site, not at `send()`.

### `Response` subclassing constraint (must be `readonly`)

Since 0.7.0, `Response` is `readonly class Response implements ResponseInterface` ([`src/Http/Response/Response.php:23`](../../src/Http/Response/Response.php)) — `final` was removed so userland code may subclass it (e.g. a typed `JsonResponse`). The implicit constraint: **subclasses MUST also be `readonly`**. PHP enforces this — extending a `readonly` class with a non-`readonly` subclass is a compile-time error (`Class ... cannot extend readonly class Framework\Http\Response\Response`). The framework's safety contract depends on this: every `withX()` builder returns a new instance, and that invariant only holds if the class tree is fully `readonly`.

A userland subclass that adds fields and mutators mutating `$this` would break the immutable contract that middleware relies on (every middleware assumes `withHeader()` returns a new instance, never mutates the original).

### `StreamedResponse::send()` redaction on emitter throw

`StreamedResponse::send()` ([`src/Http/Response/StreamedResponse.php:206`](../../src/Http/Response/StreamedResponse.php)) wraps the emitter call in `try/catch (Throwable)`. If the emitter throws **after** headers have been sent, the catch block writes a sanitized one-line message to `STDERR`:

```
StreamedResponse::send() emitter threw after headers were sent: <exception-class>: <sanitized-message>
```

The sanitization collapses CR and LF in the message to spaces so a poisoned `$e->getMessage()` cannot smuggle a log-line break (and a misleading subsequent "ERROR" line) into stderr. The exception is then rethrown for normal error-rendering — the wire format of the partial response stays valid (the connection may be truncated mid-frame, but the chunked-transfer encoding terminator that PHP appended on close is what the client sees).

> **Defense in depth, not a substitute.** The redaction closes the log-injection path. It does NOT turn a streaming endpoint into a safe one for arbitrary user input — the emitter should still validate the data it writes (a poisoned `$data` to `Sse::event()` is rejected by the sanitizer, but a user-controlled `fwrite($stream, $raw)` from your own code is not).

### `StreamedResponse::send()` 1xx / 204 / 304 body guard (RFC 9110 §6.4)

`StreamedResponse::send()` ([`src/Http/Response/StreamedResponse.php:169`](../../src/Http/Response/StreamedResponse.php)) throws `LogicException` when called with `status < 200`, `status === 204`, or `status === 304`:

> StreamedResponse: status 204 cannot have a streamed body (RFC 9110 §6.4 forbids a body for 1xx, 204, 304); use a Response instead

RFC 9110 §6.4 forbids a body on those statuses — clients and proxies are not required to read past the response line, and a stray body can cause connection-reuse bugs (HTTP/1.1 keep-alive ambiguity around 1xx finalization) or false-positive "response body present" checks in client libraries. Use a buffered `Response::empty(204)` for those statuses; the framework will not let a streamed body slip past the spec.

### `Sse` wire-format invariants

`Sse` ([`src/Http/Response/Sse.php`](../../src/Http/Response/Sse.php)) is a parser-side guard, not just a writer convenience. Each helper rejects or normalizes input before it reaches the wire:

| Field | Rule |
|---|---|
| `$data` | CR / LF in `$data` is collapsed to LF (each line gets its own `data:` prefix). NUL is rejected outright (`InvalidArgumentException`). |
| `$event` | CR / LF / NUL rejected. A poisoned `event: tick\nSet-Cookie: ...\n` would let a server-controlled-or-attacker-influenced value smuggle a different SSE field into the frame, with downstream parser confusion as the failure mode. |
| `$id` | Same as `$event`. The browser stores the `id` verbatim and re-sends it as `Last-Event-ID` on reconnect; a poisoned `id` would let a CRLF reach the request-log layer. |
| `$retryMs` | Must be `>= 0`. Negative values are nonsensical per the SSE spec and rejected. |

The `$stream` argument is checked with `is_resource() && get_resource_type($stream) === 'stream'` before every `fwrite` — passing a non-stream resource throws `InvalidArgumentException` at the call site, not at `fwrite`.

### `Sse::ping()` heartbeat cadence is caller-paced

`Sse::ping($stream)` writes a single `: ping` line. It does **not** schedule a timer. The emitter loop owns the cadence. A long-lived SSE endpoint that never calls `Sse::ping()` (or `Sse::comment()`) will be idle-timed-out by every intermediate proxy that has a 30–60 second idle window — the connection drops, the browser reconnects, the server sees an unbounded cascade of "new" connections with no observable business event.

The cadence is not a framework knob; the framework cannot know how long your stream is supposed to live. Pick a heartbeat interval between 15 and 30 seconds for a typical SSE endpoint and emit `Sse::ping()` on the schedule.

## Worked example: protecting a `/login` endpoint

```php
$container->set(SignedCookieJar::class, static fn(): SignedCookieJar
    => new SignedCookieJar(secret: getenv('APP_SECRET')));
$container->set(CsrfMiddleware::class, static fn(Container $c): CsrfMiddleware
    => new CsrfMiddleware(jar: $c->get(SignedCookieJar::class)));

$router->get('/login', static fn(Request $r): Response => Response::html(
    '<form method="POST" action="/login">'
    . '<input type="hidden" name="_token" value="'
    . htmlspecialchars($r->csrfToken() ?? '', ENT_QUOTES) . '">'
    . '<input type="email" name="email" required>'
    . '<input type="password" name="password" required>'
    . '<button>Sign in</button></form>',
));

final readonly class LoginRequest {
    public function __construct(
        #[Validate([new RequiredRule(), new EmailRule()])]
        public ?string $email = null,
        #[Validate([new RequiredRule(), 'string', new MinRule(min: 8), new MaxRule(max: 200)])]
        public ?string $password = null,
    ) {}
}

$router->post('/login', static function (Request $r): Response {
    $body = $r->bind(LoginRequest::class);
    if (!$this->authenticate($body->email, $body->password)) {
        throw new UnauthorizedHttpException('Invalid credentials');
    }
    return Response::json(['ok' => true]);
});

$pipeline = new Pipeline($container);
$pipeline->pipe(new RateLimitMiddleware(
    capacity: 5, refillPerSecond: 0.1,
    keyExtractor: static fn(Request $r): string => 'login:' . ($r->ip() ?? 'unknown'),
));
$pipeline->pipe(CsrfMiddleware::class);
```

This single endpoint is protected by **CSRF** (form submission without a valid `__Host-csrf_token` cookie → 400), **Rate limit** (5 burst, then 1 per 10 seconds keyed by IP → 429), **Body cap** (10 MiB hard limit), **Validation** (email and password length → 422), and **HSTS** (set by `SecurityHeadersMiddleware` only on a real HTTPS connection).

## 0.6.3 hardening

A single defensive pass on the surface that faces the network and the filesystem. Each item is also documented in `CHANGELOG.md`; this page is the "how do I live with it" guide.

### `RequestErrorRenderer` defaults to `redactTrace: true`

`RequestErrorRenderer` ([`src/Http/RequestErrorRenderer.php:29`](../../src/Http/RequestErrorRenderer.php)) used to leak stack frames whenever `debug: true` was passed. From 0.6.3 on, `redactTrace` defaults to `true` — `debug` is internally overridden to `false` for the rendering call, so the `trace` field of a 5xx body is suppressed regardless of how the renderer was constructed. This is the safe production default; to restore the prior behaviour in development, opt out explicitly:

```php
use Framework\Http\RequestErrorRenderer;

// Production — safe default, no opt-in required
$renderer = new RequestErrorRenderer(debug: false);

// Development — restore the old behaviour
$renderer = new RequestErrorRenderer(debug: true, redactTrace: false);
```

> **Common pitfall.** Wiring `$kernel = new HttpKernel(..., debug: true)` is **not enough** — the legacy `RequestErrorRenderer` ctor takes its own `$debug` flag, and the safe default (`redactTrace: true`) now wins on that flag too. To see stack frames in development, build your own renderer and pass it via the `$errorRenderer` ctor arg.

### CSRF cookie rename + plain-HTTP refusal

The cookie is now `__Host-csrf_token` (`src/Security/CsrfMiddleware.php:25`). The `__Host-` prefix is enforced by every browser since ~2020 — the cookie MUST be served with `Secure`, MUST NOT carry a `Domain=` attribute, and MUST use `Path=/`. Browsers silently drop the cookie if any rule is violated, so the middleware refuses to mint over a connection it cannot prove is HTTPS:

```
LogicException: CsrfMiddleware: refusing to mint a `__Host-csrf_token` cookie
over an insecure connection. The `__Host-` cookie prefix requires Secure,
which requires HTTPS.
```

The exception is thrown on the first safe request over plain HTTP. Fix one of:
1. Serve over HTTPS (production: required).
2. Behind a TLS-terminating proxy (load balancer, nginx, Cloudflare), pass `trustedProxies` to `CsrfMiddleware` so `Request::isSecure()` honours `X-Forwarded-Proto: https` from the proxy.
3. Dev only — exempt the unsafe paths via `exemptPrefixes` / `exemptPaths` AND downgrade the cookie name to a non-`__Host-` value via a subclass.

**Migration.** Pre-0.6.3 handlers that read the raw cookie name must update. Worked example:

```php
// Before (0.6.2 and earlier)
$rawToken = $request->cookie('csrf_token');
$token = $jar->payload($rawToken ?? '') ?? null;

// After (0.6.3+) — read by the middleware constant
use Framework\Security\CsrfMiddleware;

$rawToken = $request->cookie(CsrfMiddleware::COOKIE_NAME);
$token = $jar->payload($rawToken ?? '') ?? null;

// Or, preferred: rely on the middleware to attach the plaintext token
$token = $request->csrfToken();
```

The `$request->csrfToken()` accessor is set by `CsrfMiddleware` after verifying the signed cookie, so the canonical reader path needs no migration at all.

### `FilenameSanitizer` strips path traversal

`FilenameSanitizer` ([`src/Http/Multipart/FilenameSanitizer.php`](../../src/Http/Multipart/FilenameSanitizer.php)) now strips CR/LF/NUL **and** `/`, `\`, leading dots, and Windows-reserved basenames (`CON`, `PRN`, `AUX`, `NUL`, `COM1`–`COM9`, `LPT1`–`LPT9`), then caps the result at 200 bytes. The sanitized name lands in `UploadedFile::$name`; the original `Content-Disposition: filename=` is **not** preserved.

Operators who compose upload paths with `$file->name` must sanitize explicitly — the framework no longer passes the raw client-supplied name through. Either build paths with a server-generated prefix or pass the sanitizer output as the on-disk name:

```php
use Framework\Http\Multipart\FilenameSanitizer;

$target = '/uploads/' . bin2hex(random_bytes(16)) . '/' . FilenameSanitizer::sanitize($file->name);
```

`../../etc/cron.d/backdoor` collapses to `etc.cron.dbackdoor`; `CON.txt` collapses to `.txt`; an empty result falls back to `file`. See [the `UploadedFile` section](#uploaded-file--uploadedfile) above for the full upload flow.

### `StreamLogger` chmod 0600

`StreamLogger` ([`src/Logging/StreamLogger.php:60`](../../src/Logging/StreamLogger.php)) calls `@chmod($stream, 0o600)` immediately after opening a filesystem path. The `@` is intentional — FAT, FUSE, and Windows refuse chmod and the logger must not crash on them; the chmod is best-effort, the rest of the write path is unchanged.

Pre-opened stream resources (`StreamLogger::stderr()`, `StreamLogger::stdout()`, or a `fopen()` you supplied yourself) are not chmod-ed — the logger does not own the file. If you pre-open a log file and want 0600, chmod it yourself before passing the resource in.

## Common pitfalls

> **HSTS over plain HTTP.** The middleware refuses to emit `Strict-Transport-Security` unless the connection is actually HTTPS (or `X-Forwarded-Proto: https` from a trusted proxy). Setting `hstsMaxAge` is not enough on its own.

> **CSRF-exempting the login page.** `POST /login` is **not** exempt by default — login pages need CSRF too. Listing `/login` in `exemptPaths` would let an attacker force a victim to authenticate to a known account.

> **`Set-Cookie` from a non-`Cookie` source.** `Response::withHeader('Set-Cookie', '...')` is CRLF-checked but is **not** the typed way. Use `Response::withCookie($cookie)`.

## Next

- [Request / Response / Route value objects](value-objects.md) — `Cookie` and `Vary` headers in detail.
- [HTTP kernel and middleware pipeline](http-kernel.md) — wiring order, custom middleware.
- [Streaming responses](streaming-response.md) — SSE / NDJSON / large-file download, deployment gotchas, PHPUnit testing.
- [Configuration and environment variables](config.md) — `APP_SECRET`, `APP_TRUSTED_HOSTS`, `APP_TRUSTED_PROXIES`.
