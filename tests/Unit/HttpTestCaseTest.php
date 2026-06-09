<?php

declare(strict_types=1);

namespace Framework\Tests\Unit;

use Framework\Http\HttpKernel;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;
use Framework\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(HttpTestCase::class)]
final class HttpTestCaseTest extends HttpTestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->get('/ping', static fn(): Response => Response::json(['pong' => true]));
        $this->router->post('/echo', static fn(Request $r): Response => Response::json([
            'received' => $r->json(),
        ]));
    }

    protected function app(): HttpKernel
    {
        return new HttpKernel($this->router);
    }

    public function testRequestReturnsResponseForKnownRoute(): void
    {
        $response = $this->request('GET', '/ping');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->status);
        self::assertSame(['pong' => true], $this->jsonBody($response));
    }

    public function testRequestSerializesArrayBodyAsJson(): void
    {
        $response = $this->request('POST', '/echo', ['hello' => 'world']);

        self::assertSame(200, $response->status);
        $body = $this->jsonBody($response);
        self::assertSame(['hello' => 'world'], $body['received']);
    }

    public function testRequestReturnsProblemForUnknownRoute(): void
    {
        $response = $this->request('GET', '/missing');

        $this->assertProblem($response, 404);
    }

    public function testRequestMergesCustomHeaders(): void
    {
        $this->router->get('/with-header', static fn(Request $r): Response => Response::json([
            'token' => $r->header('X-Token'),
        ]));

        $response = $this->request('GET', '/with-header', headers: ['X-Token' => 'abc123']);

        self::assertSame(200, $response->status);
        self::assertSame(['token' => 'abc123'], $this->jsonBody($response));
    }
}
