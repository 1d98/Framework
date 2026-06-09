<?php

declare(strict_types=1);

namespace Framework\Http;

use Framework\Container\ContainerInterface;
use Framework\Http\Exception\HttpException;
use Framework\Http\Exception\NotFoundHttpException;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\RouteNotFoundException;
use Framework\Http\Router\Router;
use Framework\Logging\LoggerInterface;
use Throwable;

final class HttpKernel
{
    private readonly Pipeline $pipeline;

    private readonly RequestErrorRenderer $errorRenderer;

    private readonly RequestLogger $requestLogger;

    private readonly ?LoggerInterface $logger;

    public function __construct(
        private readonly Router $router,
        ?Pipeline $pipeline = null,
        private readonly ?ContainerInterface $container = null,
        ?LoggerInterface $logger = null,
        private readonly bool $debug = false,
        ?RequestErrorRenderer $errorRenderer = null,
        ?RequestLogger $requestLogger = null,
    ) {
        $this->pipeline = $pipeline ?? new Pipeline($container);
        $this->errorRenderer = $errorRenderer ?? new RequestErrorRenderer($debug);
        $this->requestLogger = $requestLogger ?? new RequestLogger($logger);
        $this->logger = $logger;
    }

    public function handle(Request $request): Response
    {
        $core = $this->core();
        try {
            return $this->pipeline->process($request, $core)->withRequestId($request->id);
        } catch (Throwable $e) {
            $response = $this->errorRenderer->render($e, $request);
            $this->logFailure($e, $request, $response);
            return $response;
        }
    }

    private function core(): callable
    {
        return function (Request $r): Response {
            try {
                $result = $this->router->match($r);
            } catch (RouteNotFoundException $e) {
                throw new NotFoundHttpException('No route matches ' . $r->method . ' ' . $r->path, $e);
            }
            $handler = $result['handler'];
            $params = $result['params'];
            /** @var callable(Request, array<string, string>): Response $handler */
            return $handler($r, $params);
        };
    }

    public function container(): ?ContainerInterface
    {
        return $this->container;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function logger(): ?LoggerInterface
    {
        return $this->logger;
    }

    private function logFailure(Throwable $e, Request $request, Response $response): void
    {
        if ($e instanceof HttpException) {
            $this->requestLogger->logHttpException($e, $request, $response);
            return;
        }
        $this->requestLogger->logUnhandledException($e, $request, $response);
    }
}
