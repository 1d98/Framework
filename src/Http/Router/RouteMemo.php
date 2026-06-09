<?php

declare(strict_types=1);

namespace Framework\Http\Router;

/**
 * Mutable bag of derived state attached to a {@see Route}: the lazy caches
 * built on first match plus the monotonic registration index assigned by the
 * Router. Lives off-`Route` so the route value object can stay
 * `final readonly`.
 *
 * Why this is NOT `readonly`: a `readonly` class cannot expose mutable
 * fields, and these caches must be filled in-place on the first matching
 * call (otherwise every `Router::match()` re-compiles the regex and
 * re-parses the path — a measurable regression for the dynamic-route
 * hot path). The class is `final` so the only writes go through `Route`'s
 * own methods, and the fields are public so `Route` can read/write
 * without a setter ceremony.
 *
 * Sharing across derivatives: `Route` is `final readonly` and exposes
 * no `__clone()` hook, so a `clone` of a `Route` is never produced by
 * the framework. The actual derivative paths
 * (`where()` / `withRegistrationOrder()` / `withPrefix()`) each
 * construct a **fresh** `RouteMemo` rather than cloning the parent's
 * — the caches they preserve are copied by value, the ones they want
 * invalidated are passed as `null`, and `registrationOrder` is threaded
 * through explicitly. This keeps per-derivative derived state
 * independent without relying on COW.
 *
 * The one place a `RouteMemo` is shared by reference is across
 * `Router::withStrict()` clones: the child router copies the parent's
 * `routes` array by handle, so parent and child share the same
 * `RouteMemo` objects. That is safe because the memo's lazy fields
 * are append-only (filled once, never rewritten in a way that depends
 * on the holding route) and the registration index is stamped at
 * `add()` time, before any clone can observe the table.
 */
final class RouteMemo
{
    /**
     * @param list<int>|null $specificityCache
     */
    public function __construct(
        public ?string $compiledPattern = null,
        public ?string $normalizedMethod = null,
        public ?bool $staticCache = null,
        public ?array $specificityCache = null,
        public int $registrationOrder = 0,
    ) {
    }
}
