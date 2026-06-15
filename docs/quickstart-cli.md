# Quick start — CLI

What this is: the `bin/framework` dispatcher, every `make:*` scaffolder, and where to register the generated files.

## Running the CLI

```bash
php bin/framework
# Framework Console 0.5.4 — available commands:
#   list, config:show, routes:list, app:secret
#   make:command, make:controller, make:dto, make:exception,
#   make:middleware, make:rule
```

`--help` / `--version` give app-level help; `--ansi` / `--no-ansi` control color.

## `config:show`, `routes:list`, `app:secret`

```bash
php bin/framework config:show
# +-------+--------+
# | Key   | Value  |
# +-------+--------+
# | app   | {"env":"dev","version":"0.5.4"} |
# +-------+--------+

php bin/framework routes:list        # every (method, path) the default Router has
php bin/framework app:secret         # 32-byte hex APP_SECRET
```

`ConfigShowCommand` reads from `ConfigInterface`. The default wiring ([`bin/framework:25`](../../bin/framework)) only contains `app.env` and `app.version` — the framework is **12-factor**, so app config does not live in this container.

## `make:controller`

```bash
php bin/framework make:controller Home
# Created src/Http/Controller/HomeController.php
```

Generates a `final readonly class HomeController` with `index(Request): Response`. **Where to register it** in `public/index.php`:

```php
$container->set(HomeController::class, static fn(): HomeController => new HomeController());
$router->get('/', static fn(HomeController $c, Request $r): Response => $c->index($r));
```

The container resolves the controller lazily via reflection.

## `make:command`

```bash
php bin/framework make:command SendEmail
# Created src/Console/Command/SendEmailCommand.php
```

**Where to register it** in `bin/framework`: `use Framework\Console\Command\SendEmailCommand; ... $app->add(new SendEmailCommand($container));`. Read args with `$input->arg(1)` / `$input->option('name')` / `$input->flag('verbose')`. Write with `$output->success()` / `$output->info()` / `$output->danger()` / `$output->table()`. Return `0` for success.

## `make:rule`

```bash
php bin/framework make:rule Slug
# Created src/Validation/Rule/SlugRule.php
```

Generates `final class SlugRule implements RuleInterface` with a placeholder regex. Edit the body, then register it:

```php
$container->set(RuleRegistry::class, static fn(): RuleRegistry => new RuleRegistry([
    'slug' => new SlugRule(),
]));
```

Then in a DTO: `#[Validate(['required', 'slug'])] public ?string $handle = null,`. See [Validation: 3-tier pipeline](validation.md#built-in-rules) for the full rule list.

## `make:dto`

```bash
php bin/framework make:dto CreateUser --suffix=Request
# Created src/Validation/Dto/CreateUserRequest.php
```

`--suffix=Request` sets the default class suffix (the binder finds a typed DTO next to your route handler). The generated class is `final readonly` with a placeholder `string $example` property. DTOs are not registered in a container — they are bound at request time with `$request->bind(CreateUserRequest::class)`.

## `make:exception`

```bash
php bin/framework make:exception Conflict
```

> **Watch out:** the scaffolder refuses to generate a class whose name collides with a built-in. `make:exception Conflict` exits with: `A built-in Framework\Http\Exception\ConflictHttpException already exists for this name. Throw \Framework\Http\Exception\ConflictHttpException from your controller instead.` This prevents you from shadowing the framework's RFC-correct exception types.

If you need a custom one (a status code the framework doesn't ship, like 449 or 451):

```bash
php bin/framework make:exception Teapot --status=418 --message='I am a teapot'
# Created src/Http/Exception/TeapotException.php
```

The generated class is `final class TeapotException extends HttpException` with `parent::__construct(418, $message, 'about:blank', $previous)`. Throw it from a route handler the same way as any built-in. `RequestErrorRenderer` renders every `HttpException` subclass as RFC 7807 `application/problem+json` automatically.

## `make:middleware`

```bash
php bin/framework make:middleware Auth
# Created src/Http/Middleware/AuthMiddleware.php
```

Output is a stub `final class AuthMiddleware implements MiddlewareInterface`. See [HTTP kernel and middleware pipeline](http-kernel.md#custom-middleware) for the registration pattern.

## Common pitfalls

> **`make:dto` and `make:rule` write into `src/`.** If you have moved your application code outside the framework's PSR-4 root, edit `dtoDir` / `rulesDir` in `bin/framework`.
> **Newly generated classes are not autoloadable.** Run `composer dump-autoload` after adding a class in a new directory.
> **`--ansi` colors may be off.** TTY detection is automatic; use `--no-ansi` to strip them.

## Next

- [HTTP kernel and middleware pipeline](http-kernel.md) — wire the generated middleware.
- [Validation: 3-tier pipeline](validation.md) — bind the generated DTO.
- [Configuration and environment variables](config.md) — what `config:show` reads.
