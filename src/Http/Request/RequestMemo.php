<?php

declare(strict_types=1);

namespace Framework\Http\Request;

/**
 * Mutable bag of derived state attached to a {@see Request}: the lazily
 * constructed {@see RequestBinder} built on first `bind()` / `bindWith()`
 * call. Lives off-`Request` so the request value object can stay
 * `final readonly`.
 *
 * Why this is NOT `readonly`: a `readonly` class cannot expose a writable
 * field, and the binder cache must be filled in-place on the first call
 * to `Request::bind()` — otherwise every bind allocates a fresh
 * `RequestBinder` and a fresh `Validator` (O(N) for a controller that
 * binds inside a loop). The class is `final` so the only writes go
 * through `Request`'s own accessor.
 *
 * Sharing across `with*()`: a `RequestMemo` instance is held **by
 * reference** by all `with*()` children (`withJson()` / `withForm()` /
 * `withFiles()` / `withCsrfToken()` / `withValidator()` / `withId()` /
 * `withTrustedProxies()` / `withAttribute()`). PHP does NOT copy-on-write
 * object handles — `new self(..., $this->memo)` propagates the same
 * object handle to the new request. This is **intentional** and load-
 * bearing:
 *  - the first `bind()` call populates the lazy binder cache on the
 *    shared memo;
 *  - subsequent `with*()` calls see the already-populated binder
 *    without re-allocating it (the test
 *    `RequestBinderLazyTest::testWithMethodsPreserveMemoSoBinderStaysShared`
 *    pins this contract);
 *  - the binder itself is stateless and safe to share across requests
 *    that share the same `Validator`, so per-call allocation would be
 *    pure waste on the bind-in-a-loop hot path.
 *
 * A caller that genuinely needs an isolated memo (e.g. for a request
 * bound to a *different* `Validator`) MUST pass an explicit `RequestMemo`
 * — there is no public API to detach the shared one.
 */
final class RequestMemo
{
    public ?RequestBinder $binder = null;

    public function __construct(?RequestBinder $binder = null)
    {
        $this->binder = $binder;
    }
}
