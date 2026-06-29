# HTTP kernel and middleware pipeline

What this is: how `HttpKernel` invokes your router, the order middlewares run in, the built-in list, and how to write your own.

## The kernel

```php
namespace Framework\Http;

final class HttpKernel
{
    public function __construct(
        private readonly Router $router,
        ?Pipeline $pipeline = null,
        private readonly ?ContainerInterface $container = null,
        ?LoggerInterface $logger = null,
        private readonly bool $debug = false,
        ?RequestErrorRenderer $errorRenderer = null,
        ?RequestLogger $requestLogger = null,
        ?StructuredErrorRenderer $structuredRenderer = null,
    ) {}

    public function handle(Request $request): ResponseInterface { ... }
}
```

The full source is [`src/Http/HttpKernel.php`](../../src/Http/HttpKernel.php) (≈120 lines). The contract is:

1. Build the pipeline (default: empty `new Pipeline($container)`).
2. Call `handle($request)`.
3. The pipeline runs the registered middlewares around the **core**, which calls `Router::match($request)` and invokes the matched handler.
4. Any uncaught `Throwable` reaches `StructuredErrorRenderer` (when supplied) or the legacy `RequestErrorRenderer` and becomes an `application/problem+json` response.
5. The response gets `X-Request-Id` set from `Request::$id`.

`HttpKernel` always builds a default `RequestErrorRenderer` and `RequestLogger` — pass your own to override. To upgrade the error renderer, pass a `StructuredErrorRenderer` (see below); legacy `RequestErrorRenderer` is still used when no `StructuredErrorRenderer` is supplied, so existing code is unchanged.

## Error rendering

The kernel ships two renderers. Pick one based on whether you need request/trace correlation and a `redactTrace` knob.

### `RequestErrorRenderer` (legacy, default)

The minimal renderer. Emits an `application/problem+json` body with `type`, `title`, `status`, `detail`, `instance` and (for 4xx validation) `errors[]`. The response gets `X-Request-Id` set from `Request::$id`. No trace context.

Since 0.6.3, the ctor takes a second optional argument `bool $redactTrace = true` ([`src/Http/RequestErrorRenderer.php:29`](../../src/Http/RequestErrorRenderer.php)). When `redactTrace: true` (the default), the renderer internally forces `debug` to `false` for the rendering call — stack frames NEVER appear in the response body, even if `debug: true` was passed:

```php
use Framework\Http\RequestErrorRenderer;

// Production — safe default; debug=false, no frames leaked
$renderer = new RequestErrorRenderer(debug: false);

// Development — restore the old behaviour, see frames in the 500 body
$renderer = new RequestErrorRenderer(debug: true, redactTrace: false);
```

