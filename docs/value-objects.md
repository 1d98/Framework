# Request / Response / Route value objects

What this is: the immutable VOs the framework hands you — `Request`, `Response`, `StatusText`, `Vary`, `Cookie` — and how to construct and mutate them safely.

All framework value objects are `final readonly class` — with one explicit exception: `Response` is `readonly` (not `final`) so userland code may subclass it for custom buffered response shapes. Every mutator returns a **new** instance.

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

## ResponseInterface

[`ResponseInterface`](../../src/Http/Response/ResponseInterface.php) is the common contract every response the kernel can hand back implements. Two built-in implementations:

- [`Response`](#response) — buffered body (small/JSON/text/HTML/redirects).
- [`StreamedResponse`](#streamedresponse) — body produced at `send()` time by an emitter closure (SSE, NDJSON, large-file download).

```php
namespace Framework\Http\Response;

use Framework\Http\Cookie\Cookie;

interface ResponseInterface
{
    public int $status { get; }

    /** @var array<string, string> */
    public array $headers { get; }

    /** @var list<Cookie> */
    public array $cookies { get; }

    public ?string $reasonPhrase { get; }

    public function withHeader(string $name, string $value): self;
    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self;
    public function withStatus(int $status, ?string $reason = null): self;
    public function withCookie(Cookie $c): self;
    public function withRequestId(string $id): self;
    public function send(): void;
}
```

Properties use PHP 8.5 **asymmetric visibility** (`public ... { get; }`) — read-only from the outside, write-only through the immutable `with*` builders. Every implementation MUST:

1. Be `readonly` (immutable; mutators return a new instance).
2. Validate header names/values and reason phrases at construction, throwing on CRLF / NUL injection rather than letting a poisoned value reach the wire at `send()` time.
3. Use canonical reason phrases from [`StatusText`](#statustext) unless the caller overrides them via the constructor or `withStatus()`.

`HttpKernel::handle()` returns `ResponseInterface` ([`src/Http/HttpKernel.php:51`](../../src/Http/HttpKernel.php)). The pipeline core, every `MiddlewareInterface::process()`, and `MiddlewareLink::__invoke()` all return `ResponseInterface`. Controllers typed as `: Response` keep compiling (covariant return type).

## Response

`Response` ([`src/Http/Response/Response.php`](../../src/Http/Response/Response.php)) is `readonly class Response implements ResponseInterface`. Since 0.7.0 it is **no longer `final`** — userland code may subclass it for custom buffered response shapes (e.g. a typed `JsonResponse` subclass that always sets `Content-Type: application/json`). Subclasses MUST also be `readonly` (PHP enforces it). Use the static factories — they pick the right `Content-Type` and the right constructor:

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

## StreamedResponse

`StreamedResponse` ([`src/Http/Response/StreamedResponse.php`](../../src/Http/Response/StreamedResponse.php)) is a lazy response VO whose body is produced by a `Closure(resource): void` emitter at `send()` time. The body is never materialised into a PHP string — the emitter writes directly to `php://output`, which the SAPI flushes to the client.

```php
use Framework\Http\Response\StreamedResponse;

new StreamedResponse(
    status: 200,
    emitter: static function ($stream): void {
        foreach (range(1, 1000) as $i) {
            fwrite($stream, "line {$i}\n");
        }
    },
    headers: ['Content-Type' => 'text/plain; charset=utf-8'],
    contentLength: null,    // null → Transfer-Encoding: chunked
    contentType: null,      // convenience for setting Content-Type
);
```

| Constructor arg | Purpose |
|---|---|
| `$status` | HTTP status code in `[100, 599]`. Out-of-range throws `InvalidArgumentException`. |
| `$emitter` | `Closure(resource): void`. The closure receives an open write stream and writes the body to it. |
| `$headers` | `array<string, string>`. Header names reject `[\r\n\0:]`, values reject `[\r\n\0]`. |
| `$cookies` | `list<Cookie>`. Re-emitted on send as `Set-Cookie` headers. |
| `$reasonPhrase` | Optional override; rejects `[\r\n\0]`. |
| `$contentLength` | `?int`. When `null`, `send()` wraps `php://output` in the PHP `http` chunked-transfer stream filter (`Transfer-Encoding: chunked`). When set, used as the `Content-Length` header (and chunking is disabled). |
| `$contentType` | `?string`. Convenience: if `Content-Type` is not in `$headers`, this value is used. |

**Chunked-encoding fallback.** When the `pecl_http` extension is not available on the PHP build, `send()` falls back to a userland chunked-encoding filter ([`Framework\Http\Response\ChunkedStreamWriter`](../../src/Http/Response/ChunkedStreamWriter.php)). The wire format is identical per RFC 7230 §4.1; performance is slightly lower because every `fwrite()` is intercepted by userland code. The capability can be probed via the static method `StreamedResponse::isHttpFilterAvailable()`. See [PHP extension requirements](streaming-response.md#php-extension-requirements) for the deployment picture.

### Static helpers

```php
StreamedResponse::sse($emitter);    // text/event-stream + Cache-Control + X-Accel-Buffering: no
StreamedResponse::ndjson($emitter); // application/x-ndjson; charset=utf-8 + Cache-Control + X-Accel-Buffering: no
```

`StreamedResponse::sse()` and `StreamedResponse::ndjson()` are the right starting points for SSE and NDJSON endpoints. They set the correct wire-format `Content-Type`, `Cache-Control: no-cache, no-transform` (for SSE — `no-transform` blocks proxies from rewriting the stream), and `X-Accel-Buffering: no` so nginx does not buffer the response.

### Invariants and `send()` pipeline

The `send()` method runs in this order:

1. **Headers-already-sent guard.** If `headers_sent($file, $line)` returns true, throws `LogicException` with the offending file and line.
2. **1xx/204/304 guard.** Status `< 200`, `=== 204`, or `=== 304` throws `LogicException` citing RFC 9110 §6.4 (no body on those statuses). Use `Response::empty()` instead.
3. **Status line.** Emits `HTTP/1.1 <code> <reason>` via `header()`.
4. **Header lines.** Emits each `$headers` entry plus a `Set-Cookie` line per cookie. CRLF-checked again at send time.
5. **Emitter invocation.** Opens `php://output`. If `contentLength === null`, attaches the `http` chunked stream filter via `stream_filter_append(..., 'http', STREAM_FILTER_WRITE, ['transfer' => 'chunked'])`. Invokes the emitter against the wrapped stream. Throws are caught; if `headers_sent()` returns true at that point (headers already flushed), the exception is sanitized (CR/LF collapsed) and written to `STDERR` before rethrowing — so the wire format stays valid even when the emitter blows up mid-stream.
6. **Cleanup.** `fflush` + `fclose` in a `finally` block so the chunked terminator (the `0\r\n\r\n` end-of-body) is written even on throw.

### Mutators

```php
$response
    ->withHeader('X-Stream-Id', 'abc123')
    ->withStatus(201)
    ->withCookie($cookie)
    ->withRequestId($request->id);
```

All return a new `StreamedResponse`. The emitter closure is shared by reference (closures are reference-counted, not copied by value), so the emitter does not have to be re-registered on every builder call.

> **Why a separate VO instead of a method on `Response`?** `Response::$body` is a `string`. A streamed body is conceptually `Closure(resource): void` — they cannot share a single field without an "is this body buffered or lazy?" branch on every read. Splitting into two implementations of `ResponseInterface` keeps the type narrow at each call site: `$response->body` is a string; `$response->emitter` is a closure.

For end-to-end usage (route handler, SSE/NDJSON, deployment gotchas, PHPUnit testing), see [Streaming responses](streaming-response.md).

## Sse

`Sse` ([`src/Http/Response/Sse.php`](../../src/Http/Response/Sse.php)) is a static helper for writing Server-Sent Events frames from inside a `StreamedResponse::sse()` emitter. Pass the `php://output` resource the emitter received.

### Methods

| Method | Writes |
|---|---|
| `Sse::event($stream, $data, $event = null, $id = null, $retryMs = null)` | A `data:` field (one line per `\n` in `$data`), optional `event:`, `id:`, `retry:` fields, then the blank-line frame terminator. |
| `Sse::comment($stream, $text)` | A `: <text>` comment line (browsers ignore it — useful as a heartbeat). |
| `Sse::ping($stream)` | A `: ping` comment. Two bytes — cheaper than `Sse::comment($stream, 'ping')` and signals intent in the wire dump. |
| `Sse::retry($stream, $retryMs)` | A `retry: <ms>` field plus the blank-line frame terminator. Use when the reconnection delay changes mid-stream; the browser applies the new value to subsequent reconnects. |

### Sanitization

| Field | Sanitization |
|---|---|
| `$data` | CR/LF in `$data` is collapsed to LF; each LF becomes a new `data:` line. NUL is rejected (`InvalidArgumentException`). |
| `$event` | Rejects CR / LF / NUL (`InvalidArgumentException`). A poisoned `event` field could smuggle a different SSE field into the frame. |
| `$id` | Same as `$event` — single line, no control bytes. |
| `$retryMs` | Must be `>= 0`. |

### Worked example

```php
use Framework\Http\Response\Sse;
use Framework\Http\Response\StreamedResponse;

return StreamedResponse::sse(static function ($stream): void {
    // Initial retry hint — browser waits 5s before reconnect.
    Sse::retry($stream, 5_000);

    // Comment frame (ignored by EventSource clients; visible in curl -N).
    Sse::comment($stream, 'connection open');

    // Real event.
    Sse::event(
        stream: $stream,
        data: json_encode(['user' => 'alice', 'id' => 42], JSON_THROW_ON_ERROR),
        event: 'user.created',
        id: '42',
    );

    // Heartbeat every 30s — keeps proxies from idle-timing-out the connection.
    for ($i = 0; $i < 30; $i++) {
        sleep(1);
        if ($i % 30 === 29) {
            Sse::ping($stream);
        }
    }
});
```

> **`Sse::ping()` cadence is caller-paced.** The helper writes a single `: ping` line — it does **not** schedule a timer. If you want a periodic heartbeat, the emitter loop owns that timer (or you can drive it from ReactPHP / Amp / Swoole if you wire a long-running worker).

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

- [Streaming responses](streaming-response.md) — SSE / NDJSON / large-file download end-to-end, deployment gotchas, PHPUnit testing.
- [DI container and reset semantics](container.md) — how a `Response` factory could be wired.
- [Defense-in-depth checklist](security.md) — `Cookie`, `SignedCookieJar`, the `Sse` wire-format invariants, and the 1xx/204/304 body guard.
