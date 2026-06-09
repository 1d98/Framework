<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Router;

use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Route;
use Framework\Http\Router\Router;
use Framework\Http\Router\RouterException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
#[CoversClass(RouterException::class)]
final class RouterPerformanceAndSpecificityTest extends TestCase
{
    public function testStaticIndexHitsAllRequestsAndSkipsRegexOnMostlyStaticTable(): void
    {
        $router = new Router();
        for ($i = 0; $i < 190; $i++) {
            $router->get(sprintf('/static/path/%03d', $i), static fn(): Response => Response::text('s'));
        }
        for ($i = 0; $i < 10; $i++) {
            $router->get(sprintf('/dynamic/%03d/{id}', $i), static fn(): Response => Response::text('d'));
        }

        for ($i = 0; $i < 1000; $i++) {
            $idx = $i % 190;
            $result = $router->match(new Request('GET', sprintf('/static/path/%03d', $idx)));
            self::assertArrayHasKey('handler', $result);
        }

        self::assertSame(1000, $router->stats()['staticHits']);
        self::assertSame(0, $router->stats()['regexCalls']);
    }

    public function testStaticRouteBeatsParamRouteRegardlessOfRegistrationOrder(): void
    {
        $router = new Router();
        $param = static fn(): Response => Response::text('param');
        $static = static fn(): Response => Response::text('static');
        $router->add(new Route('GET', '/users/{id}', $param));
        $router->add(new Route('GET', '/users/me', $static));

        $result = $router->match(new Request('GET', '/users/me'));
        self::assertSame($static, $result['handler']);
    }

    public function testSpecificityBeatsRegistrationOrderAtSecondSegment(): void
    {
        $router = new Router();
        $first = static fn(): Response => Response::text('first');
        $second = static fn(): Response => Response::text('second');
        $router->add(new Route('GET', '/users/{id}/posts', $first));
        $router->add(new Route('GET', '/users/me/posts', $second));

        $result = $router->match(new Request('GET', '/users/me/posts'));
        self::assertSame($second, $result['handler']);
    }

    public function testDuplicateRegistrationThrowsWithPathInMessage(): void
    {
        $router = new Router();
        $router->get('/users/me', static fn(): Response => Response::text('first'));

        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('GET /users/me');

        $router->get('/users/me', static fn(): Response => Response::text('second'));
    }

    public function testStrictFalseAllowsDuplicateRegistration(): void
    {
        $router = (new Router())->withStrict(false);
        $first = static fn(): Response => Response::text('first');
        $router->get('/users/me', $first);
        $router->get('/users/me', static fn(): Response => Response::text('second'));

        $result = $router->match(new Request('GET', '/users/me'));
        self::assertSame($first, $result['handler']);
    }

    public function testWhereConstraintAppliesToParamRoute(): void
    {
        $router = new Router();
        $route = $router->get('/users/{id}', static fn(Request $r, array $p): Response => Response::text('id=' . $p['id']))
            ->where('id', '\d+');
        $router->add($route);

        $result = $router->match(new Request('GET', '/users/123'));
        self::assertSame(['id' => '123'], $result['params']);
    }

    public function testWhereConstraintRejectsNonMatchingInput(): void
    {
        $router = new Router();
        $route = new Route('GET', '/users/{id}', static fn(): Response => Response::text('id'))
            ->where('id', '\d+');
        $router->add($route);

        $this->expectException(\Framework\Http\Router\RouteNotFoundException::class);
        $router->match(new Request('GET', '/users/abc'));
    }

    public function testParamAndStaticRoutesWithSameTemplateAreNotTreatedAsDuplicates(): void
    {
        $router = new Router();
        $router->get('/users/{id}', static fn(): Response => Response::text('param'));
        $router->get('/users/me', static fn(): Response => Response::text('static'));

        $meResult = $router->match(new Request('GET', '/users/me'));
        $idResult = $router->match(new Request('GET', '/users/42'));

        $meHandler = $meResult['handler'];
        $idHandler = $idResult['handler'];
        self::assertIsCallable($meHandler);
        self::assertIsCallable($idHandler);

        $meResp = $meHandler(new Request('GET', '/users/me'), $meResult['params']);
        $idResp = $idHandler(new Request('GET', '/users/42'), $idResult['params']);
        self::assertInstanceOf(Response::class, $meResp);
        self::assertInstanceOf(Response::class, $idResp);
        self::assertSame('static', $meResp->body);
        self::assertSame('param', $idResp->body);
    }

    public function testDifferentMethodsOnSamePathAreNotDuplicates(): void
    {
        $router = new Router();
        $router->get('/users', static fn(): Response => Response::text('list'));
        $router->post('/users', static fn(): Response => Response::text('create'));

        $getResult = $router->match(new Request('GET', '/users'));
        $postResult = $router->match(new Request('POST', '/users'));

        $getHandler = $getResult['handler'];
        $postHandler = $postResult['handler'];
        self::assertIsCallable($getHandler);
        self::assertIsCallable($postHandler);

        $getResp = $getHandler(new Request('GET', '/users'), $getResult['params']);
        $postResp = $postHandler(new Request('POST', '/users'), $postResult['params']);
        self::assertInstanceOf(Response::class, $getResp);
        self::assertInstanceOf(Response::class, $postResp);
        self::assertSame('list', $getResp->body);
        self::assertSame('create', $postResp->body);
    }

    public function testFiveHundredMixedRoutesPerformanceBudget(): void
    {
        $router = new Router();
        for ($i = 0; $i < 500; $i++) {
            $router->get(sprintf('/v1/items/%04d', $i), static fn(): Response => Response::text('static'));
            $router->get(sprintf('/v1/groups/{gid}/items/%04d', $i), static fn(): Response => Response::text('mixed'));
        }

        for ($i = 0; $i < 500; $i++) {
            $router->match(new Request('GET', sprintf('/v1/items/%04d', $i % 500)));
        }

        self::assertSame(500, $router->stats()['staticHits']);
        self::assertSame(0, $router->stats()['regexCalls']);
    }

    public function testWhereConstraintWithMultipleParams(): void
    {
        $router = new Router();
        $base = $router->get('/a/{x}/b/{y}', static fn(Request $r, array $p): Response => Response::text("{$p['x']}-{$p['y']}"));
        $router->add($base->where('x', '\d+')->where('y', '[a-z]+'));

        $result = $router->match(new Request('GET', '/a/42/b/abc'));
        self::assertSame(['x' => '42', 'y' => 'abc'], $result['params']);
    }

    public function testWhereConstraintSurvivesGroupPrefix(): void
    {
        $router = new Router();
        $router->group('/api/v1', static function (Router $r): void {
            $base = $r->get('/users/{id}', static fn(Request $r, array $p): Response => Response::text($p['id']));
            $r->add($base->where('id', '\d+'));
        });

        $result = $router->match(new Request('GET', '/api/v1/users/77'));
        self::assertSame(['id' => '77'], $result['params']);
    }

    public function testGroupExpansionRespectsDuplicateDetection(): void
    {
        $router = new Router();
        $router->get('/api/v1/x', static fn(): Response => Response::text('outer'));

        $this->expectException(RouterException::class);

        $router->group('/api/v1', static function (Router $r): void {
            $r->get('/x', static fn(): Response => Response::text('inner'));
        });
    }

    public function testHeadRequestIsNotImplicitlyHandledByGet(): void
    {
        $router = new Router();
        $router->get('/x', static fn(): Response => Response::text('ok'));

        $this->expectException(\Framework\Http\Exception\MethodNotAllowedHttpException::class);
        $router->match(new Request('HEAD', '/x'));
    }
}
