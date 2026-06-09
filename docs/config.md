# Configuration and environment variables

What this is: where the framework reads configuration from, what's configurable, and what isn't.

## The 12-factor stance

The framework has **no file-based config** of its own. Everything that affects runtime behavior is read from environment variables, loaded through `Env::load()` ([`src/Config/Env.php`](../../src/Config/Env.php)):

```php
Env::load(__DIR__ . '/../.env');   // real env wins; .env is defaults
```

`Env::load()` is **12-factor compliant** — values already in `getenv()` / `$_ENV` override `.env` values. This means production deploys set env vars directly (Kubernetes `Secret`, AWS Parameter Store, systemd `EnvironmentFile=`) and never touch `.env`.

`Env::load()` is a no-op if the file does not exist — production can ship without a `.env` at all.

## Environment variables

The full set is documented in [`.env.example`](../../.env.example):

| Variable | Default | Purpose |
|---|---|---|
| `APP_ENV` | `dev` | `dev` (default) or `prod`. In `prod`, `HttpsRedirectMiddleware` is wired and `AppSecretValidator` rejects the dev default. |
| `APP_DEBUG` | `1` | `1` exposes exception details in `application/problem+json`; `0` hides them. Set to `0` in prod. |
| `APP_SECRET` | `dev-only-secret-change-in-prod` | HMAC secret for `SignedCookieJar` (CSRF tokens, signed cookies). In prod, set to **32+ random bytes** (`php bin/framework app:secret`). |
| `APP_UPLOAD_TMP_DIR` | `<project>/var/tmp` | Where `MultipartBodyParser` writes uploaded files. Override for sandboxed environments. |
| `APP_TRUSTED_HOSTS` | empty → `Request::TRUSTED_HOSTS_DEFAULT` (`localhost`, `127.0.0.1`, `*.localhost`) | Comma-separated host patterns `HttpsRedirectMiddleware` may reflect into `Location:`. Required in prod. |
| `APP_TRUSTED_PROXIES` | empty | Comma-separated CIDR / IP list the framework treats as a trusted reverse proxy for `X-Forwarded-Proto` and `X-Forwarded-For`. See `Request::TRUST_LOOPBACK` and `Request::TRUST_PRIVATE` for convenient pre-built lists. |

## `config:show`

```bash
php bin/framework config:show
# +-------+--------+
# | Key   | Value  |
# +-------+--------+
# | app   | {"env":"dev","version":"0.5.1"} |
# +-------+--------+
```

`ConfigShowCommand` ([`src/Console/Command/ConfigShowCommand.php`](../../src/Console/Command/ConfigShowCommand.php)) reads from `ConfigInterface` — the in-memory `Config` object built at CLI boot. By default this only contains `app.env` and `app.version` ([`bin/framework:25`](../../bin/framework)):

```php
$container->set(ConfigInterface::class, static fn(): ConfigInterface
    => Config::fromArray([
        'app' => [
            'env' => getenv('APP_ENV') ?: 'dev',
            'version' => \Framework\Framework::VERSION,
        ],
    ]));
```

`Config` ([`src/Config/Config.php`](../../src/Config/Config.php)) is `final readonly` and supports `with($overrides)` for safe, immutable copies:

```php
$base = Config::fromArray(['app' => ['env' => 'dev']]);
$prod = $base->with(['app' => ['env' => 'prod']]);
$base->get('app')['env'];   // 'dev'  — base unchanged
$prod->get('app')['env'];   // 'prod'
```

## What is and isn't configurable

### Configurable (per request / per boot)

- All `APP_*` env vars above.
- Middleware constructor arguments (e.g. `CorsMiddleware::origins`, `RateLimitMiddleware::capacity`). Wire them in `public/index.php` from env vars.
- `AppSecretValidator::assertProductionSafe()` — runs at boot, refuses the dev default in prod.

### Not configurable (deliberate)

- The router — routes live in `public/index.php` (or whatever you import there). There is no `config/routes.php` convention.
- The container — bindings and factories are declared in code, not in a config file.
- Validation rules — registered in code via `RuleRegistry`. Custom rules from a config file would break the autowire contract.
- The autowire path itself — class-string resolution is by `class_exists` + `ReflectionClass`. No class allowlist / denylist.

This is by design. The framework is **12-factor**: config is env, behavior is code. If a "config" is complex enough to need a file, it is complex enough to be a PHP class.

## Common pitfalls

> **`.env` overrides the real env.** It does not — `Env::load()` is non-overriding by default. To override, pass `true`: `Env::load($path, override: true)`. Never do this in production.

> **Config file is loaded twice.** `public/index.php` calls `Env::load()`; `bin/framework` calls `getenv()` directly. If you add a `Config` factory in your app that also calls `Env::load()`, you'll load the same file twice. It's idempotent (the second call sees the vars are already set), but it's wasteful.

> **Forgetting `APP_TRUSTED_HOSTS` in prod.** `HttpsRedirectMiddleware` throws at construction when the list is empty. Set `APP_TRUSTED_HOSTS=example.com,*.example.com` before booting in prod.

> **`config:show` shows your secrets.** It does not — the default wiring only contains `app.env` and `app.version`. If you add `APP_SECRET` to the `Config::fromArray(...)` call in `bin/framework`, it will show up here. Don't.

## Next

- [Defense-in-depth checklist](security.md) — every env var explained in its security context.
- [Installation](installation.md) — first-time `.env` setup.
