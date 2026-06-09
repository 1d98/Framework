<?php

declare(strict_types=1);

namespace Framework\Http\Router;

use Framework\Http\Request\Request;
use Framework\Http\Response\Response;

interface RouterInterface
{
    public function add(Route $route): void;

    /**
     * @param callable(Request, array<string, string>): Response $handler
     */
    public function get(string $path, callable $handler): Route;

    /**
     * @param callable(Request, array<string, string>): Response $handler
     */
    public function post(string $path, callable $handler): Route;

    /**
     * @param callable(Request, array<string, string>): Response $handler
     */
    public function put(string $path, callable $handler): Route;

    /**
     * @param callable(Request, array<string, string>): Response $handler
     */
    public function delete(string $path, callable $handler): Route;

    /**
     * @param callable(Request, array<string, string>): Response $handler
     */
    public function patch(string $path, callable $handler): Route;

    /**
     * @return array{handler: callable, params: array<string, string>}
     * @throws RouteNotFoundException
     */
    public function match(Request $request): array;
}
