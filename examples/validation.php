<?php

declare(strict_types=1);

/**
 * Validation pipeline demo: 3 DTO classes with #[Validate] attributes.
 *
 *     php -S 127.0.0.1:8765 examples/validation.php
 *
 * Then curl:
 *
 *     # Valid body → 201 with sanitized fields:
 *     curl -i -X POST -H "Content-Type: application/json" \
 *       -d '{"name":"alice","email":"a@x.io","age":30}' \
 *       http://127.0.0.1:8765/users
 *
 *     # Invalid body → 422 with errors[]:
 *     curl -i -X POST -H "Content-Type: application/json" \
 *       -d '{"name":"","email":"not-an-email","age":-1}' \
 *       http://127.0.0.1:8765/users
 *
 *     # Query-string DTO binding (?name=alice&email=x@y.io&age=42):
 *     curl -i 'http://127.0.0.1:8765/search?name=alice&email=x@y.io&age=42'
 */

namespace Framework\Examples;

require_once __DIR__ . '/../vendor/autoload.php';

use Framework\Container\Container;
use Framework\Http\HttpKernel;
use Framework\Http\Middleware\FormBodyParser;
use Framework\Http\Middleware\JsonBodyParser;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;
use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\MaxRule;
use Framework\Validation\Rule\MinRule;
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\Validator;

final readonly class CreateUserRequest
{
    public function __construct(
        #[Validate(['required', 'string', 'min:2', 'max:50'])]
        public ?string $name = null,
        #[Validate(['required', 'email'])]
        public ?string $email = null,
        #[Validate(['required', 'integer', new MinRule(min: 0), new MaxRule(max: 150)])]
        public ?int $age = null,
    ) {}
}

final readonly class SearchQuery
{
    public function __construct(
        // Query-string values are always strings, so we coerce them
        // to their target PHP types in the handler before binding.
        // The DTO's property types match the post-coercion shape.
        #[Validate(['required', 'string', 'min:1'])] public ?string $name = null,
        #[Validate(['required', 'email'])] public ?string $email = null,
        #[Validate(['required', 'integer', new MinRule(min: 0)])] public ?int $age = null,
    ) {}
}

$container = new Container();
$container->set(RuleRegistry::class, static fn(): RuleRegistry => new RuleRegistry());
$container->set(Validator::class, static fn(Container $c): Validator
    => new Validator($c->get(RuleRegistry::class)));

// Parsers hydrate $request->json() and $request->form() BEFORE the
// handler runs, so $r->bind() can find the data in the right place.
$pipeline = new Pipeline();
$pipeline->pipe(new JsonBodyParser());
$pipeline->pipe(new FormBodyParser());

$router = new Router();
$router->post('/users', static function (Request $r) {
    // $r->bind() reads #[Validate] attrs, resolves rules from
    // RuleRegistry, and returns a hydrated DTO. Throws
    // ValidationException → 422 application/problem+json.
    /** @var CreateUserRequest $u */
    $u = $r->bind(CreateUserRequest::class);
    return Response::json(['name' => $u->name, 'email' => $u->email, 'age' => $u->age], 201);
});
$router->get('/search', static function (Request $r) {
    // For GET /search, the data lives in the query string — pass it
    // explicitly via bindWith() so the binder runs the same #[Validate]
    // pipeline on it. Query strings are always strings, so we coerce
    // the `age` field to int before validation; the DTO's `?int $age`
    // then type-matches.
    $query = $r->query();
    $coerced = [
        'name' => is_string($query['name'] ?? null) ? $query['name'] : null,
        'email' => is_string($query['email'] ?? null) ? $query['email'] : null,
        'age' => isset($query['age']) && is_numeric($query['age'])
            ? (int) $query['age']
            : null,
    ];
    /** @var SearchQuery $q */
    $q = $r->bindWith($coerced, SearchQuery::class);
    return Response::json(['name' => $q->name, 'email' => $q->email, 'age' => $q->age]);
});

(new HttpKernel($router, $pipeline, $container))
    ->handle(Request::fromGlobals()->withValidator($container->get(Validator::class)))
    ->send();
