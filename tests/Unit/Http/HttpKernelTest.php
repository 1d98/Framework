<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\HttpKernel;
use Framework\Http\Middleware\MiddlewareInterface;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpKernel::class)]
final class HttpKernelTest extends TestCase
{
    public function testRoutesRequestAndReturnsResponse(): void
    {
        $router = new Router();
        $router->get('/hello/{name}', static fn(Request $r, array $p): Response => Response::text("Hi {$p['name']}"));

        $kernel = new HttpKernel($router);
        $response = $kernel->handle(new Request('GET', '/hello/world'));

        self::assertSame('Hi world', $response->body);
    }

    public function testReturnsProblemDetailsForUnmatchedRoute(): void
    {
        $router = new Router();
        $kernel = new HttpKernel($router);

        $response = $kernel->handle(new Request('GET', '/missing'));

        self::assertSame(404, $response->status);
        self::assertSame('application/problem+json', $response->headers['Content-Type']);

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame(404, $body['status']);
        self::assertSame('about:blank', $body['type']);
        self::assertSame('Not Found', $body['title']);
        self::assertSame('/missing', $body['instance']);
    }

    public function testMiddlewareIsApplied(): void
    {
        $router = new Router();
        $router->get('/x', static fn(): Response => Response::text('body'));

        $pipeline = new Pipeline();
        $pipeline->pipe(new class () implements MiddlewareInterface {
            public function process(Request $request, callable $next): Response
            {
                return $next($request)->withHeader('X-Mw', 'on');
            }
        });

        $kernel = new HttpKernel($router, $pipeline);
        $response = $kernel->handle(new Request('GET', '/x'));

        self::assertSame('body', $response->body);
        self::assertSame('on', $response->headers['X-Mw']);
    }

    public function testMiddlewareShortCircuitReturnsEarly(): void
    {
        $router = new Router();
        $router->get('/x', static fn(): Response => Response::text('original'));

        $pipeline = new Pipeline();
        $pipeline->pipe(new class () implements MiddlewareInterface {
            public function process(Request $request, callable $next): Response
            {
                return Response::json(['blocked' => true], 403);
            }
        });

        $kernel = new HttpKernel($router, $pipeline);
        $response = $kernel->handle(new Request('GET', '/x'));

        self::assertSame(403, $response->status);
        self::assertSame('application/json', $response->headers['Content-Type']);
    }

    public function testLoggerDefaultsToNull(): void
    {
        $kernel = new HttpKernel(new Router());
        self::assertNull($kernel->logger());
    }

    public function testLoggerGetterReturnsInjectedLogger(): void
    {
        $logger = new \Framework\Logging\NullLogger();
        $kernel = new HttpKernel(new Router(), null, null, $logger);
        self::assertSame($logger, $kernel->logger());
    }

    public function testDebugIsMovedToFifthPositional(): void
    {
        $kernel = new HttpKernel(new Router(), null, null, null, true);
        self::assertTrue($kernel->isDebug());
        self::assertNull($kernel->logger());
    }

    public function testDebugDefaultsToFalseWithExplicitLogger(): void
    {
        $logger = new \Framework\Logging\NullLogger();
        $kernel = new HttpKernel(new Router(), null, null, $logger);
        self::assertFalse($kernel->isDebug());
        self::assertSame($logger, $kernel->logger());
    }

    public function testJsonEncodeFailureRendersAsRfc7807ProblemJson(): void
    {
        $router = new Router();
        $router->get('/bad', static function (): Response {
            $resource = fopen('php://memory', 'r');
            try {
                return Response::json(['bad' => $resource]);
            } finally {
                if (is_resource($resource)) {
                    fclose($resource);
                }
            }
        });

        $kernel = new HttpKernel($router);
        $response = $kernel->handle(new Request('GET', '/bad'));

        self::assertSame(500, $response->status);
        self::assertSame('application/problem+json', $response->headers['Content-Type']);

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame(500, $body['status']);
        self::assertSame('Internal Server Error', $body['title']);
        $detail = $body['detail'];
        self::assertIsString($detail);
        self::assertStringContainsString('json_encode', $detail);
        self::assertSame('/bad', $body['instance']);
    }

    public function testValidationExceptionProduces422WithErrorsField(): void
    {
        $router = new Router();
        $router->post('/x', static function (): void {
            $errors = new \Framework\Validation\ValidationErrorCollection([
                new \Framework\Validation\ValidationError('email', 'required', 'Field is required'),
                new \Framework\Validation\ValidationError('age', 'min', 'Must be at least 18', 5),
            ]);
            throw new \Framework\Validation\ValidationException($errors);
        });

        $kernel = new HttpKernel($router);
        $response = $kernel->handle(new Request('POST', '/x'));

        self::assertSame(422, $response->status);
        self::assertSame('application/problem+json', $response->headers['Content-Type']);

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame(422, $body['status']);
        self::assertSame('Unprocessable Entity', $body['title']);
        self::assertArrayHasKey('errors', $body);
        self::assertIsArray($body['errors']);
        self::assertCount(2, $body['errors']);

        $first = $body['errors'][0];
        self::assertIsArray($first);
        self::assertSame('email', $first['property']);
        self::assertSame('required', $first['rule']);
        self::assertSame('Field is required', $first['message']);

        $second = $body['errors'][1];
        self::assertIsArray($second);
        self::assertSame('age', $second['property']);
        self::assertSame('min', $second['rule']);
        self::assertSame(5, $second['value']);
    }
}
