# Quick start — Web

What this is: a walkthrough of `public/index.php` — routes, path/query parameters, body parsers, JSON, redirects — line by line.

## The web entry point

```bash
php -S localhost:8000 -t public
```

`public/` is the document root. `public/index.php` ([`public/index.php`](../../public/index.php)) is the canonical demo.

## Defining a route

```php
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;

$router = new Router();
$router->get('/hello/{name}', static fn(Request $r, array $p): Response
    => Response::text("Hello, {$p['name']}!"));
$router->get('/users/{id}/posts/{postId}', static fn(Request $r, array $p): Response
    => Response::json(['user' => $p['id'], 'post' => $p['postId']]));
```

```bash
curl http://localhost:8000/hello/world        # Hello, world!
curl http://localhost:8000/users/42/posts/9    # {"user":"42","post":"9"}
```

`$p` is the array of path parameters, parsed from `{name}` segments. The router sorts dynamic routes by specificity — a literal `/users` always beats `/users/{id}` — and serves a `405 Method Not Allowed` with an `Allow:` header when the path exists for a different verb. Path parameters are always **strings**.

> **Heads up:** the second handler argument is **not** `$request->arg('name')` (`arg()` is for **query-string** parameters). Path parameters are always the second closure argument.

## Query parameters

`Request::query()` parses the raw query string via `SafeParseStr` (caps nesting and key counts to prevent DoS). Each value is `string|list<string>`:

```php
$router->get('/search', static function (Request $r): Response {
    $params = $r->query();
    $term = is_string($params['q'] ?? null) ? $params['q'] : '';
    $tags = $params['tag'] ?? [];
    return Response::json(['q' => $term, 'tags' => $tags]);
});
```

```bash
curl 'http://localhost:8000/search?q=php&tag=web&tag=framework'
# {"q":"php","tags":["web","framework"]}
```

## Request method dispatch

```php
$router->get('/users',         static fn() => /* list   */);
$router->post('/users',        static fn() => /* create */);
$router->put('/users/{id}',    static fn(Request $r, array $p) => /* update */);
$router->patch('/users/{id}',  static fn(Request $r, array $p) => /* patch  */);
$router->delete('/users/{id}', static fn(Request $r, array $p) => /* delete */);
```

`Router::get`/`post`/`put`/`patch`/`delete` ([`src/Http/Router/Router.php:98`](../../src/Http/Router/Router.php)) are the built-in shortcuts. The router is **strict by default** — registering the same `(method, path)` twice throws.

## Returning text, HTML, JSON, redirects, errors

`Response` ([`src/Http/Response/Response.php`](../../src/Http/Response/Response.php)) is `final readonly`. Use the static factories:

```php
Response::text('plain text');                  // 200 text/plain
Response::html('<h1>Hi</h1>');                  // 200 text/html
Response::json(['users' => []]);                // 200 application/json
Response::json(['created' => true], 201);       // any status
Response::empty(204);                           // empty body, default 204
Response::redirect('/login', 302);              // 302 with Location
```

`Response::redirect` accepts only redirect codes (`301, 302, 303, 307, 308`). For an error response, throw an `HttpException` subclass — `NotFoundHttpException`, `BadRequestHttpException`, etc. `HttpKernel::handle()` catches every `Throwable` and renders it as `application/problem+json` (RFC 7807) via `RequestErrorRenderer`. In `APP_DEBUG=0` mode, the message is hidden from the wire.

## Reading the request body

`public/index.php` pipes three body parsers — they run **before** the handler and decorate `Request` with a parsed shape:

| Middleware | Content-Type | Method | Populates |
|---|---|---|---|
| `JsonBodyParser` | `application/json` | any | `Request::$json` (mixed) |
| `FormBodyParser` | `application/x-www-form-urlencoded` | POST/PUT/PATCH | `Request::$form` (array) |
| `MultipartBodyParser` | `multipart/form-data` | POST/PUT/PATCH | `Request::$form` + `Request::$files` |

```php
$r->post('/echo', static function (Request $req): Response {
    return Response::json(['received' => $req->json()]);    // invalid JSON → 400
});
$r->post('/form', static function (Request $req): Response {
    return Response::json(['received' => $req->form() ?? []]);
});
$r->post('/upload', static function (Request $req): Response {
    return Response::json(['files' => $req->files() ?? []]);
});
```

See [`MultipartBodyParser`](../../src/Http/Middleware/MultipartBodyParser.php) and the [validation chapter](validation.md) for the `UploadedFile` value object.

## A complete "Hello, name!" example in 20 lines

Drop this into a fresh `public/index.php` (the canonical 20-line wiring):

```php
<?php

declare(strict_types=1);

use Framework\Container\Container;
use Framework\Http\HttpKernel;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;
use Framework\Logging\LoggerInterface;
use Framework\Logging\StreamLogger;

require __DIR__ . '/../vendor/autoload.php';

$container = new Container();
$container->set(LoggerInterface::class, static fn(): LoggerInterface => StreamLogger::stderr());

$router = new Router();
$router->get('/hello/{name}', static fn(Request $r, array $p): Response
    => Response::text("Hello, {$p['name']}!"));

(new HttpKernel($router, new Pipeline($container), $container))
    ->handle(Request::fromGlobals())
    ->send();
```

## Common pitfalls

> **Two `GET` routes for the same path.** The default `Router` throws on duplicates. Use `new Router(strict: false)` to allow first-wins.
> **No route matches but path exists for another method.** Kernel returns 405 with an `Allow:` header. Inspect it before changing your client.
> **Body is empty in the handler.** A parser middleware must be in the pipeline — see [HTTP kernel and middleware pipeline](http-kernel.md).
> **Request body too large.** Default cap is 10 MiB (`Request::MAX_BODY_BYTES`); 100-MiB uploads are rejected with 413 before any middleware runs.

## Next

- [Quick start — CLI](quickstart-cli.md) — `bin/framework` and the `make:*` scaffolders.
- [HTTP kernel and middleware pipeline](http-kernel.md) — pipeline order, custom middlewares, DI.
- [Validation: 3-tier pipeline](validation.md) — DTO + `#[Validate]` for `/api/v1/users`.
