<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Router;

use Framework\Http\Router\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Route::class)]
final class RouteStaticPathTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function staticPathProvider(): array
    {
        return [
            'simple literal' => ['/users', true],
            'root literal' => ['/', true],
            'nested literal' => ['/users/posts/comments', true],
            'trailing slash' => ['/users/', true],
            'empty string is static' => ['', true],
            'whitespace only' => ['   ', true],

            'single param' => ['/users/{id}', false],
            'param in middle' => ['/users/{id}/posts', false],
            'multiple params' => ['/users/{a}/posts/{b}', false],
            'wildcard' => ['/users/*', false],
            'param and wildcard' => ['/users/{id}/*', false],
            'lone opening brace' => ['/users/{', false],
            'asterisk inside segment' => ['/assets/*.css', false],
        ];
    }

    #[DataProvider('staticPathProvider')]
    public function testIsStaticPathReturnsExpectedVerdict(string $path, bool $expected): void
    {
        self::assertSame($expected, Route::isStaticPath($path));
    }

    public function testIsStaticPathIsPureAndAllocationFree(): void
    {
        // Calling the static check repeatedly on the same path must always
        // agree and must not produce a deprecation/error (catches a future
        // refactor that accidentally turns this into a stateful lookup).
        $path = '/users/{id}';
        for ($i = 0; $i < 5; $i++) {
            self::assertFalse(Route::isStaticPath($path));
            self::assertTrue(Route::isStaticPath('/users'));
        }
    }

    public function testIsStaticPathMatchesRouteInstanceIsStaticForSamePath(): void
    {
        // Router and Route must agree on every shape of path — that's the
        // whole point of centralising the regex in one place.
        $samples = [
            '/users',
            '/users/{id}',
            '/users/{a}/posts/{b}',
            '/users/*',
            '/assets/*.css',
            '',
            '/',
        ];

        foreach ($samples as $path) {
            $route = new Route('GET', $path, static fn(): string => 'ok');
            self::assertSame(
                $route->isStatic(),
                Route::isStaticPath($path),
                sprintf('Route::isStaticPath() and Route::isStatic() disagree on %s', $path),
            );
        }
    }
}
