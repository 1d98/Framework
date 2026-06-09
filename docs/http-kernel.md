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
    ) {}

    public function handle(Request $request): Response { ... }
}
```

The full source is [`src/Http/HttpKernel.php`](../../src/Http/HttpKernel.php) (93 lines). The contract is:

1. Build the pipeline (default: empty `new Pipeline($container)`).
2. Call `handle($request)`.
3. The pipeline runs the registered middlewares around the **core**, which calls `Router::match($request)` and invokes the matched handler.
4. Any uncaught `Throwable` reaches `RequestErrorRenderer` and becomes an `application/problem+json` response.
5. The response gets `X-Request-Id` set from `Request::$id`.

`HttpKernel` always builds a default `RequestErrorRenderer` and `RequestLogger` — pass your own to override.

## The pipeline

```php
namespace Framework\Http\Middleware;

final class Pipeline
{
    public function pipe(MiddlewareInterface|string $middleware): void;
    public function process(Request $request, callable $core): Response;
}
```

`Pipeline::pipe()` ([`src/Http/Middleware/Pipeline.php:32`](../../src/Http/Middleware/Pipeline.php)) accepts either a `MiddlewareInterface` instance **or a class-string** to be resolved through the container at request time. A class-string requires a non-null container on the pipeline — `new Pipeline()` with no container throws when it tries to resolve a class.

Order: the **first** piped middleware is the **outermost** shell. `pipe(A); pipe(B);` runs `A → B → core → B → A` on the way out.

## Middleware interface

`MiddlewareInterface::process(Request, callable): Response` ([`src/Http/Middleware/MiddlewareInterface.php:10`](../../src/Http/Middleware/MiddlewareInterface.php)). `$next($request)` invokes the next layer. Return the response from `next()` (possibly mutated) or short-circuit with a new `Response`. **Always return a `Response`** — the kernel never inspects nulls.

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
| `CorsMiddleware` | RFC-aware CORS with `Vary` header | `$origins`, `$methods`, `$headers`, `$credentials`, `$maxAge` |
| `FormBodyParser` | parses `application/x-www-form-urlencoded` | — |
| `HttpsRedirectMiddleware` | 301 → https in prod | `$statusCode` (301/308), `$trustedHosts` (required), `$trustedProxies` |
| `JsonBodyParser` | parses `application/json` | — |
| `MultipartBodyParser` | parses `multipart/form-data` + writes uploads to a tmp dir | `$tmpDir`, `$maxBodyBytes` |
| `RateLimitMiddleware` | per-key token bucket | `$capacity`, `$refillPerSecond`, `?Clock`, `?Closure` key extractor |
| `SecurityHeadersMiddleware` | CSP + HSTS + clickjacking/MIME guards | `$headers`, `$hstsMaxAge`, `$csp`, `$trustedProxies` |
| `CsrfMiddleware` | signed-cookie double-submit CSRF | `$jar`, `$exemptPrefixes`, `$exemptPaths`, `$logger`, `$trustedProxies` |

## Custom middleware

```php
namespace App\Http\Middleware;

use Framework\Http\Middleware\MiddlewareInterface;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $expectedToken,
    ) {}

    public function process(Request $request, callable $next): Response
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
