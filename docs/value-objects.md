# Request / Response / Route value objects

What this is: the immutable VOs the framework hands you — `Request`, `Response`, `StatusText`, `Vary`, `Cookie` — and how to construct and mutate them safely.

All framework value objects are `final readonly class`. Every mutator returns a **new** instance.

## Request

`Request` ([`src/Http/Request/Request.php`](../../src/Http/Request/Request.php)) is a DTO. Build it from SAPI globals or directly:

```php
use Framework\Http\Request\Request;

// From PHP's SAPI
$request = Request::fromGlobals();

// Direct construction (tests / CLI / fixtures)
$request = new Request(
    method: 'GET',
    path: '/users/42',
    queryString: 'expand=posts',
    headers: ['host' => 'example.com'],
);
```

`Request::fromGlobals()` enforces a body cap of `Request::MAX_BODY_BYTES` (10 MiB) — bodies over the cap throw `PayloadTooLargeHttpException` (HTTP 413) before any middleware runs. Direct construction also enforces the cap unless you pass `maxBodyBytes: PHP_INT_MAX` for a test fixture.

### Public read-only properties and accessors

```php
$request->method;       // 'GET'
$request->path;         // '/users/42'
$request->queryString;  // 'expand=posts'
$request->headers;      // ['host' => 'example.com', ...]  (lowercased keys)
$request->body;         // raw body string
$request->json;         // mixed, set by JsonBodyParser
$request->form;         // ?array, set by FormBodyParser / MultipartBodyParser
$request->files;        // ?array<UploadedFile|list<UploadedFile>>
$request->cookies;      // ['__Host-csrf_token' => '...']
$request->csrfToken;    // ?string, set by CsrfMiddleware
$request->id;           // 'abcd1234...' — X-Request-Id or random
$request->attributes;   // request-scoped bag for middleware

$request->query();            // array<string, string|list<string>>
$request->header('Host');     // ?string
$request->cookie('csrf');     // ?string
$request->isHttps();          // true only if the immediate transport is TLS
$request->isSecure($proxies); // honors X-Forwarded-Proto from a trusted proxy
$request->ip($proxies);       // ?string
$request->host($trusted);     // string, validated against trusted-host patterns
$request->getAttribute('k', null);
```

### `withX()` mutators

Every mutator returns a **new** instance and shares the per-request memo (binding cache). Use `withJson`, `withForm`, `withFiles`, `withCsrfToken`, `withValidator`, `withId`, `withHost`, and `withAttribute`. `withAttribute` is the standard way to thread per-request state from a middleware down to a handler — CSP nonces, route match results, authenticated user.

## Response

`Response` ([`src/Http/Response/Response.php`](../../src/Http/Response/Response.php)) is `final readonly`. Use the static factories — they pick the right `Content-Type` and the right constructor:

```php
Response::text('Hello');                  // 200 text/plain; charset=utf-8
Response::html('<h1>Hello</h1>');         // 200 text/html; charset=utf-8
Response::json(['users' => []]);          // 200 application/json
Response::json(['created' => true], 201); // custom status
Response::empty(204);                     // empty body
Response::noContent();                    // alias for empty(204)
Response::redirect('/login', 302);        // 302 with Location:
```

`Response::json()` uses `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` for readable payloads; encoding failure throws `InternalServerErrorHttpException` (500). `Response::redirect` accepts only redirect codes (`301, 302, 303, 307, 308`).

Header names and values are checked for CRLF and NUL at construction time ([`src/Http/Response/Response.php:171`](../../src/Http/Response/Response.php)) — a poisoned value throws `InvalidArgumentException` and **never reaches the wire**. For direct construction, use `new Response(status: ..., body: ..., headers: [...], cookies: [...], reasonPhrase: '...')` (all named).

### Mutators and `send()`

```php
$response
    ->withHeader('X-Total-Count', '100')
    ->withStatus(404, 'No Such User')      // optional reason phrase
    ->withBody('<h1>404</h1>')
    ->withCookie($cookie)
    ->withRequestId($request->id);

$response->send();   // writes status line, headers, cookies, body
```

All return new instances; the mutators run the same CRLF/NUL guard. In long-running workers (Octane, Swoole) you usually want to **return** the response from the handler and let the worker driver call `send()` — for `php -S`, calling `send()` at the bottom of `public/index.php` is correct.

