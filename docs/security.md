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
));
```

Reading the token in a handler: `$req->csrfToken()` returns the **plaintext** payload (the `SignedCookieJar` unwraps the HMAC).

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
- [Configuration and environment variables](config.md) — `APP_SECRET`, `APP_TRUSTED_HOSTS`, `APP_TRUSTED_PROXIES`.
