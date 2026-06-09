# DI container and reset semantics

What this is: the framework's `Container` — factories, bindings, autowiring — and the per-instance / process-wide state that long-running workers must understand.

## Container

`Container` ([`src/Container/Container.php`](../../src/Container/Container.php)) implements `ContainerInterface` ([`src/Container/ContainerInterface.php`](../../src/Container/ContainerInterface.php)). Two methods resolve, two register, two clear:

```php
$container = new Container();

$container->get(string $id): mixed;        // resolve; autowire class-strings via reflection
$container->has(string $id): bool;         // binding or factory or type exists
$container->set(string $id, Closure $factory): void;       // always-cache (singleton factory)
$container->bind(string $abstract, Closure|string $concrete): void;  // alias / rebind
$container->forget(string $id): void;      // drop a single resolved singleton
$container->reset(): void;                 // drop ALL resolved singletons + cycle-guard
$container->wipe(): void;                  // alias of reset()
$container->wipeGlobalCaches(): void;      // process-wide: drops static memoization
$container::clearCaches(): void;           // alias of wipeGlobalCaches()
```

## Binding: `set` (factory) vs. `bind` (alias)

`set` registers a **factory** — every `get()` after the first returns the **same** cached instance:

```php
$container->set(LoggerInterface::class, static fn(): LoggerInterface => StreamLogger::stderr());
$logger = $container->get(LoggerInterface::class);  // calls factory once, caches
$same   = $container->get(LoggerInterface::class);  // returns the cached instance
```

`bind` registers an **alias** — when the abstract is requested, the concrete is resolved (recursively) and cached:

```php
$container->bind(App\Logger::class, LoggerInterface::class);  // App\Logger ← LoggerInterface
```

`bind` also accepts a class-string concrete, in which case the container **autowires** it on first resolution:

```php
$container->bind(\App\Http\Controller\HomeController::class, \App\Http\Controller\HomeController::class);
$controller = $container->get(\App\Http\Controller\HomeController::class);
```

The autowire path uses reflection on the constructor, resolves each typed parameter through `Container::get()`, falls back to default values when present, and throws `ContainerException` if an unresolvable typed parameter is encountered.

## Singleton vs. factory

A `set()`-registered factory is **lazy and singleton by default** — first `get()` runs the factory, every subsequent `get()` returns the same instance. There is no "factory that re-runs" mode; if you need a fresh instance per call, build a new `Container` for the call (or use a builder pattern in user code).

## Autowiring via reflection

```php
final class HomeController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RuleRegistry $registry,
    ) {}
}

$container->set(LoggerInterface::class, static fn(): LoggerInterface => StreamLogger::stderr());
$container->set(RuleRegistry::class,   static fn(): RuleRegistry => new RuleRegistry());
$container->set(HomeController::class, static fn(Container $c): HomeController
    => new HomeController($c->get(LoggerInterface::class), $c->get(RuleRegistry::class)));
```

The autowire path ([`src/Container/Container.php:281`](../../src/Container/Container.php)) does the same thing automatically — if you don't pass a factory, the container reflects on the class and resolves every typed constructor parameter via `get()`. The only time you need an explicit `set()` is when you want to **choose** the implementation (an interface, a config-driven concrete) rather than the autowired default.

Reflection results are memoized in `Container::$reflectionCache` (a **static** property) — see "Reset semantics" below.

## Reset semantics — per-instance vs. process-wide

This is the part that bites long-running workers (Octane, Swoole, the console loop). The container holds **two** kinds of state:

| State | Where | Cleared by |
|---|---|---|
| Factories, bindings | instance props (`$factories`, `$bindings`) | not cleared by `wipe()` — only by `set` / `bind` overwriting the key |
| Resolved singletons | instance prop (`$instances`) | `wipe()` / `reset()` / `forget()` |
| Cycle-guard map | instance prop (`$resolving`) | `wipe()` / `reset()` / cycle resolution failure |
| Class-existence lookups | **static** prop (`$typeExistsCache`) | `wipeGlobalCaches()` only |
| `ReflectionClass` cache | **static** prop (`$reflectionCache`) | `wipeGlobalCaches()` only |

So:

- **`wipe()` is per-instance.** Two `Container` objects in the same process each hold their own `$instances`. Wiping one does not touch the other.
- **`wipeGlobalCaches()` is process-wide.** It clears the static memoization on `Container` *and* delegates to `Validator::clearCaches()`. Calling it stalls the next `get()` on **every** container in the process — reach for it deliberately.
- **`clearCaches()` is an alias of `wipeGlobalCaches()`** kept for back-compat.

Between requests in a worker, `reset()` (or `wipe()`) is the right call. It drops the resolved singletons, so the next request rebuilds the per-request service graph, while keeping the static reflection cache warm.

## The "no service locator" rule

The container is for **wiring**, not for **lookup** at request time. Pass dependencies explicitly through constructors. Do not call `$container->get(SomeService::class)` from inside a route handler:

```php
// BAD — hidden dependency, untestable handler
$router->get('/users', static function (Request $r) use ($container) {
    $repo = $container->get(UserRepository::class);
    return Response::json($repo->all());
});

// GOOD — explicit dependency
$router->get('/users', static function (Request $r, UserRepository $repo) {
    return Response::json($repo->all());
});
```

`$repo` is resolved by the **router's own autowire path** when the handler is invoked through a controller pattern, or by an explicit factory binding you write for free functions. The framework does not look up services from the container mid-request — the container is constructed once at boot, its configuration (factories + bindings) is the application graph.

The only legitimate mid-request container reads are:

1. The `Pipeline` resolving a class-string middleware at request time ([`src/Http/Middleware/Pipeline.php:71`](../../src/Http/Middleware/Pipeline.php)).
2. The `RequestBinder` resolving a `Validator` (passed in at boot, not fetched at request time).
3. Test code that needs to swap an implementation between requests.

## Common pitfalls

> **Circular dependency.** Throws `ContainerException` with the chain (e.g. `Circular dependency detected while resolving "A". Chain: A -> B -> A`). The cycle-guard map is per-instance; rebuilding the container clears it.

> **Cannot autowire a primitive.** The autowire path refuses scalar / union / untyped parameters without a default value: `Cannot autowire parameter $name in App\X: no type hint and no default value`. Use a factory.

> **Interface with no binding.** Calling `get(SomeInterface::class)` when only an implementation class exists throws `NotFoundException` pointing at `bind()`. The container cannot pick a concrete for an interface without a hint.

> **Wiping the wrong container.** In a worker holding two containers (e.g. one for the request, one for an admin tool), `wipe()` on the admin container does not affect the request container. To invalidate both, call `wipeGlobalCaches()` — but only when you really want the reflection cache cold.

> **`clearCaches()` vs `wipe()`.** The names overlap. Prefer `wipeGlobalCaches()` for the static caches and `wipe()` (or `reset()`) for per-request state. `clearCaches()` is the legacy alias.

## Next

- [HTTP kernel and middleware pipeline](http-kernel.md) — wiring middleware factories in `public/index.php`.
- [Quick start — CLI](quickstart-cli.md) — `bin/framework` and the container it builds.
