# Installation

What this is: the minimum steps to get the framework running on PHP 8.5.

## Requirements

- **PHP 8.5** or higher (`php -v` to check)
- **Composer 2.x** (`composer --version`)

The framework has **zero runtime dependencies** — `composer.json`'s `require` lists PHP only (`composer.json:12`). `phpunit` and `phpstan` are dev-only.

## Install

```bash
git clone <repo> framework
cd framework
composer install
```

`composer install` regenerates `vendor/autoload.php`, which `public/index.php` and `bin/framework` both require.

## First request

```bash
php -S localhost:8000 -t public
```

In another terminal:

```bash
curl -i http://localhost:8000/
```

Expected:

```http
HTTP/1.1 200 OK
Content-Type: text/html; charset=utf-8
...
<!DOCTYPE html>...<h1>Framework v0.5.5</h1>
```

Other demo routes wired in `public/index.php`: `/json`, `/hello/{name}`, `/api/v1/echo` (POST JSON), `/api/v1/form` (POST form), `/boom` (deliberate 404). See [Quick start — Web](quickstart-web.md) for the full table.

## .env setup

```bash
cp .env.example .env
```

Headline items (full reference in [Configuration](config.md)):

- `APP_ENV` — `dev` (default) or `prod`; `prod` activates `HttpsRedirectMiddleware`.
- `APP_DEBUG` — `1` exposes exception details; set `0` in prod.
- `APP_SECRET` — HMAC for `SignedCookieJar`. In prod, **32+ random bytes** (`openssl rand -hex 32`). The framework refuses the dev default at boot when `APP_ENV=prod`.
- `APP_TRUSTED_HOSTS` — host patterns `HttpsRedirectMiddleware` may reflect into `Location:`. Required in prod.

`Env::load()` (`src/Config/Env.php:18`) treats real env vars as authoritative — shell-set values override `.env`. Twelve-factor compliant.

## Hello, Framework — the minimal app

The canonical minimal example is a 30-line file that wires `Container → Router → HttpKernel → send()`. See [Quick start — Web](quickstart-web.md#a-complete-hello-name-example-in-20-lines) for the full example, and [`examples/full-app.php`](../../examples/full-app.php) for a more realistic wiring (JSON + form parsers, multiple routes, an `/api/v1` group).

## Common pitfalls

> **`composer install` fails on PHP < 8.5.** `composer.json` requires `"php": "^8.5"`.
> **`.env` changes have no effect.** `Env::load()` runs once. Restart `php -S`.
> **`/form` shows a 400 in dev.** Visit the page first to set the `csrf_token` cookie.

## Next

- [Quick start — Web](quickstart-web.md) — the real `public/index.php`, route patterns, body parsers.
- [Quick start — CLI](quickstart-cli.md) — `bin/framework`, the `make:*` scaffolders.
