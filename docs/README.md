# Framework Documentation

The canonical reference for the framework. Each page is a real, runnable example — no stubs, no placeholders. If you spot something out of date, the source of truth is `src/`.

## Reading order

If you're new to the framework, read in this order:

1. **[Installation](installation.md)** — PHP 8.5, `composer install`, first request, `.env` setup, "Hello, Framework" in 30 lines.
2. **[Quick start — Web](quickstart-web.md)** — the real `public/index.php`, route patterns, path / query / body parameters, JSON / form / multipart parsers.
3. **[Quick start — CLI](quickstart-cli.md)** — `bin/framework`, the `make:*` scaffolders, and where to register the generated files.
4. **[HTTP kernel and middleware pipeline](http-kernel.md)** — pipeline order, the built-in middlewares (CSRF, CORS, compression, rate limit, security headers, HSTS, body parsers), how to write a custom middleware, DI patterns.
5. **[Validation: 3-tier pipeline](validation.md)** — `Rule` → `#[Validate]` DTO → `Validator`, every built-in rule, nested DTOs, `bind()` / `bindWith()`, 422 mapping.
6. **[Defense-in-depth checklist](security.md)** — CSRF, signed cookies, CSP nonces, HSTS, rate limit, body cap, OWASP A9 redaction, a worked `/login` example.
7. **[DI container and reset semantics](container.md)** — `set` / `bind` / autowire, per-instance vs. process-wide state, the "no service locator" rule.
8. **[Request / Response / Route value objects](value-objects.md)** — the immutable VOs that flow through the pipeline, `withX()` mutators, `StatusText`, `Vary`, `Cookie`, `SignedCookieJar`, `ResponseInterface`, `StreamedResponse`, `Sse`.
9. **[Streaming responses](streaming-response.md)** — SSE / NDJSON / large-file download end-to-end, deployment gotchas (PHP-FPM, nginx, Apache), PHPUnit testing recipes.
10. **[Configuration and environment variables](config.md)** — the env vars, `config:show`, what's configurable and what isn't (12-factor stance).

## Conventions

- `declare(strict_types=1);` in every file. The framework passes **PHPStan level max** on `src/` and `tests/`.
- Value objects are `final readonly class`. Every mutator returns a new instance.
- Tests are PHPUnit 11, with `failOnRisky` and `failOnWarning` enabled. The CI workflow runs `composer validate`, `composer install`, `composer check` on PHP 8.5 across Ubuntu, Windows, and macOS.
- The release process lives in [CONTRIBUTING.md](../../CONTRIBUTING.md).
- No `var/` or `vendor/` content is committed; both are gitignored. Uploaded files go to `var/tmp/`, which is created at runtime.

## File layout

| Path | What it is |
|---|---|
| [`bin/framework`](../../bin/framework) | CLI entry point. |
| [`public/index.php`](../../public/index.php) | Web entry point — the canonical demo. |
| [`src/`](../../src/) | Framework source, `Framework\` namespace. |
| [`tests/`](../../tests/) | PHPUnit unit + integration. |
| [`examples/`](../../examples/) | `full-app.php`, `auth-app.php` — runnable demos. |
| [`var/tmp/`](../../var/tmp) | Project-local upload tmp dir (gitignored). |
| `docs/` | You are here. |
| `composer.json` | `require: { php: ^8.5 }` — zero runtime deps. |
| `phpstan.neon` | PHPStan level max. |
| `.env.example` | Tracked template; copy to `.env` (gitignored). |

## Design proposals

Reference material for maintainers and security reviewers — not part of the linear
reading order. Each proposal describes an architectural change with trade-offs, open
questions, and an estimated scope; none have a release target until the maintainer (or
community) accepts the design.

- **[Security roadmap](design/security-roadmap.md)** — five architectural security risks
  identified by the v0.6.3-era audit that did not fit into a single small fix:
  `LogRedactor` framework primitive, `CorsMiddleware` permissive-echo opt-in,
  `Container::autowire` deny-list, `IdempotencyKeyMiddleware` 5xx replay policy, and
  boot warning for in-memory defaults in prod.

## See also

- [`src/`](../../src/) — the source.
- [`examples/full-app.php`](../../examples/full-app.php) — minimal app, ~50 lines.
- [`examples/auth-app.php`](../../examples/auth-app.php) — login, CSRF, signed cookies, DTO validation.
- [README](../../README.md) — the one-screen overview.
