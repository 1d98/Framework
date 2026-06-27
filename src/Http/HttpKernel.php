<?php

declare(strict_types=1);

namespace Framework\Http;

use Framework\Container\ContainerInterface;
use Framework\Http\Exception\HttpException;
use Framework\Http\Exception\NotFoundHttpException;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Response\ResponseInterface;
use Framework\Http\Router\RouteNotFoundException;
use Framework\Http\Router\Router;
use Framework\Logging\LoggerInterface;
use Throwable;

final class HttpKernel
{
    private readonly Pipeline $pipeline;

    private readonly RequestErrorRenderer $errorRenderer;

    private readonly ?StructuredErrorRenderer $structuredRenderer;

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
        ?StructuredErrorRenderer $structuredRenderer = null,
    ) {
        $this->pipeline = $pipeline ?? new Pipeline($container);
        // Build a default `RequestErrorRenderer` so legacy callers that
        // do not opt into `StructuredErrorRenderer` keep getting the
        // exact same shape they have been getting since 0.5.x.
        $this->errorRenderer = $errorRenderer ?? new RequestErrorRenderer($debug);
        $this->structuredRenderer = $structuredRenderer;
        $this->requestLogger = $requestLogger ?? new RequestLogger($logger);
        $this->logger = $logger;
    }

    public function handle(Request $request): ResponseInterface
    {
        $core = $this->core();
        try {
            $response = $this->pipeline->process($request, $core);
            return $response->withRequestId($request->id);
        } catch (Throwable $e) {
            $response = $this->renderError($e, $request);
            $this->logFailure($e, $request, $response);
            return $response;
        }
    }

    private function renderError(Throwable $e, Request $request): Response
    {
        if ($this->structuredRenderer !== null) {
            return $this->structuredRenderer->render($e, $request);
        }
        return $this->errorRenderer->render($e, $request);
    }

    private function core(): callable
    {
        return function (Request $r): ResponseInterface {
            try {
                $result = $this->router->match($r);
            } catch (RouteNotFoundException $e) {
                throw new NotFoundHttpException('No route matches ' . $r->method . ' ' . $r->path, $e);
            }
            $handler = $result['handler'];
            $params = $result['params'];
            /** @var callable(Request, array<string, string>): ResponseInterface $handler */
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
