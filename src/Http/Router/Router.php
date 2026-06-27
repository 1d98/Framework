<?php

declare(strict_types=1);

namespace Framework\Http\Router;

use Closure;
use Framework\Http\Exception\MethodNotAllowedHttpException;
use Framework\Http\Request\Request;
use Framework\Http\Response\ResponseInterface;

final class Router implements RouterInterface
{
    /**
     * Reject duplicate `(method, path)` registrations in `add()`. When false,
     * the first registration wins and later duplicates are silently dropped.
     * Read-only per instance; use {@see withStrict()} to opt out.
     */
    private readonly bool $strict;

    private int $staticHitCount = 0;

    private int $regexCallCount = 0;

    /** @var list<Route> Dynamic (param/wildcard) routes; sorted lazily by specificity. */
    private array $routes = [];

    /** @var array<string, Route> METHOD|PATH -> Route for static routes. */
    private array $staticIndex = [];

    /** @var array<string, list<string>> PATH -> list<uppercased METHOD> for fast 405 lookup. */
    private array $staticMethodsByPath = [];

    private int $registrationCounter = 0;
    private bool $sorted = false;

    /**
     * Stack of group prefixes currently in scope. Routes registered while
     * the stack is non-empty are prefixed with the joined stack at
     * registration time, so `group()` is O(1) per route (no re-`add()`,
     * no `withPrefix()`, no `getRoutes()` cache invalidation in a loop).
     *
     * @var list<string>
     */
    private array $prefixStack = [];

    /**
     * Bumped on every successful `add()`; alone it forms the cache key for
     * `getRoutes()`. Counts are intentionally NOT folded in: under the current
     * `add()`-only mutation contract, they are fully determined by this counter,
     * so they would add no invalidation signal and only cost false misses when
     * a cloned router (see `withStrict()`) is mutated in parallel with the parent.
     */
    private int $cacheVersion = 0;

    /** @var list<Route>|null Cached result of the most recent `getRoutes()` call. */
    private ?array $cachedRoutes = null;

    /** Cache key from the last `getRoutes()` computation; null when uninitialized. */
    private ?int $cachedKey = null;

    public function __construct(bool $strict = true)
    {
        $this->strict = $strict;
    }

    public function add(Route $route): void
    {
        $key = strtoupper($route->method) . '|' . $route->path;

        if (isset($this->staticIndex[$key])) {
            if ($this->strict) {
                throw new RouterException(sprintf(
                    'Duplicate route registration: %s %s (also registered earlier)',
                    strtoupper($route->method),
                    $route->path,
                ));
            }
            return;
        }

        $isStatic = Route::isStaticPath($route->path);

        if ($isStatic) {
            $this->staticIndex[$key] = $route;
            $this->staticMethodsByPath[$route->path][] = strtoupper($route->method);
            $this->registrationCounter++;
            $this->cacheVersion++;
            return;
        }

        $route = $route->withRegistrationOrder($this->registrationCounter++);
        $this->routes[] = $route;
        $this->sorted = false;
        $this->cacheVersion++;
    }