The kernel auto-builds a `RequestErrorRenderer(debug: (bool) getenv('APP_DEBUG'))` — that single-arg form picks up the new `redactTrace: true` safe default. To see frames in development, build your own renderer and pass it via the `$errorRenderer` ctor arg on `HttpKernel`. See the [0.6.3 hardening section in the security chapter](security.md#requesterrorrenderer-defaults-to-redacttrace-true) for the migration.

### `StructuredErrorRenderer` (recommended for production)

Adds three orthogonal knobs to the legacy renderer:

| Knob | Default | Effect |
|---|---|---|
| `includeRequestId` | `true` | `X-Request-Id` header + `requestId` body field |
| `includeTraceId` | `true` | Parses incoming `traceparent` (or mints one), emits `traceparent` header + `traceId` body field |
| `redactTrace` | `true` | Suppresses the `trace` stack-frame array in 5xx bodies, even when `debug: true` |
| `exposeType` | `false` | Hides the `type` body field for a cleaner non-debug response (`about:blank` is implied per RFC 7807) |

Wire it in:

```php
use Framework\Http\StructuredErrorRenderer;

$kernel = new HttpKernel(
    router: $router,
    logger: $logger,
    debug: (bool) getenv('APP_DEBUG'),
    structuredRenderer: new StructuredErrorRenderer(
        debug: (bool) getenv('APP_DEBUG'),
        // redactTrace defaults to true; explicit for clarity
        redactTrace: !(bool) getenv('APP_DEBUG'),
    ),
);
```

A 5xx in non-debug mode now returns:

```http
HTTP/1.1 500 Internal Server Error
Content-Type: application/problem+json
X-Request-Id: 0a1b2c3d4e5f6a7b
traceparent: 00-aaaabbbbccccddddeeeeffffaaaabbbb-1111222233334444-01

{
  "title": "Internal Server Error",
  "status": 500,
  "detail": "Internal Server Error",
  "instance": "/api/orders",
  "requestId": "0a1b2c3d4e5f6a7b",
  "traceId": "aaaabbbbccccddddeeeeffffaaaabbbb"
}
```

In debug mode the `type` field appears (`"about:blank"` by default, or your custom `HttpException::type`) and the `trace` field lists the stack frames — unless `redactTrace: true` is set, in which case the trace is suppressed regardless of debug.

### Trace propagation

`StructuredErrorRenderer` parses the W3C `traceparent` header on the incoming request:

```
traceparent: 00-aaaabbbbccccddddeeeeffffaaaabbbb-1111222233334444-01
```

If the header is well-formed, the `traceId` in the response body matches the upstream trace id. If it is missing, malformed, or has the wrong field widths, a fresh `TraceContext::mint()` is generated with `random_bytes` (16-byte trace id, 8-byte span id). The framework never fails a request because of a bad upstream header.

The trace id propagates through:
- `traceparent` response header (so the next service in the chain can keep the same trace)
- `traceId` body field (so a developer reading a 500 in their console can grep the access log for the same trace)
- The `TraceContext` value object (so any other middleware that needs it can ask `Request::getAttribute('trace_context')` — wiring that in is a 3-line change in your middleware)

## The pipeline

```php
namespace Framework\Http\Middleware;

final class Pipeline
{
    public function pipe(MiddlewareInterface|string $middleware): void;
    public function process(Request $request, callable $core): ResponseInterface;
}
```

`Pipeline::pipe()` ([`src/Http/Middleware/Pipeline.php:32`](../../src/Http/Middleware/Pipeline.php)) accepts either a `MiddlewareInterface` instance **or a class-string** to be resolved through the container at request time. A class-string requires a non-null container on the pipeline — `new Pipeline()` with no container throws when it tries to resolve a class.

Order: the **first** piped middleware is the **outermost** shell. `pipe(A); pipe(B);` runs `A → B → core → B → A` on the way out.

## Middleware interface

`MiddlewareInterface::process(Request, callable): ResponseInterface` ([`src/Http/Middleware/MiddlewareInterface.php:15`](../../src/Http/Middleware/MiddlewareInterface.php)). `$next($request)` invokes the next layer. Return the response from `next()` (possibly mutated) or short-circuit with a new `Response` or `StreamedResponse`. **Always return a `ResponseInterface`** — the kernel never inspects nulls. Since 0.7.0 the return type is widened to `ResponseInterface`; middleware may now hand back either a buffered `Response` or a streaming `StreamedResponse`.

## Response types

`HttpKernel::handle()` ([`src/Http/HttpKernel.php:51`](../../src/Http/HttpKernel.php)) returns `ResponseInterface`. Two implementations ship in the framework:

| Type | When | Wire format |
|---|---|---|
| [`Response`](value-objects.md#response) | Body fits in memory. | Headers + buffered body. ETag-able, gzip-able. |
| [`StreamedResponse`](value-objects.md#streamedresponse) | Body is too large or too long-lived to buffer (SSE, NDJSON, large file). | Headers + `php://output` writes via the `http` chunked stream filter (when `contentLength === null`). |

The kernel and middleware are polymorphic over the return type — middleware that buffers the body short-circuits on a streamed response and vice versa.

### Middleware behavior with `StreamedResponse`

Three built-in middlewares change shape on a streamed response:

| Middleware | Buffered `Response` | `StreamedResponse` |
|---|---|---|
| `EtagMiddleware` | Computes an `ETag` from the body, returns `304` on `If-None-Match`, enforces `If-Match` (412). | Pass-through. Streaming bodies cannot be hashed-for-etag. Set your own `ETag` header on the streamed response if you need cacheability. |
| `CompressionMiddleware` | gzips bodies over `$threshold` (default 1 KiB), sets `Content-Encoding: gzip` + `Vary: Accept-Encoding`. | Pass-through. The buffer-then-gzip strategy is incompatible with chunked transfer encoding. |
| `IdempotencyKeyMiddleware` | `put()`s the response into the store for replay on retry. | Calls `IdempotencyStoreInterface::forget()` to release the reservation and returns the streamed response unchanged. No replay guarantee — see [the Streamed responses section of the idempotency chapter](idempotency.md#streamed-responses). |

Middleware that reads `$response->body` will fail loudly on a streamed response (the field is on `Response`, but the wire body is on the emitter). For a streamed response, call `$response->send()` and read from `php://output` indirectly via the emitter.

### Body parsers don't interact

The body-parser middlewares (`JsonBodyParser`, `FormBodyParser`, `MultipartBodyParser`) mutate the **request**, not the response. They are indifferent to whether the handler returns a buffered or streamed response — the response choice is purely downstream. A route can parse a JSON body and stream SSE back, e.g. an AI token-by-token endpoint.

For the streaming-response end-to-end story (route handlers, deployment gotchas, PHPUnit testing), see [Streaming responses](streaming-response.md).

## Pipeline order in `public/index.php`

[`public/index.php:202`](../../public/index.php) wires:

```php
$pipeline = new Pipeline($container);
$pipeline->pipe(CompressionMiddleware::class);
if ($appEnv === 'prod') {
    $pipeline->pipe(HttpsRedirectMiddleware::class);
}
$pipeline->pipe(CorsMiddleware::class);
$pipeline->pipe(SecurityHeadersMiddleware::class);
$pipeline->pipe(JsonBodyParser::class);
$pipeline->pipe(FormBodyParser::class);
$pipeline->pipe(MultipartBodyParser::class);
$pipeline->pipe(CsrfMiddleware::class);
```

Outer → inner. Why this order: **Compression** outer so 500s still get gzipped; **HttpsRedirect** in prod before anything else; **CORS** decorates every response; **SecurityHeaders** sets CSP + HSTS; **Body parsers** only mutate the request; **CSRF** is innermost so `_token` is already in `$request->form()`.

## Built-in middlewares

| Middleware | Purpose | Notable options |
|---|---|---|
| `CompressionMiddleware` | gzip responses ≥ 1 KiB | `$threshold`, `$level`, `$compressibleTypes` |
| `CorsMiddleware` | RFC-aware CORS with `Vary` header | `$origins`, `$methods`, `$headers`, `$credentials`, `$maxAge` (default 300 s since 0.6.3; was 86400) |
| `FormBodyParser` | parses `application/x-www-form-urlencoded` | — |
| `HttpsRedirectMiddleware` | 301 → https in prod | `$statusCode` (301/308), `$trustedHosts` (required), `$trustedProxies` |
| `JsonBodyParser` | parses `application/json` | — |
| `MultipartBodyParser` | parses `multipart/form-data` + writes uploads to a tmp dir | `$tmpDir`, `$maxBodyBytes` |
| `RateLimitMiddleware` | per-key token bucket | `$capacity`, `$refillPerSecond`, `?Clock`, `?Closure` key extractor |
| `SecurityHeadersMiddleware` | CSP + HSTS + clickjacking/MIME guards | `$headers`, `$hstsMaxAge`, `$csp`, `$trustedProxies` |
| `CsrfMiddleware` | signed-cookie double-submit CSRF | `$jar`, `$exemptPrefixes`, `$exemptPaths`, `$logger`, `$trustedProxies`, `$ttl` (default 3600 s, 0.7.2+), `$graceTtl` (default 604800 s, 0.7.2+) |

## Custom middleware

```php
namespace App\Http\Middleware;

use Framework\Http\Middleware\MiddlewareInterface;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Response\ResponseInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $expectedToken,
    ) {}

    public function process(Request $request, callable $next): ResponseInterface
    {
        $auth = $request->header('Authorization');
        if ($auth !== 'Bearer ' . $this->expectedToken) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        return $next($request);
    }
}
```

## DI: passing config to a middleware

**Factory closure** — register in the container, resolve by class-string in the pipeline:

```php
$container->set(AuthMiddleware::class, static fn(): AuthMiddleware
    => new AuthMiddleware(expectedToken: getenv('APP_API_TOKEN') ?: ''));

$pipeline->pipe(AuthMiddleware::class);   // resolved lazily by the pipeline
```

**Direct instance** — pipe an instance for the rare case the middleware has no constructor dependencies: `$pipeline->pipe(new StripCookiesMiddleware());`. The pipeline accepts both ([`src/Http/Middleware/Pipeline.php:32`](../../src/Http/Middleware/Pipeline.php)). Class-string resolution requires `Pipeline::__construct(?ContainerInterface $container)` to have been passed a non-null container.

## Middleware priority / ordering

There is no priority table — the **registration order IS the order**. `Pipeline::compile()` ([`src/Http/Middleware/Pipeline.php:53`](../../src/Http/Middleware/Pipeline.php)) reverses the list once at request time, walks it on the way in, then unwinds on the way out. The compiled pipeline is cached and only rebuilt when you `pipe()` something new.

## Common pitfalls

> **Class-string pipe + no container.** `new Pipeline()` throws on the first class-string `pipe()` call at request time. Pass `$container` to the constructor.
> **`HttpsRedirectMiddleware` constructor throws.** It refuses to boot without an explicit `$trustedHosts` list — the default `Request::TRUSTED_HOSTS_DEFAULT` is consulted only by your `public/index.php`, not by the middleware.
> **CSRF exempting `/`.** `CsrfMiddleware` throws at construction if `exemptPrefixes === ['/']` — that would silently disable CSRF for the entire site.

## Next

- [Request / Response / Route value objects](value-objects.md) — the immutable VOs that flow through the pipeline.
- [Defense-in-depth checklist](security.md) — what every shipped middleware does and why.
- [DI container and reset semantics](container.md) — building factories for your own middlewares.
