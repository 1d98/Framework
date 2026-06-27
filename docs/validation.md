# Validation: 3-tier pipeline

What this is: the framework's `Rule → #[Validate] attribute → Validator → DTO` pipeline, every built-in rule, and how to extend it with custom rules and DTOs.

## The 3 tiers

| Tier | Lives in | Purpose |
|---|---|---|
| **1. Rule** | `src/Validation/Rule/RuleInterface` | Single check: `validate($value, $params): ?string` — error message or `null`. Reusable, registered in a `RuleRegistry`. |
| **2. DTO + `#[Validate]`** | `src/Validation/Attribute/Validate` | A `final readonly class` whose constructor parameters carry `#[Validate(...)]` attributes. The DTO is the **shape** of an inbound payload. |
| **3. Validator** | `src/Validation/Validator` | Walks the DTO's reflection, runs the rules, hydrates the DTO. `check()` collects errors; `validate()` collects and throws. |

A handler asks the validator for a populated DTO:

```php
use Framework\Http\Request\Request;
use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\EmailRule;
use Framework\Validation\Rule\MinRule;
use Framework\Validation\Rule\RequiredRule;

final readonly class CreateUserRequest
{
    public function __construct(
        #[Validate([new RequiredRule(), new EmailRule()])]
        public ?string $email = null,
        #[Validate([new RequiredRule(), 'string', new MinRule(min: 2)])]
        public ?string $name = null,
    ) {}
}

$router->post('/users', static function (Request $req): Response {
    /** @var CreateUserRequest $user */
    $user = $req->bind(CreateUserRequest::class);
    return Response::json(['email' => $user->email, 'name' => $user->name], 201);
});
```

On `bind()`, `Request` pulls JSON or form data (JSON priority), runs the rules, returns the DTO, or throws `ValidationException`. The kernel renders that as a 422 `application/problem+json` with the error list embedded.

## Built-in rules

Registered by `RuleRegistry::registerBuiltins()` ([`src/Validation/Rule/RuleRegistry.php:62`](../../src/Validation/Rule/RuleRegistry.php)):

| Name | What it checks |
|---|---|
| `required` | Rejects `null`, `''`, `[]`. |
| `string` / `integer` / `float` / `boolean` / `array` | Type-strict (`integer` rejects numeric strings). |
| `email` | Rejects what `filter_var(..., FILTER_VALIDATE_EMAIL)` rejects. |
| `url` / `uuid` | URL / UUID shape. |
| `regex` | Pattern check (params-driven). |
| `min` / `max` | `>= min` / `<= max` — number magnitude, string length, or array count. |
| `length` / `between` / `in` | Exact length, inclusive `[min, max]`, membership in a set. |

Rule instances carry their baked-in parameters: `new MinRule(min: 2)`, `new MaxRule(max: 50)`, `new InRule(values: ['draft', 'published', 'archived'])`.

## The shorthand DSL

`#[Validate]` accepts a `|`-separated string in addition to a list of rules:

```php
#[Validate('required|email|min:3|max:50')]
public ?string $email = null;
```

A bare class-string of a DTO in the same position is interpreted as a nested DTO recursion target (see "Nested DTOs" below).

## Defining a custom rule

```bash
php bin/framework make:rule Slug
# Created src/Validation/Rule/SlugRule.php
```

```php
namespace Framework\Validation\Rule;

final class SlugRule implements RuleInterface
{
    public function validate(mixed $value, array $params): ?string
    {
        if (!is_string($value) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
            return 'slug: must be lowercase letters, digits, and single hyphens';
        }
        return null;
    }

    public function name(): string { return 'slug'; }
    public function params(): array { return []; }
}
```

Register it before binding the `RuleRegistry`:

```php
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\Rule\SlugRule;

$container->set(RuleRegistry::class, static fn(): RuleRegistry => new RuleRegistry([
    'slug' => new SlugRule(),
]));
```

Now `#[Validate(['required', 'slug'])]` works.

## Unknown rules and the `UnresolvedRule` placeholder

The validator memoizes its parse of every `#[Validate]` attribute, including a parse of the rule names inside the attribute. If the rule is not registered at the moment the attribute is first parsed, the validator does **not** throw — it returns a stable placeholder `Framework\Validation\UnresolvedRule` instance that, on every call to `validate()`, reports the failure as a regular `ValidationError`:

```json
{ "field": "email", "rule": "unresolved", "message": "Validation rule \"foo\" is not registered" }
```

The placeholder is a real `RuleInterface`, so it integrates with the rest of the pipeline (error aggregation, dotted paths, `UnprocessableEntityHttpException`) without a special case. The placeholder is **excluded from the per-process parsed-rule cache**, so registering the missing rule and calling `Validator::clearCaches()` re-resolves it on the next `validate()`:

```php
$registry->register('slug', new SlugRule());
$validator->clearCaches(); // required in long-running workers
```

> **Long-running workers (Swoole / Octane / ReactPHP) that late-register rules MUST call `Validator::clearCaches()`** after `RuleRegistry::register()`. The per-process parsed-rule cache otherwise holds the prior `UnresolvedRule` placeholder for the lifetime of the worker and the new rule never fires. The cache is per-process; in classic PHP-FPM / CLI it is cleared automatically at the end of each request.

