<?php

declare(strict_types=1);

namespace Framework\Http\Router;

use Closure;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use InvalidArgumentException;

final class Route
{
    private const int SCORE_LITERAL = 2;
    private const int SCORE_PARAM = 1;
    private const int SCORE_WILDCARD = 0;

    /**
     * Mutable bag of derived state: lazy caches + registration index.
     * The memo itself is mutable so the lazy fields can be filled in
     * place on the first matching call (without re-allocating a fresh
     * `Route` on the hot path).
     *
     * Nullable: for routes that never reach a memo-using method
     * (most static routes — the Router short-circuits them via its
     * `METHOD|PATH` hash) the memo is `null` and is allocated
     * lazily on the first cacheable read ({@see self::memo()}). A
     * Router with 1000 static routes therefore allocates 0 memos
     * at registration time; only routes that actually get matched
     * pay the allocation. Dynamic routes (params / wildcards) still
     * get the memo eagerly because they always need it.
     *
     * Not readonly: the class is `final` (not `final readonly`) for
     * this single mutable property; every other public property is
     * explicitly `readonly` (see the constructor signature), so the
     * value-object surface stays immutable. The mutable `$memo`
     * property is the one place where lazy allocation needs to
     * write after construction.
     *
     * @var RouteMemo|null
     */
    private ?RouteMemo $memo;

    private readonly Closure $handler;

    /**
     * @var array<string, string> Per-parameter regex fragments, populated
     *                              only via `where()` which returns a new
     *                              `Route` (the original is immutable).
     */
    private array $constraints;

    /**
     * @param Closure|array{0: object|string, 1: string}|string $handler
     *        Closure, [object, method], [class, method], or function/method name.
     *        Static method form is `'Class::method'`; top-level functions are bare names.
     * @param array<string, string> $constraints
     *        Per-parameter regex fragments; the public default is `[]`.
     *        Internal callers (`where()`, `withPrefix()`) thread the
     *        current map through.
     * @param RouteMemo|null $memo Pre-built memo to attach (used by `where()` /
     *        `withPrefix()` to copy the parent's memo fields). Pass `null`
     *        to skip eager allocation — the memo is allocated lazily on
     *        the first cacheable read ({@see self::memo()}). The Router
     *        passes `null` for static routes that go through the
     *        `METHOD|PATH` hash and therefore never need a memo.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        Closure|array|string $handler,
        array $constraints = [],
        ?RouteMemo $memo = null,
    ) {
        if (!is_callable($handler)) {
            throw new InvalidArgumentException(sprintf(
                'Route handler for %s %s is not callable',
                $method,
                $path,
            ));
        }

        $this->handler = $handler instanceof Closure ? $handler : Closure::fromCallable($handler);
        $this->memo = $memo;
        $this->constraints = $constraints;
    }

    /**
     * @return array{handler: callable, params: array<string, string>}|null
     */
    public function matches(string $method, string $path): ?array
    {
        if ($this->getNormalizedMethod() !== strtoupper($method)) {
            return null;
        }

        if (preg_match($this->getCompiledPattern(), $path, $matches) !== 1) {
            return null;
        }

        /** @var array<string, string> $params */
        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return ['handler' => $this->handler, 'params' => $params];
    }

    public function pathMatches(string $path): bool
    {
        return preg_match($this->getCompiledPattern(), $path) === 1;
    }

