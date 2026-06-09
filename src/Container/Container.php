<?php

declare(strict_types=1);

namespace Framework\Container;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

final class Container implements ContainerInterface
{
    /**
     * Per-instance state vs. process-wide static state.
     *
     * The per-instance properties (`$factories`, `$bindings`, `$instances`,
     * `$resolving`) belong to one `Container` object. A second `Container`
     * constructed in the same process sees an empty set — its own
     * registrations and its own cached singletons — and never reads or
     * writes another instance's maps.
     *
     * The static properties (`$typeExistsCache`, `$reflectionCache`) are
     * `private static` and are therefore **shared across every
     * `Container` instance in the process**. They exist purely to make
     * `get()` / `has()` / `autowire()` cheap in long-running workers
     * (PHP-FPM, RoadRunner, Octane, Swoole, the console loop): PHP's
     * `class_exists` and `new ReflectionClass` lookups would otherwise
     * run on every cold resolution.
     *
     * Reset contract (R6):
     *
     * - `wipe()` is **per-instance only**. It clears `$instances` and
     *   `$resolving` — i.e. it is an alias for `reset()`. It does **not**
     *   touch the static caches. In a process that holds more than one
     *   container (a shared PHPUnit fixture, a long-running worker that
     *   builds a fresh container per request, an admin tool alongside the
     *   request container), calling `wipe()` on container B does not
     *   invalidate container A's memoization. This is the safe default:
     *   static caches are process-wide by design, and one container's
     *   teardown must not stall another container's hot path.
     * - `wipeGlobalCaches()` is **process-wide**. It clears the static
     *   caches on `Container` *and* delegates to
     *   `Validator::clearCaches()` if that class is loaded. Use it
     *   deliberately — e.g. between test runs that swap class
     *   definitions via runkit/uopz/eval, or as the rare explicit
     *   "invalidate the framework's memoized maps" hook.
     * - `Container::clearCaches()` is retained as an alias for
     *   `wipeGlobalCaches()` for back-compat with code written before
     *   the split. New code should prefer `wipeGlobalCaches()` because
     *   the name is explicit about the cross-instance scope.
     *
     * If two tenants (or two test suites) need hard isolation so that
     * neither one's `wipe()` affects the other, draw the boundary at the
     * process level — separate PHP-FPM pools, separate Octane/Swoole
     * worker processes, or `phpunit --process-isolation` for tests.
     *
     * @see self::$typeExistsCache
     * @see self::$reflectionCache
     * @see self::wipe()
     * @see self::wipeGlobalCaches()
     * @see self::clearCaches()
     */
    /**
     * @var array<string, Closure(ContainerInterface): mixed>
     */
    private array $factories = [];

    /**
     * @var array<string, class-string|Closure(ContainerInterface): mixed>
     */
    private array $bindings = [];

    /**
     * Per-instance: resolved singletons. Survives only as long as the
     * owning `Container`. Cleared by `forget()`, `reset()`, and `wipe()`.
     *
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * Per-instance: in-progress resolution stack used to detect circular
     * dependencies. Cleared by `reset()` and `wipe()`. The
     * `get()` failure path also clears the entry for the id it was
     * working on.
     *
     * @var array<string, true>
     */
    private array $resolving = [];

    /**
     * Process-wide memoization of type-existence lookups keyed by FQCN.
     *
     * **Shared across all `Container` instances in the process.** Because
     * the property is `private static`, every `Container` in this PHP
     * process reads and writes the same array. Cleared by
     * `wipeGlobalCaches()` (and the alias `clearCaches()`). Deliberately
     * **not** touched by `wipe()` — `wipe()` is per-instance, and one
     * container's teardown must not invalidate the memoization other
     * containers in the same process are still relying on.
     *
     * @var array<string, bool>
     */
    private static array $typeExistsCache = [];

