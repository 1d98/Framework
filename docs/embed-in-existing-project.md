# Embedding the framework in an existing project

This page is for downstream maintainers who want to add the framework to an existing PHP application. You do not have to rewrite anything: you add the framework as one HTTP entry point alongside what you already have.

## Prerequisites

- PHP 8.5 or higher
- Composer 2.x
- An existing project with a `composer.json`

## 1. Install

```bash
composer require 1d98/framework
```

This adds `vendor/1d98/framework/` and exposes `vendor/bin/framework` for the CLI.

## 2. Wire up the web entry point

Pick a route prefix or a port for the framework to own. The cleanest pattern is to mount the framework at a sub-path under your existing web server (Apache/Nginx), but the simplest is a separate port.

Create `public/framework.php` (or wherever your web root is):

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Framework\Config\Env;
use Framework\Container\Container;
use Framework\Http\HttpKernel;
use Framework\Http\Middleware\CompressionMiddleware;
use Framework\Http\Middleware\HttpsRedirectMiddleware;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Middleware\SecurityHeadersMiddleware;
use Framework\Http\Request\Request;
use Framework\Http\Request\RequestFactory;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;

Env::load(__DIR__ . '/../.env');

$trustedHosts = array_values(array_filter(
    array_map('trim', explode(',', getenv('APP_TRUSTED_HOSTS') ?: '')),
    static fn(string $h): bool => $h !== '',
));
if ($trustedHosts === []) {
    $trustedHosts = Request::TRUSTED_HOSTS_DEFAULT;
}

$router = new Router();
$router->get('/hello/{name}', static fn (Request $r, array $p): Response
    => Response::text("Hello, {$p['name']}!"));

$pipeline = new Pipeline();
$pipeline->pipe(new HttpsRedirectMiddleware(trustedHosts: $trustedHosts));
$pipeline->pipe(new SecurityHeadersMiddleware());
$pipeline->pipe(new CompressionMiddleware());

$kernel = new HttpKernel(
    router: $router,
    pipeline: $pipeline,
    debug: getenv('APP_DEBUG') === '1',
);

$response = $kernel->handle(RequestFactory::fromGlobals());
$response->send();
```

## 3. Wire up Nginx (or Apache)

Point a `location` block at `public/framework.php`:

```nginx
location /api/ {
    try_files $uri /framework.php?$query_string;
}
```

Or run a side-by-side PHP-FPM pool on a different port.

## 4. Environment variables

Copy the framework's `.env.example` entries into your project's `.env`:

```bash
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$(openssl rand -hex 32)
APP_TRUSTED_HOSTS=api.your-domain.com
APP_UPLOAD_TMP_DIR=/var/tmp/your-app
```

`APP_SECRET` is required in production. The framework refuses to boot with the dev default.

## 5. Use the CLI

```bash
vendor/bin/framework list                    # see all commands
vendor/bin/framework make:controller Foo     # scaffold into ./src/Http/Controller/
vendor/bin/framework make:command SendEmail  # scaffold into ./src/Console/Command/
vendor/bin/framework make:middleware Auth    # scaffold into ./src/Http/Middleware/
vendor/bin/framework config:show             # dump resolved config
vendor/bin/framework routes:list             # list registered HTTP routes
```

The `make:*` commands write to the **current working directory's** conventional `src/...` paths. Run them from your project root.

## 6. What you keep

- Your existing database layer, ORM, queue, cache — the framework has no opinions on any of that.
- Your existing autoloader — the framework is PSR-4 under `Framework\`, your code stays in whatever namespace you have.
- Your existing deployment — `php-fpm` works as-is, no special build step.

## 7. What you give up

- Do not add the framework's `public/index.php` — it ships with demo routes you do not want exposed.
- Do not run `vendor/bin/framework` with `APP_ENV=dev` in production: the dev secret check is the only thing standing between you and a known-bad cookie signing key.

## Common pitfalls

- **The framework refuses to start in `prod` without `APP_SECRET`.** Generate with `openssl rand -hex 32` (or `vendor/bin/framework app:secret`) and pass it via your secrets manager.
- **`RequestFactory::fromGlobals()` reads `$_SERVER` directly.** If you have a custom SAPI (Laravel Octane, Swoole, RoadRunner), build a `Request` from a payload array instead and supply a `RequestHost` explicitly.
- **`HttpsRedirectMiddleware` defaults to `localhost`-only trusted hosts.** In production, set `APP_TRUSTED_HOSTS=your-domain.com` or the middleware will refuse to redirect.

## Next

- [security.md](security.md) — defense-in-depth checklist, CSP nonces, signed cookies
- [validation.md](validation.md) — the 3-tier validation pipeline
- [container.md](container.md) — DI wiring for your services