## StatusText

`StatusText` ([`src/Http/Response/StatusText.php`](../../src/Http/Response/StatusText.php)) maps HTTP status codes to the canonical reason phrase: `StatusText::for(404) === 'Not Found'`, `for(200) === 'OK'`.

> **Breaking change since 0.5.3.** `StatusText::for()` returns `?string` — `null` for codes outside the maintained IANA registry, instead of the previous `'Unknown'` sentinel. `Response::buildStatusLine()` substitutes an empty string for `null` so the wire format never contains `'Unknown'`. Code that called `for($code)` and concatenated the result should null-coalesce (`?? ''`) or fall back to a custom phrase.

`Response::send()` calls this internally to build the status line.

## Vary

`Vary` ([`src/Http/Response/Vary.php`](../../src/Http/Response/Vary.php)) collapses header duplication for the `Vary:` response header — the value CDNs and reverse proxies key their cache on:

```php
use Framework\Http\Response\Vary;

Vary::merge('', 'Accept-Encoding');                  // 'Accept-Encoding'
Vary::merge('Accept-Encoding', 'Origin');            // 'Accept-Encoding, Origin'
Vary::merge('Accept-Encoding', 'accept-encoding');   // 'Accept-Encoding' (case-insensitive dedup)
```

`Vary::tokens('Accept-Encoding, Origin')` returns `['Accept-Encoding', 'Origin']`. The shipped `CompressionMiddleware` and `CorsMiddleware` both use `Vary::merge()` to add their axes without overwriting prior values.

## Cookie

`Cookie` ([`src/Http/Cookie/Cookie.php`](../../src/Http/Cookie/Cookie.php)) is the typed alternative to building a `Set-Cookie` string by hand:

```php
new Cookie(
    name: 'session',
    value: 'abc123',
    expiresAt: 0,                 // 0 = session cookie
    path: '/',
    domain: null,                 // null = current host only
    secure: false,                // set true behind HTTPS
    httpOnly: true,               // default true
    sameSite: 'Lax',              // 'Lax' | 'Strict' | 'None'
);
```

The constructor enforces: no CRLF in any string field; `sameSite` is one of `Lax` / `Strict` / `None`; `SameSite=None` requires `secure=true` (RFC 6265bis). `Response::withCookie()` adds it to the response.

### Signed cookies

For tamper-resistant cookies, use `SignedCookieJar` ([`src/Security/SignedCookieJar.php`](../../src/Security/SignedCookieJar.php)):

```php
$jar = new SignedCookieJar(secret: getenv('APP_SECRET'));
$cookie = $jar->makeCookie('session', 'alice', expiresAt: time() + 3600);
$response = $response->withCookie($cookie);
```

`SignedCookieJar::sign()` produces `value.signature` (base64url HMAC). The reader path is `jar->read($request, 'session')` — returns the original payload or `null` if the signature does not match. The CSRF middleware uses this internally for the `__Host-csrf_token` cookie (since 0.6.3; previously `csrf_token`).

> **Since 0.6.3** `SignedCookieJar` rejects any algorithm not in `ALLOWED_ALGORITHMS = ['sha256', 'sha384', 'sha512', 'sha3-256', 'sha3-384', 'sha3-512']` and refuses secrets shorter than `MIN_SECRET_BYTES = 16`. See the [0.6.3 hardening section in the security chapter](security.md#0-6-3-hardening) for the full list of changes.

## Common pitfalls

> **Header injection attempts.** `Response::withHeader()` rejects `\r`, `\n`, `\0` in any value. Validate user input before reflecting it into a `Location:`.
> **`Request::withX` does not mutate.** Every `with*` returns a new instance. Forgetting to assign it back is the most common bug in custom middleware.
> **`Request::$json` is `mixed`.** Check `is_array()` before indexing.
> **`Cookie::SAME_SITE_VALUES`** is a `const array` — `'lax'` (lowercase) throws. Use the exact case.

## Next

- [DI container and reset semantics](container.md) — how a `Response` factory could be wired.
- [Defense-in-depth checklist](security.md) — `Cookie`, `SignedCookieJar`, and the response headers that travel with your data.
