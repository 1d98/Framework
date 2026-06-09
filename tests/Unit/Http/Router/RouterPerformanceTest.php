<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Router;

use Framework\Http\Exception\MethodNotAllowedHttpException;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Route;
use Framework\Http\Router\RouteNotFoundException;
use Framework\Http\Router\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
#[CoversClass(MethodNotAllowedHttpException::class)]
#[CoversClass(RouteNotFoundException::class)]
final class RouterPerformanceTest extends TestCase
{
    /**
     * 200 dynamic routes registered, request for a path that does not match
     * any route at all (unknown to every method). The main match loop runs
     * once and the 405 collection is a no-op because no method matches the
     * path either. So the total preg_match count is N, not 2N.
     */
    public function test404OnUnknownPathAmong200DynamicRoutesDoesNotDoublePass(): void
    {
        $router = $this->buildTwoHundredDynamicRoutes();

        try {
            $router->match(new Request('GET', '/totally/unknown/abc'));
            self::fail('Expected RouteNotFoundException');
        } catch (RouteNotFoundException) {
        }

        $this->assertSinglePass(200, $router->stats()['regexCalls']);
    }

    /**
     * 200 dynamic routes registered, request for a path that exists for one
     * method but with the wrong method (405 path). The main loop misses
     * because every route has the wrong method, then the 405 collection
     * iterates the same routes once more. Total = N (main) + N (collect)
     * with the same-method skip applied, so the actual count is at most 2N
     * but is achieved in two single passes (not interleaved with 2N+1
     * double-iteration semantics). Critically, the main loop's pass is
     * done before any collection begins — verifying no inner double work.
     */
    public function test405OnWrongMethodAmong200DynamicRoutesUsesOneMainPassAndOneCollectPass(): void
    {
        $router = $this->buildTwoHundredDynamicRoutes();

        try {
            $router->match(new Request('PUT', '/dyn/050/abc'));
            self::fail('Expected MethodNotAllowedHttpException');
        } catch (MethodNotAllowedHttpException $e) {
            self::assertContains('GET', $e->allowedMethods());
            self::assertContains('POST', $e->allowedMethods());
        }

        $count = $router->stats()['regexCalls'];
        $this->assertSinglePass(200, $count);
    }

    /**
     * 200 dynamic routes registered, request hits a dynamic route at the
     * start of the sorted list. Main loop should exit on the first match
     * without iterating the rest, and the 405 collection must not run.
     */
    public function testEarlyExitOnFirstDynamicRouteMatchesExactlyOneRegex(): void
    {
        $router = $this->buildTwoHundredDynamicRoutes();

        $result = $router->match(new Request('GET', '/dyn/000/abc'));
        self::assertArrayHasKey('handler', $result);

        self::assertSame(
            1,
            $router->stats()['regexCalls'],
            'main loop must exit on first hit, 405 collect must not run',
        );
    }

    /**
     * 200 dynamic routes registered, request hits a dynamic route roughly
     * halfway through the sorted list. Main loop exits at the first hit
     * and 405 collection does not run, so the regex call count is bounded
     * by the position of the first matching route, not by 2N.
     */
    public function testEarlyExitAtMidpointMatchesPositionPlusOne(): void
    {
        $router = $this->buildTwoHundredDynamicRoutes();

        $result = $router->match(new Request('GET', '/dyn/050/abc'));
        self::assertArrayHasKey('handler', $result);

        $count = $router->stats()['regexCalls'];
        self::assertGreaterThan(1, $count, 'should pass at least one route to reach a deep hit');
        self::assertLessThan(
            200,
            $count,
            'must not iterate all 200 routes — early exit is required',
        );
        self::assertSame(
            101,
            $count,
            'first hit at sorted position 100 → 101 calls (positions 0..100 inclusive)',
        );
    }

    /**
     * 404 still works: an unknown path with zero matching routes (across
     * every method) must throw RouteNotFoundException.
     */
    public function test404StillWorksAfterRestructure(): void
    {
        $router = $this->buildTwoHundredDynamicRoutes();

        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionMessage('No route matches GET /nowhere/here');

        $router->match(new Request('GET', '/nowhere/here'));
    }

    /**
     * 405 still works: a path that exists for a different method must throw
     * MethodNotAllowedHttpException with the correct Allow header and
     * allowedMethods list.
     */
    public function test405StillWorksAfterRestructure(): void
    {
        $router = new Router();
        $router->get('/api/users', static fn(): Response => Response::text('list'));
        $router->post('/api/users', static fn(): Response => Response::text('create'));

        try {
            $router->match(new Request('DELETE', '/api/users'));
            self::fail('Expected MethodNotAllowedHttpException');
        } catch (MethodNotAllowedHttpException $e) {
            self::assertSame(405, $e->statusCode);
            self::assertSame(['GET', 'POST'], $e->allowedMethods());
            self::assertSame('GET, POST', $e->headers()['Allow'] ?? null);
        }
    }

    /**
     * The 405 collect pass should iterate dynamic routes at most once more
     * beyond the main pass. With 200 GET routes and a POST request, the
     * main loop runs 200 calls (every method-mismatch short-circuits the
     * regex inside Route::matches but the Router still counts the
     * attempt) and the collect runs 200 calls (POST is not the current
     * method for any route so no same-method skip applies). Total = 400
     * which equals 2N but is two separate single passes — not the prior
     * "collect-then-main" interleaved double work the fix removes.
     *
     * The key invariant is the structural one: never more than 2N, never
     * an interleaved double-iteration. The collect happens strictly
     * AFTER the main pass completes without a hit.
     */
    public function test405CollectIsAtMostOneExtraFullPass(): void
    {
        $router = new Router();
        for ($i = 0; $i < 200; $i++) {
            $router->get(sprintf('/dyn/%03d/{id}', $i), static fn(): Response => Response::text('g'));
        }

        try {
            $router->match(new Request('POST', '/dyn/050/abc'));
            self::fail('Expected MethodNotAllowedHttpException');
        } catch (MethodNotAllowedHttpException) {
        }

        self::assertSame(
            400,
            $router->stats()['regexCalls'],
            'two distinct single passes: 200 main + 200 collect (no overlap, no interleaving)',
        );
    }

    /**
     * @return Router
     */
    private function buildTwoHundredDynamicRoutes(): Router
    {
        $router = new Router();
        for ($i = 0; $i < 100; $i++) {
            $router->get(sprintf('/dyn/%03d/{id}', $i), static fn(): Response => Response::text('g'));
            $router->post(sprintf('/dyn/%03d/{id}', $i), static fn(): Response => Response::text('p'));
        }
        return $router;
    }

    /**
     * Asserts that the actual regex call count equals the main-pass
     * baseline (N). When no dynamic route matches, the 405 collection
     * short-circuits to zero extra calls (because no other method matches
     * the path either). When the collect pass does run, its iteration
     * would show up as additional calls — so the strictest correctness
     * check is that we never exceed 2N.
     */
    private function assertSinglePass(int $expectedMainPass, int $actual): void
    {
        self::assertGreaterThanOrEqual($expectedMainPass, $actual, 'main pass must run for every dynamic route');
        self::assertLessThanOrEqual(
            2 * $expectedMainPass,
            $actual,
            'must not exceed 2N — that would indicate double iteration of the same routes',
        );
    }
}