    /**
     * Process-wide memoization of `ReflectionClass` instances keyed by FQCN.
     * The autowire path is the hot path (one reflection lookup per cold
     * `get()`), and in long-running workers that call `forget()`/`reset()`
     * between requests the class reflection is otherwise rebuilt every
     * time.
     *
     * **Shared across all `Container` instances in the process.** Because
     * the property is `private static`, every `Container` in this PHP
     * process reads and writes the same array. Cleared by
     * `wipeGlobalCaches()` (and the alias `clearCaches()`). Deliberately
     * **not** touched by `wipe()`, `reset()`, or `forget()` — reflected
     * class shape is process-invariant, so paying for it once per FQCN
     * is the right trade-off.
     *
     * @var array<class-string, ReflectionClass<object>>
     */
    private static array $reflectionCache = [];

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->resolving[$id])) {
            throw new ContainerException(sprintf(
                'Circular dependency detected while resolving "%s". Chain: %s',
                $id,
                implode(' -> ', [...array_keys($this->resolving), $id]),
            ));
        }

        $this->resolving[$id] = true;

        try {
            if (isset($this->factories[$id])) {
                $factory = $this->factories[$id];
                $instance = $factory($this);
            } elseif (isset($this->bindings[$id])) {
                $concrete = $this->bindings[$id];
                $instance = is_string($concrete)
                    ? $this->get($concrete)
                    : $concrete($this);
            } elseif (self::typeExists($id)) {
                $instance = $this->autowire($id);
            } else {
                throw new NotFoundException("Service not found: {$id}");
            }
        } finally {
            unset($this->resolving[$id]);
        }

        if (!is_object($instance)) {
            throw new ContainerException("Factory for {$id} must return an object");
        }

        $this->instances[$id] = $instance;

        return $instance;
    }

    public function has(string $id): bool
    {
        if (array_key_exists($id, $this->instances)) {
            return true;
        }

        if (isset($this->factories[$id])) {
            return true;
        }

        if (isset($this->bindings[$id])) {
            $concrete = $this->bindings[$id];

            if ($concrete instanceof Closure) {
                return true;
            }

            return self::typeExists($concrete);
        }

        return self::typeExists($id);
    }

    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function bind(string $abstract, Closure|string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
        unset($this->instances[$abstract]);
    }

    public function forget(string $id): void
    {
        unset($this->instances[$id]);
    }

    public function reset(): void
    {
        $this->instances = [];
        $this->resolving = [];
    }

    /**
     * Drop this instance's resolved singletons and the cycle-guard map.
     * Bindings and factories (the *configuration* of the container) are
     * preserved. This is an alias for `reset()` — the two methods are
     * kept distinct in the API so call sites can document intent
     * ("drop the runtime singletons" vs. "drop the configuration too"),
     * and so the static caches below can be cleared explicitly via
     * `wipeGlobalCaches()` without surprising other containers in the
     * same process.
     *
     * The Router's per-instance `$cachedRoutes` is intentionally NOT
     * touched: `wipe()` operates on the container's state, and the
     * router is a separate, independently-constructed service. If you
     * also need to flush route memoization, call `Router::getRoutes()`
     * (which rebuilds on its own) or instantiate a fresh router.
     */
    public function wipe(): void
    {
        $this->instances = [];
        $this->resolving = [];
    }

    /**
     * Drop the process-wide memoization owned by this class
     * (`$typeExistsCache`, `$reflectionCache`) and delegate to
     * `Validator::clearCaches()` if that class is loaded.
     *
     * This is **process-wide**: every `Container` instance in the PHP
     * process shares those maps, so calling `wipeGlobalCaches()` will
     * force the next `get()` / `has()` / `autowire()` on any container
     * to re-run `class_exists` and rebuild `ReflectionClass` for every
     * FQCN it has ever resolved. In a long-running worker (Octane,
     * Swoole, the console loop) this is a hot-path stall — reach for
     * it only when you have a real reason to invalidate the memoized
     * state (a test that swaps class definitions via runkit/uopz/eval,
     * or a deliberate cold-boot of the framework).
     *
     * For routine per-request teardown, prefer `reset()` (per-instance,
     * preserves the static caches) — that is the right default between
     * requests in a worker.
     */
    public static function wipeGlobalCaches(): void
    {
        self::$typeExistsCache = [];
        self::$reflectionCache = [];

        if (class_exists(\Framework\Validation\Validator::class)) {
            \Framework\Validation\Validator::clearCaches();
        }
    }

    /**
     * Alias for {@see self::wipeGlobalCaches()}. Retained for back-compat
     * with code written before `wipe()` and `wipeGlobalCaches()` were
     * split (R6). New code should prefer `wipeGlobalCaches()` because
     * the name is explicit about the cross-instance scope.
     */
    public static function clearCaches(): void
    {
        self::wipeGlobalCaches();
    }

    /**
     * @param string $class names a class, interface, trait, or enum. Non-class types are rejected
     *                        with a NotFoundException that points the caller at $container->bind().
     */
    private function autowire(string $class): object
    {
        if (interface_exists($class) || trait_exists($class) || enum_exists($class) || !class_exists($class)) {
            throw new NotFoundException(sprintf(
                'No binding for %s; bind a concrete implementation via $container->bind(%s, ...)',
                $class,
                $class,
            ));
        }

        if (!isset(self::$reflectionCache[$class])) {
            self::$reflectionCache[$class] = new ReflectionClass($class);
        }

        $reflection = self::$reflectionCache[$class];
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $args[] = $this->resolveParameter($param, $class);
        }

        /** @var object */
        return $reflection->newInstanceArgs($args);
    }

    /**
     * True when $fqcn names any userland type PHP can resolve: class, interface, trait, or enum.
     *
     * Result is memoized per-process to avoid re-hitting the language lookup in hot resolution paths.
     */
    private static function typeExists(string $fqcn): bool
    {
        if (!array_key_exists($fqcn, self::$typeExistsCache)) {
            self::$typeExistsCache[$fqcn] = class_exists($fqcn)
                || interface_exists($fqcn)
                || trait_exists($fqcn)
                || enum_exists($fqcn);
        }

        return self::$typeExistsCache[$fqcn];
    }

    private function resolveParameter(ReflectionParameter $param, string $class): mixed
    {
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();

            if ($param->allowsNull() && $param->isDefaultValueAvailable() && $param->getDefaultValue() === null) {
                return null;
            }

            if ($this->has($typeName)) {
                return $this->get($typeName);
            }

            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }

            throw new ContainerException(sprintf(
                'Cannot autowire parameter $%s of type %s in %s',
                $param->getName(),
                $typeName,
                $class,
            ));
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        throw new ContainerException(sprintf(
            'Cannot autowire parameter $%s in %s: no type hint and no default value',
            $param->getName(),
            $class,
        ));
    }
}
