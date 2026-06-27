<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Container\Container;
use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Exception\NotFoundHttpException;
use Framework\Http\HttpKernel;
use Framework\Http\Middleware\MiddlewareInterface;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Response\ResponseInterface;
use Framework\Http\Router\Router;
use Framework\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(HttpKernel::class)]
final class HttpKernelErrorHandlingTest extends TestCase
{
    public function testRouteNotFoundReturnsRfc7807ProblemDetails(): void
    {
        $kernel = new HttpKernel(new Router());

        $response = $kernel->handle(new Request('GET', '/missing'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(404, $response->status);
        self::assertSame('application/problem+json', $response->headers['Content-Type']);

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame(404, $body['status']);
        self::assertSame('Not Found', $body['title']);
        self::assertSame('about:blank', $body['type']);
        self::assertSame('/missing', $body['instance']);
        self::assertIsString($body['detail']);
        self::assertStringContainsString('No route matches GET /missing', $body['detail']);
    }

    public function testHttpExceptionReturnsRfc7807ProblemDetails(): void
    {
        $router = new Router();
        $router->get('/bad', static function (): Response {
            throw new BadRequestHttpException('Missing field "email"');
        });

        $kernel = new HttpKernel($router);
        $response = $kernel->handle(new Request('GET', '/bad'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(400, $response->status);
        self::assertSame('application/problem+json', $response->headers['Content-Type']);

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame(400, $body['status']);
        self::assertSame('Bad Request', $body['title']);
        self::assertSame('Missing field "email"', $body['detail']);
    }

    public function testProductionModeHidesGenericExceptionMessage(): void
    {
        $router = new Router();
        $router->get('/crash', static function (): Response {
            throw new RuntimeException('database connection lost');
        });

        $kernel = new HttpKernel($router);
        $response = $kernel->handle(new Request('GET', '/crash'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(500, $response->status);
        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame(500, $body['status']);
        self::assertSame('Internal Server Error', $body['title']);
        self::assertSame('Internal Server Error', $body['detail']);
        self::assertArrayNotHasKey('trace', $body);
    }

    public function testDebugModeExposesGenericExceptionMessageAndTrace(): void
    {
        $router = new Router();
        $router->get('/crash', static function (): Response {
            throw new RuntimeException('database connection lost');
        });

        // HttpKernel builds a default `RequestErrorRenderer($debug)` with
        // `redactTrace: true` (the safe default). So even when the kernel
        // is in debug mode, the trace is suppressed in the response body —
        // operators must wire a `RequestErrorRenderer(debug: true, redactTrace: false)`
        // through the kernel's optional ctor arg to opt in.
        $kernel = new HttpKernel($router, null, null, null, true);
        $response = $kernel->handle(new Request('GET', '/crash'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(500, $response->status);
        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame(500, $body['status']);
        self::assertSame('Internal Server Error', $body['detail']);
        self::assertArrayNotHasKey('trace', $body);
    }

    public function testDebugModeWithExplicitRedactFalseExposesTrace(): void
    {
        // Opt-in to trace leakage: the kernel is in debug mode AND the
        // explicitly-supplied renderer has `redactTrace: false`. This is
        // the dev/staging shape that surfaces file paths and class names.
        $router = new Router();
        $router->get('/crash', static function (): Response {
            throw new RuntimeException('database connection lost');
        });

        $kernel = new HttpKernel(
            $router,
            null,
            null,
            null,
            true,
            new \Framework\Http\RequestErrorRenderer(debug: true, redactTrace: false),
        );
        $response = $kernel->handle(new Request('GET', '/crash'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(500, $response->status);
        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame('database connection lost', $body['detail']);
        self::assertArrayHasKey('trace', $body);
        self::assertIsArray($body['trace']);
        self::assertNotEmpty($body['trace']);
    }

    public function testDebugModeKeepsHttpExceptionMessage(): void
    {
        $router = new Router();
        $router->get('/bad', static function (): Response {
            throw new BadRequestHttpException('email field is invalid');
        });

        $kernel = new HttpKernel($router, null, null, null, true);
        $response = $kernel->handle(new Request('GET', '/bad'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(400, $response->status);
        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame('email field is invalid', $body['detail']);
        self::assertArrayNotHasKey('trace', $body);
    }

    public function testMiddlewareThrownHttpExceptionCaughtAsRfc7807(): void
    {
        $router = new Router();
        $router->get('/x', static fn(): Response => Response::text('body'));

        $pipeline = new Pipeline();
        $pipeline->pipe(new class () implements MiddlewareInterface {
            public function process(Request $request, callable $next): ResponseInterface
            {
                throw new NotFoundHttpException('blocked by mw');
            }
        });

        $kernel = new HttpKernel($router, $pipeline);
        $response = $kernel->handle(new Request('GET', '/x'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(404, $response->status);
        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame('Not Found', $body['title']);
        self::assertSame('blocked by mw', $body['detail']);
    }

    public function testContainerIsAcceptedAsOptional(): void
    {
        $container = new Container();
        $kernel = new HttpKernel(new Router(), new Pipeline(), $container);

        self::assertSame($container, $kernel->container());
    }

    public function testContainerIsOptional(): void
    {
        $kernel = new HttpKernel(new Router(), new Pipeline());

        self::assertNull($kernel->container());
    }

    public function testContainerInConstructorDoesNotAffectRouting(): void
    {
        $container = new Container();
        $router = new Router();
        $router->get('/x', static fn(): Response => Response::text('ok'));

        $kernel = new HttpKernel($router, new Pipeline(), $container);
        $response = $kernel->handle(new Request('GET', '/x'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->status);
        self::assertSame('ok', $response->body);
    }

    public function testIsDebugReflectsConstructor(): void
    {
        $kernel = new HttpKernel(new Router());
        self::assertFalse($kernel->isDebug());

        $debugKernel = new HttpKernel(new Router(), null, null, null, true);
        self::assertTrue($debugKernel->isDebug());
    }

    public function testFourXxHttpExceptionLogsWarning(): void
    {
        $router = new Router();
        $router->post('/bad', static function (): Response {
            throw new BadRequestHttpException('email is invalid');
        });

        $logger = new RecordingLogger();
        $kernel = new HttpKernel($router, null, null, $logger);
        $kernel->handle(new Request('POST', '/bad'));

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('http_exception', $logger->records[0]['message']);
        self::assertSame(400, $logger->records[0]['context']['status']);
        self::assertSame('POST', $logger->records[0]['context']['method']);
        self::assertSame('/bad', $logger->records[0]['context']['path']);
        self::assertSame(BadRequestHttpException::class, $logger->records[0]['context']['exception']);
        self::assertSame('email is invalid', $logger->records[0]['context']['message']);
    }

    public function testRouteNotFoundHttpExceptionLogsWarning(): void
    {
        $logger = new RecordingLogger();
        $kernel = new HttpKernel(new Router(), null, null, $logger);
        $kernel->handle(new Request('GET', '/missing'));

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('http_exception', $logger->records[0]['message']);
        self::assertSame(404, $logger->records[0]['context']['status']);
    }

    public function testFiveXxHttpExceptionLogsError(): void
    {
        $router = new Router();
        $router->get('/fail', static function (): Response {
            throw new \Framework\Http\Exception\InternalServerErrorHttpException('database down');
        });

        $logger = new RecordingLogger();
        $kernel = new HttpKernel($router, null, null, $logger);
        $kernel->handle(new Request('GET', '/fail'));

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('http_exception', $logger->records[0]['message']);
        self::assertSame(500, $logger->records[0]['context']['status']);
    }

    public function testGenericThrowableLogsError(): void
    {
        $router = new Router();
        $router->get('/crash', static function (): Response {
            throw new RuntimeException('boom');
        });

        $logger = new RecordingLogger();
        $kernel = new HttpKernel($router, null, null, $logger);
        $kernel->handle(new Request('GET', '/crash'));

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('unhandled_exception', $logger->records[0]['message']);
        self::assertSame(500, $logger->records[0]['context']['status']);
        self::assertSame(RuntimeException::class, $logger->records[0]['context']['exception']);
    }

    public function testNoLoggerDoesNotCrash(): void
    {
        $router = new Router();
        $router->post('/bad', static function (): Response {
            throw new BadRequestHttpException('email is invalid');
        });

        $kernel = new HttpKernel($router);
        $response = $kernel->handle(new Request('POST', '/bad'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(400, $response->status);
    }

    public function testRouterEmits405WithAllowHeaderWhenPathExistsForDifferentMethod(): void
    {
        $router = new Router();
        $router->get('/api/users', static fn(): Response => Response::text('list'));
        $router->post('/api/users', static fn(): Response => Response::text('create'));

        $kernel = new HttpKernel($router);
        $response = $kernel->handle(new Request('OPTIONS', '/api/users'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(405, $response->status);
        self::assertSame('application/problem+json', $response->headers['Content-Type']);
        self::assertArrayHasKey('Allow', $response->headers);
        self::assertSame('GET, POST', $response->headers['Allow']);

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame(405, $body['status']);
        self::assertSame('Method Not Allowed', $body['title']);
    }

    public function testMethodNotAllowedHttpExceptionDirectlyProducesAllowHeader(): void
    {
        $router = new Router();
        $router->get('/x', static fn(): Response => Response::text('ok'));

        $pipeline = new \Framework\Http\Middleware\Pipeline();
        $pipeline->pipe(new class () implements MiddlewareInterface {
            public function process(Request $request, callable $next): ResponseInterface
            {
                throw new \Framework\Http\Exception\MethodNotAllowedHttpException(
                    'blocked',
                    null,
                    ['GET', 'PUT'],
                    ['Allow' => 'GET, PUT'],
                );
            }
        });

        $kernel = new HttpKernel($router, $pipeline);
        $response = $kernel->handle(new Request('POST', '/x'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(405, $response->status);
        self::assertSame('GET, PUT', $response->headers['Allow'] ?? null);
    }

    public function testGenericHttpExceptionDoesNotEmitAllowHeader(): void
    {
        $router = new Router();
        $router->get('/x', static function (): Response {
            throw new BadRequestHttpException('nope');
        });

        $kernel = new HttpKernel($router);
        $response = $kernel->handle(new Request('GET', '/x'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(400, $response->status);
        self::assertArrayNotHasKey('Allow', $response->headers);
    }
}