    public function get(string $path, callable $handler): Route
    {
        return $this->register('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): Route
    {
        return $this->register('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): Route
    {
        return $this->register('PUT', $path, $handler);
    }

    public function delete(string $path, callable $handler): Route
    {
        return $this->register('DELETE', $path, $handler);
    }

    public function patch(string $path, callable $handler): Route
    {
        return $this->register('PATCH', $path, $handler);
    }

    public function match(Request $request): array
    {
        $this->sortRoutes();

        $method = strtoupper($request->method);
        $path = $request->path;

        $staticKey = $method . '|' . $path;
        if (isset($this->staticIndex[$staticKey])) {
            $this->staticHitCount++;
            $route = $this->staticIndex[$staticKey];
            return ['handler' => $route->handler(), 'params' => []];
        }

        foreach ($this->routes as $route) {
            if ($route->isStatic()) {
                continue;
            }
            $this->regexCallCount++;
            $result = $route->matches($request->method, $path);
            if ($result !== null) {
                return $result;
            }
        }

        $allowedMethods = $this->collectAllowedMethods($path, $method);

        if ($allowedMethods !== []) {
            throw new MethodNotAllowedHttpException(
                "No route matches {$request->method} {$path}",
                null,
                $allowedMethods,
                ['Allow' => implode(', ', $allowedMethods)],
            );
        }

        throw new RouteNotFoundException(
            "No route matches {$request->method} {$path}",
        );
    }

    /**
     * Return all registered routes, ordered by specificity (most specific first,
     * registration order as tiebreaker).
     *
     * Cached. Invalidated on every `add()`. Cloned routers (via `withStrict()`)
     * have independent caches: each clone bumps its own `cacheVersion`, so the
     * parent and the clone never share a cache slot.
     *
     * @return list<Route>
     */
    public function getRoutes(): array
    {
        $key = $this->cacheVersion;

        if ($this->cachedRoutes !== null && $this->cachedKey === $key) {
            return $this->cachedRoutes;
        }

        $this->sortRoutes();

        $merged = [...array_values($this->staticIndex), ...$this->routes];

        usort($merged, static function (Route $a, Route $b): int {
            $diff = self::compareSpecificity($b->specificity(), $a->specificity());
            if ($diff !== 0) {
                return $diff;
            }
            return $a->registrationOrder() <=> $b->registrationOrder();
        });

        $this->cachedRoutes = $merged;
        $this->cachedKey = $key;

        return $merged;
    }

    /**
     * @return list<array{method: string, path: string}>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->getRoutes() as $route) {
            $out[] = ['method' => $route->method, 'path' => $route->path];
        }
        return $out;
    }

    /**
     * Detailed route export: method, path, extracted path-parameter
     * names, and per-parameter regex constraints from `where()`.
     * Used by `routes:list --json` and by {@see \Framework\OpenApi\OpenApiExporter}.
     *
     * @return list<array{
     *   method: string,
     *   path: string,
     *   params: list<string>,
     *   where: array<string, string>
     * }>
     */
    public function allDetailed(): array
    {
        $out = [];
        foreach ($this->getRoutes() as $route) {
            $out[] = [
                'method' => $route->method,
                'path' => $route->path,
                'params' => self::extractPathParams($route->path),
                'where' => $route->getConstraints(),
            ];
        }
        return $out;
    }

    /**
     * Extract `{name}` path-parameter names from a route path.
     *
     * @return list<string>
     */
    private static function extractPathParams(string $path): array
    {
        $names = [];
        $count = preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $path, $matches);
        if ($count !== false) {
            foreach ($matches[1] as $name) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Return a new Router with the given strict-mode flag and the current
     * route table copied over. Counters and the routes cache are also
     * copied so a caller can branch off a pre-populated router (e.g. for
     * test fixtures that need non-strict behaviour) without losing the
     * registered routes.
     */
    public function withStrict(bool $strict): self
    {
        $clone = new self($strict);
        $clone->routes = $this->routes;
        $clone->staticIndex = $this->staticIndex;
        $clone->staticMethodsByPath = $this->staticMethodsByPath;
        $clone->registrationCounter = $this->registrationCounter;
        $clone->sorted = $this->sorted;
        $clone->cacheVersion = $this->cacheVersion;
        $clone->cachedRoutes = $this->cachedRoutes;
        $clone->cachedKey = $this->cachedKey;
        $clone->staticHitCount = $this->staticHitCount;
        $clone->regexCallCount = $this->regexCallCount;
        return $clone;
    }

    /**
     * @return array{staticHits: int, regexCalls: int, strict: bool}
     */
    public function stats(): array
    {
        return [
            'staticHits' => $this->staticHitCount,
            'regexCalls' => $this->regexCallCount,
            'strict' => $this->strict,
        ];
    }

    public function resetStats(): void
    {
        $this->staticHitCount = 0;
        $this->regexCallCount = 0;
    }

    /**
     * Push `$prefix` onto the prefix stack, run `$callback($this)`, and pop.
     * Routes registered inside the callback are prefixed with the joined
     * stack at `register()` time, so no sub-router is allocated and no
     * `withPrefix()` re-registration pass runs.
     *
     * @param callable(self): void $callback
     */
    public function group(string $prefix, callable $callback): void
    {
        $this->prefixStack[] = $prefix;
        try {
            $callback($this);
        } finally {
            array_pop($this->prefixStack);
        }
    }

    /**
     * @param callable(Request, array<string, string>): ResponseInterface $handler
     */
    private function register(string $method, string $path, callable $handler): Route
    {
        if ($this->prefixStack !== []) {
            $path = implode('', $this->prefixStack) . $path;
        }
        $isStatic = Route::isStaticPath($path);
        $route = $isStatic
            ? new Route($method, $path, Closure::fromCallable($handler), memo: null)
            : new Route($method, $path, Closure::fromCallable($handler));
        if (!$isStatic) {
            $route->warmupCaches();
        }
        $this->add($route);

        return $route;
    }

    private function sortRoutes(): void
    {
        if ($this->sorted) {
            return;
        }

        $indexed = [];
        foreach ($this->routes as $i => $route) {
            $indexed[] = [$i, $route];
        }

        usort($indexed, static function (array $a, array $b): int {
            $diff = self::compareSpecificity($b[1]->specificity(), $a[1]->specificity());
            if ($diff !== 0) {
                return $diff;
            }
            return $a[0] <=> $b[0];
        });

        $this->routes = array_map(static fn(array $pair): Route => $pair[1], $indexed);
        $this->sorted = true;
    }

    /**
     * Compare per-segment specificity tuples. Higher score wins; element-wise
     * compare first, and a longer tuple is treated as "more specific" when
     * all overlapping elements are equal (deeper paths beat shallower ones).
     *
     * @param list<int> $a
     * @param list<int> $b
     */
    private static function compareSpecificity(array $a, array $b): int
    {
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] <=> $b[$i];
            }
        }
        return count($a) <=> count($b);
    }

    /**
     * Collect methods (other than the current) that have a route whose path
     * matches $path. Used to build the Allow list for 405 responses after
     * the main match loop has already failed. Iterates dynamic routes once
     * more, reusing the same compiled patterns from the main loop.
     *
     * @return list<string>
     */
    private function collectAllowedMethods(string $path, string $currentMethod): array
    {
        $candidates = [];

        if (isset($this->staticMethodsByPath[$path])) {
            foreach ($this->staticMethodsByPath[$path] as $m) {
                if ($m !== $currentMethod) {
                    $candidates[] = $m;
                }
            }
        }

        foreach ($this->routes as $route) {
            if ($route->isStatic()) {
                continue;
            }
            if ($route->getNormalizedMethod() === $currentMethod) {
                continue;
            }
            $this->regexCallCount++;
            if ($route->pathMatches($path)) {
                $candidates[] = $route->getNormalizedMethod();
            }
        }

        return array_values(array_unique($candidates));
    }
}
