# Framework

A universal **zero-dependency** PHP 8.5 framework skeleton. Immutability by default, strict types everywhere, and PHPStan max from day one.

## Why?

Most PHP frameworks drag in a hundred transitive dependencies and lock you into abstractions. This one is the opposite: a thin, opinionated core with **no runtime dependencies**, written for the modern PHP 8.5 feature set.

- **Zero runtime deps.** Only `phpunit` and `phpstan` for dev.
- **Final readonly value objects** — no setters, no surprises.
- **PSR-4 autoloading** under the `Framework\` namespace.
- **PHPStan level max** passes on the entire codebase.
- **Immutable Config** with `with()` for safe overrides.
- **DI Container** with autowiring via reflection.
- **HTTP stack** with a tiny router, middleware pipeline, RFC 7807 errors, and exception-aware kernel.
- **Production-safe by default** — generic exception messages are hidden, opt in to debug mode to expose them.
- Test count is tracked by CI — see [the CI workflow](https://github.com/1d98/framework/actions/workflows/ci.yml) for the current number.

## Requirements

- **PHP 8.5** or higher
- **Composer 2.x**

## Installation

```bash
composer install
```

## Quick start (CLI)

```bash
php bin/framework
```

## Quick start (Web)

```bash
php -S localhost:8000 -t public
```

Set `APP_DEBUG=1` to enable debug mode (exposes exception messages and traces).

Then open <http://localhost:8000>. Try:

| URL | What it does |
|-----|--------------|
| `/` | HTML landing page |
| `/json` | JSON response echoing the request |
| `/hello/{name}` | Path parameter — try `/hello/world` |
| `/api/v1/users` | Grouped route (JSON list) |
| `/api/v1/echo` (POST) | JSON body parser — send `{"hello":"world"}` |
| `/api/v1/form` (POST) | Form body parser — send `name=Alice&age=30` |
| `/boom` | Throws `NotFoundHttpException` → 404 problem details |
| `/missing` | 404 problem details |

## Hello, World

```php
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;

$router = new Router();
$router->get('/hello/{name}', static fn(Request $r, array $p): Response
    => Response::text("Hello, {$p['name']}!"));
```

The router sorts routes by specificity (static beats `{param}`),
and patterns are compiled on `Route` construction.

## Project structure

```
.
├── bin/framework                 # CLI entry point
├── public/index.php              # Web entry point with demo routes
├── examples/full-app.php         # Echo + JSON example (minimal wiring)
├── examples/auth-app.php         # Login/CSRF/signed-cookie/DTO example (realistic)
├── src/                          # Framework source (Framework\ namespace)
├── tests/                        # PHPUnit tests (Unit + Integration)
├── var/tmp/                      # Project-local tmp dir (gitignored)
├── docs/                         # Reference documentation
├── .github/workflows/ci.yml      # GitHub Actions CI
├── composer.json
├── phpunit.xml
├── phpstan.neon
├── CONTRIBUTING.md
├── LICENSE
└── README.md
```

For the full module layout, see [docs/README.md](docs/README.md).

## Documentation

See [docs/README.md](docs/README.md) for the full index.

## Development

```bash
composer test    # run all tests
composer stan    # PHPStan level max
composer check   # both
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE)
