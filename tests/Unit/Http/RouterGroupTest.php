<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
final class RouterGroupTest extends TestCase
{
    public function testGroupPrefixesAllRoutes(): void
    {
        $router = new Router();
        $router->group('/api/v1', static function (Router $r): void {
            $r->get('/users', static fn(): Response => Response::text('list'));
            $r->post('/users', static fn(): Response => Response::text('create'));
        });

        $list = $router->match(new Request('GET', '/api/v1/users'));
        $create = $router->match(new Request('POST', '/api/v1/users'));

        self::assertArrayHasKey('handler', $list);
        self::assertArrayHasKey('handler', $create);
    }

    public function testNestedGroups(): void
    {
        $router = new Router();
        $router->group('/api', static function (Router $r): void {
            $r->group('/v1', static function (Router $r2): void {
                $r2->get('/users', static fn(): Response => Response::text('users'));
            });
        });

        $result = $router->match(new Request('GET', '/api/v1/users'));

        self::assertArrayHasKey('handler', $result);
    }

    public function testGroupDoesNotAffectRoutesOutsideGroup(): void
    {
        $router = new Router();
        $router->get('/health', static fn(): Response => Response::text('ok'));
        $router->group('/api', static function (Router $r): void {
            $r->get('/users', static fn(): Response => Response::text('users'));
        });

        $health = $router->match(new Request('GET', '/health'));
        $users = $router->match(new Request('GET', '/api/users'));

        self::assertArrayHasKey('handler', $health);
        self::assertArrayHasKey('handler', $users);
    }

    public function testGroupWithPathParams(): void
    {
        $router = new Router();
        $router->group('/api/v1', static function (Router $r): void {
            $r->get('/users/{id}', static fn(Request $req, array $p): Response => Response::text($p['id']));
        });

        $result = $router->match(new Request('GET', '/api/v1/users/42'));

        self::assertSame(['id' => '42'], $result['params']);
    }

    public function testGroupWithAllMethods(): void
    {
        $router = new Router();
        $router->group('/api', static function (Router $r): void {
            $r->get('/x', static fn(): Response => Response::text('g'));
            $r->post('/x', static fn(): Response => Response::text('p'));
            $r->put('/x', static fn(): Response => Response::text('u'));
            $r->delete('/x', static fn(): Response => Response::text('d'));
            $r->patch('/x', static fn(): Response => Response::text('pa'));
        });

        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            self::assertArrayHasKey(
                'handler',
                $router->match(new Request($method, '/api/x')),
                "Group should register {$method} /api/x",
            );
        }
    }

    public function testEmptyGroup(): void
    {
        $router = new Router();
        $router->group('/api', static function (Router $r): void {
            // nothing
        });

        self::assertSame([], $router->getRoutes());
    }
}