The same `UnresolvedRule` placeholder is used when the DSL has invalid syntax (e.g. `#[Validate('min:')]`) — the parser surfaces a clear `ValidationError` (rule: `unresolved`) instead of throwing `InvalidArgumentException` from inside the validator.

## Defining a DTO

```bash
php bin/framework make:dto CreateUser --suffix=Request
# Created src/Validation/Dto/CreateUserRequest.php
```

```php
namespace Framework\Validation\Dto;

use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\EmailRule;
use Framework\Validation\Rule\MaxRule;
use Framework\Validation\Rule\MinRule;
use Framework\Validation\Rule\RequiredRule;

final readonly class CreateUserRequest
{
    public function __construct(
        #[Validate([new RequiredRule(), 'string', new MinRule(min: 2), new MaxRule(max: 50)])]
        public ?string $name = null,
        #[Validate([new RequiredRule(), new EmailRule()])]
        public ?string $email = null,
        #[Validate([new RequiredRule(), 'integer', new MinRule(min: 0), new MaxRule(max: 150)])]
        public ?int $age = null,
    ) {}
}
```

You can mix a DSL string, a list of rules, and rule instances in the same attribute. The list form is preferred when a rule needs explicit construction (`new MinRule(min: 2)`) — the DSL parses `min:2` but the result is the same.

## Binding from a request

```php
$router->post('/api/v1/users', static function (Request $req): Response {
    /** @var CreateUserRequest $user */
    $user = $req->bind(CreateUserRequest::class);
    return Response::json(['id' => 1, 'name' => $user->name, 'email' => $user->email], 201);
});
```

`Request::bind()` ([`src/Http/Request/Request.php:590`](../../src/Http/Request/Request.php)) requires a `Validator` on the request — pass it via `withValidator()` in `public/index.php`:

```php
$request = Request::fromGlobals()->withValidator($container->get(Validator::class));
```

The binder is constructed lazily, **once per Request instance** — `bind()` inside a loop is allocation-free after the first call. `bindWith($data, Dto::class)` is the non-body variant for webhooks, queue messages, and CLI sources.

## Nested DTOs

Point `#[Validate]` at a DTO class-string to recurse: `#[Validate(AddressRequest::class)] public ?AddressRequest $address = null,`. Errors are reported with dotted paths: `address.country`. For lists, use `items:`: `#[Validate(items: AddressRequest::class)] public ?array $previousAddresses = null;` — errors at `previousAddresses.0.country`, etc.

## Error handling: 422 with the right shape

`ValidationException` is transport-agnostic. `RequestErrorRenderer` ([`src/Http/RequestErrorRenderer.php:20`](../../src/Http/RequestErrorRenderer.php)) catches it and calls `ValidationExceptionMapper::toHttpException()` ([`src/Http/ValidationExceptionMapper.php:19`](../../src/Http/ValidationExceptionMapper.php)) — the result is an `UnprocessableEntityHttpException` (422) with the error list as an RFC 7807 `errors` array:

```http
HTTP/1.1 422 Unprocessable Entity
Content-Type: application/problem+json

{
  "type": "about:blank",
  "title": "Unprocessable Entity",
  "status": 422,
  "errors": [
    { "field": "email", "rule": "email", "message": "Field must be a valid email" },
    { "field": "name",  "rule": "min",   "message": "Field must be at least 2" }
  ]
}
```

To override in a single handler, catch `ValidationException` **after** `bind()` and return your own response.

> **Since 0.6.3** the `RequestErrorRenderer` ctor defaults `redactTrace: true` ([`src/Http/RequestErrorRenderer.php:29`](../../src/Http/RequestErrorRenderer.php)) — a forgotten `$debug=true` in production no longer leaks stack frames to the public. To restore the old behaviour in development, build your own renderer with `new RequestErrorRenderer(debug: true, redactTrace: false)` and pass it to `HttpKernel`'s `$errorRenderer` arg. The `StructuredErrorRenderer` already had this knob and is unchanged. See the [HTTP kernel chapter](http-kernel.md#requesterrorrenderer-legacy-default).

## Common pitfalls

> **Forgetting `withValidator()`.** `$req->bind(...)` throws `LogicException('Validator not configured')` if the request wasn't wired. Always pass `withValidator($container->get(Validator::class))` in your entry point.

> **Integer rules reject numeric strings.** Type-strict. A JSON payload of `{"age": "30"}` fails `integer`. Fix the client, or use `string` + a custom numeric check.

> **`required` vs nullable.** `#[Validate([new RequiredRule()])]` on `?string` means "non-null AND non-empty". Drop `RequiredRule` to allow null.

> **Nested DTO must be an array.** An object value at the nested property reports a `type` error.

## Next

- [Defense-in-depth checklist](security.md) — body parsers, CSRF, and CSP working with validated DTOs.
- [HTTP kernel and middleware pipeline](http-kernel.md) — wiring `JsonBodyParser` and `FormBodyParser` in the pipeline.
