<?php

declare(strict_types=1);

namespace Framework\Application;

use Framework\Container\Container;
use Framework\Container\ContainerInterface;
use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\HttpKernel;
use Framework\Http\Middleware\JsonBodyParser;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;
use Framework\Logging\LoggerInterface;
use Framework\Logging\StreamLogger;

/**
 * Example application wiring the framework together.
 *
 * This file is not loaded by the framework — it serves as a runnable
 * documentation. Save as public/index.php (or import its parts) to use.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// 1. Container with services
$container = new Container();
$container->set(LoggerInterface::class, static fn(): LoggerInterface => StreamLogger::stderr());
$container->set(JsonBodyParser::class, static fn(): JsonBodyParser => new JsonBodyParser());

// 2. Router with routes
$router = new Router();
$router->get('/', static fn(): Response => Response::html('<h1>Hello, Framework</h1>'));
$router->get('/hello/{name}', static fn(Request $r, array $p): Response => Response::text("Hello, {$p['name']}!"));

$router->group('/api/v1', static function (Router $r): void {
    $r->get('/users', static fn(): Response => Response::json(['users' => []]));
    $r->post('/users', static function (Request $req): Response {
        $data = $req->json();
        if (!is_array($data) || !isset($data['name'])) {
            throw new BadRequestHttpException('Field "name" is required');
        }
        return Response::json(['created' => $data['name']], 201);
    });
});

// 3. Middleware pipeline
$pipeline = new Pipeline();
$pipeline->pipe($container->get(JsonBodyParser::class));

// 4. HTTP kernel + dispatch
$kernel = new HttpKernel($router, $pipeline, $container);
$kernel->handle(Request::fromGlobals())->send();