    /**
     * Per-segment specificity scores (literal=2, param=1, wildcard=0).
     * The router compares these lexicographically descending, with longer
     * tuples winning on tie to favour more deeply specified paths.
     *
     * @return list<int>
     */
    public function specificity(): array
    {
        $memo = $this->memo();
        if ($memo->specificityCache !== null) {
            return $memo->specificityCache;
        }

        $scores = [];
        foreach (explode('/', trim($this->path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }
            $scores[] = $this->scoreSegment($segment);
        }

        $memo->specificityCache = $scores;
        return $scores;
    }

    /**
     * True when the path has no parameters and no wildcards, i.e. an exact
     * literal string match. The router uses this to short-circuit lookups
     * via its static index.
     *
     * When the memo is `null` (route never warmed up, no caches filled),
     * the check is performed inline without allocating a memo just to
     * cache a one-shot boolean — the cost of the single `preg_match` is
     * negligible and the caller is on the registration path where
     * we explicitly want zero allocations for static routes.
     */
    public function isStatic(): bool
    {
        if ($this->memo === null) {
            return self::isStaticPath($this->path);
        }

        if ($this->memo->staticCache !== null) {
            return $this->memo->staticCache;
        }

        $this->memo->staticCache = self::isStaticPath($this->path);
        return $this->memo->staticCache;
    }

    /**
     * Pure path-shape check: `true` when `$path` contains no `{` parameter
     * delimiter and no `*` wildcard, i.e. it can be matched by a literal
     * string comparison. (A standalone `}` is not detected — the spec
     * anchors on the opening `{`, which always appears paired with a `}`
     * in well-formed routes.)
     *
     * Single source of truth for static-path detection — both `Route::isStatic()`
     * (on the warm/memo path) and `Router::add()` / `Router::register()` (on
     * the allocation-free registration path) call this method instead of
     * re-embedding the regex. The check itself is a 5-byte character-class
     * `preg_match`, allocation-free, and safe to run on every registration.
     *
     * The empty string is considered static: there is no `{` or `*` in it,
     * and any router-side handling of the empty path is the router's job,
     * not the path-shape classifier's.
     */
    public static function isStaticPath(string $path): bool
    {
        return preg_match('/[{*]/', $path) === 0;
    }

    /**
     * Constrain a path parameter to a regex fragment (without delimiters).
     * Returns a new `Route` with the constraint installed and the lazy
     * caches invalidated — the constraint map lives on the returned
     * instance, not on `$this`.
     */
    public function where(string $param, string $regex): self
    {
        $new = $this->constraints;
        $new[$param] = $regex;
        $currentOrder = $this->memo !== null ? $this->memo->registrationOrder : 0;
        return new self(
            $this->method,
            $this->path,
            $this->handler,
            $new,
            new RouteMemo(registrationOrder: $currentOrder),
        );
    }

    /**
     * @return Closure(Request, array<string, string>): Response
     */
    public function handler(): Closure
    {
        return $this->handler;
    }

    public function getNormalizedMethod(): string
    {
        $memo = $this->memo();
        if ($memo->normalizedMethod === null) {
            $memo->normalizedMethod = strtoupper($this->method);
        }

        return $memo->normalizedMethod;
    }

    /**
     * Internal: Router assigns a monotonic index to each route on `add()` so
     * the sorted output can break specificity ties by registration order.
     * Returns a new `Route` with the order stamped in — the receiver
     * (Router) is the only caller and uses the returned instance in
     * place of the original.
     */
    public function withRegistrationOrder(int $order): self
    {
        $current = $this->memo;
        $compiled = $current !== null ? $current->compiledPattern : null;
        $normalized = $current !== null ? $current->normalizedMethod : null;
        $static = $current !== null ? $current->staticCache : null;
        $specificity = $current !== null ? $current->specificityCache : null;
        return new self(
            $this->method,
            $this->path,
            $this->handler,
            $this->constraints,
            new RouteMemo(
                compiledPattern: $compiled,
                normalizedMethod: $normalized,
                staticCache: $static,
                specificityCache: $specificity,
                registrationOrder: $order,
            ),
        );
    }

    /**
     * Force the lazy caches (`compiledPattern`, `staticCache`,
     * `specificityCache`) to be built now instead of on the first
     * `match()`. Called by the Router at registration time so that the
     * first request against a route table does not pay a compile spike
     * — relevant for grouped routes (which the prefix stack registers
     * in a tight loop) and for any precomputed route set served from
     * a cache.
     */
    public function warmupCaches(): void
    {
        $this->getCompiledPattern();
        $this->isStatic();
        $this->specificity();
    }

    public function registrationOrder(): int
    {
        return $this->memo !== null ? $this->memo->registrationOrder : 0;
    }

    /**
     * Return a new route with `$prefix` prepended to its path. The
     * original instance is not mutated; all path-dependent memo
     * fields (`compiledPattern`, `staticCache`, `specificityCache`)
     * and the method-dependent `normalizedMethod` are reset on the
     * clone — `staticCache` and `specificityCache` are passed as
     * explicit `null` so the invalidation is visible at the call
     * site rather than relying on `RouteMemo`'s default arguments.
     * The new pattern is compiled immediately so the first `match()`
     * on the clone is O(1); the registration order is preserved
     * through the prefix change.
     *
     * @return self A new route instance with the same method, handler,
     *     constraints, and registration order; the path is `$prefix . path`.
     */
    public function withPrefix(string $prefix): self
    {
        $newPath = $prefix . $this->path;
        $currentOrder = $this->memo !== null ? $this->memo->registrationOrder : 0;
        return new self(
            $this->method,
            $newPath,
            $this->handler,
            $this->constraints,
            new RouteMemo(
                compiledPattern: $this->compile($newPath, $this->constraints),
                staticCache: null,
                specificityCache: null,
                registrationOrder: $currentOrder,
            ),
        );
    }

    private function scoreSegment(string $segment): int
    {
        if (str_contains($segment, '*')) {
            return self::SCORE_WILDCARD;
        }
        if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
            return self::SCORE_PARAM;
        }
        return self::SCORE_LITERAL;
    }

    private function getCompiledPattern(): string
    {
        $memo = $this->memo();
        if ($memo->compiledPattern === null) {
            $memo->compiledPattern = $this->compile($this->path, $this->constraints);
        }

        return $memo->compiledPattern;
    }

    /**
     * Lazy accessor for the mutable memo bag. On the first call from a
     * route that was constructed with `memo: null` (the static-route
     * zero-alloc path), this allocates the memo and stashes it; every
     * subsequent call returns the same instance. Routes constructed
     * with a non-null memo (the dynamic / user-supplied path) hit the
     * `??=` branch exactly once and otherwise reuse the pre-installed
     * instance.
     *
     * Callers MUST be ready to receive a fresh `RouteMemo` on the
     * first call; the returned object is mutable and shared, so
     * writing into it is the supported way to fill the lazy caches
     * in place.
     *
     * Public (not `private`) so tests can verify the lazy-allocation
     * contract: the same instance must come back on every call after
     * the first, even when the constructor took `null`.
     */
    public function memo(): RouteMemo
    {
        return $this->memo ??= new RouteMemo();
    }

    /**
     * Non-allocating read-only view of the memo. Returns `null` when
     * the route was constructed with `memo: null` AND no method that
     * allocates the memo has been called yet; returns the live memo
     * otherwise. Intended for diagnostics / tests that want to
     * distinguish the "still unallocated" state from the "allocated
     * but empty" state without forcing allocation.
     */
    public function getMemo(): ?RouteMemo
    {
        return $this->memo;
    }

    /**
     * @param array<string, string> $constraints
     */
    private function compile(string $path, array $constraints): string
    {
        $regex = preg_quote($path, '#');
        $regex = preg_replace_callback(
            '/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}/',
            static function (array $m) use ($constraints): string {
                $name = $m[1];
                $pattern = $constraints[$name] ?? '[^/]+';
                return '(?P<' . $name . '>' . $pattern . ')';
            },
            $regex,
        );

        return '#^' . $regex . '$#';
    }
}
