<?php

declare(strict_types=1);

namespace Framework\OpenApi;

use Framework\Http\Router\Route;

/**
 * Pure-function OpenAPI 3.1 converter. Reads a {@see \Framework\Http\Router\Router}'s
 * registered route table and emits a JSON-serialisable document.
 *
 * The class is intentionally a *passive consumer* of the Router —
 * it never mutates routing state, never reads request state, never
 * reaches into the container. The two inputs are:
 *  1. The route list (`Router::getRoutes()`)
 *  2. A user-supplied `operationDecorator` closure that adds
 *     `requestBody`, `responses`, `security`, `tags`, etc. — the
 *     framework does not scan code for DTO annotations; the user
 *     wires DTOs in via the decorator hook where they have the
 *     full type context.
 *
 * **Spec compliance.** The class emits OpenAPI 3.1, not 3.0.
 * 3.1 is JSON-Schema-2020-12-aligned (RFC-coherent), and is the
 * version supported by every modern tooling chain (Stoplight,
 * Redocly, Spectral, etc.). The emitted document is byte-stable
 * for a given (router, decorator) input — running the converter
 * twice produces the same output.
 *
 * **Path conversion.**
 *  - `/users/{id}` (Router)        → `/users/{id}` (OpenAPI URI template)
 *  - `/files/*`   (Router)        → `/files/{restOfPath}` (OpenAPI wildcard)
 *  - `where('id', '[0-9]+')`      → `parameter.schema.pattern = "[0-9]+"`
 */
final class OpenApiExporter
{
    /**
     * @param string $title OpenAPI `info.title` field
     * @param string $version OpenAPI `info.version` field (NOT the
     *     framework version — use the API's own semver)
     * @param string $description OpenAPI `info.description` field
     * @param array<string, string> $pathParamDescriptions Per-path
     *     parameter descriptions keyed by parameter name
     * @param ?\Closure $operationDecorator Optional
     *     `(array $op, Route $r): array` that the caller uses to
     *     attach `requestBody` / `responses` / `security` / `tags`
     *     to each operation. Receives the base operation array and
     *     the originating {@see Route}; returns the (possibly
     *     augmented) operation array. Returning the input array
     *     unchanged is the "no change" sentinel.
     */
    public function __construct(
        private readonly string $title,
        private readonly string $version,
        private readonly string $description = '',
        private readonly array $pathParamDescriptions = [],
        private readonly ?\Closure $operationDecorator = null,
    ) {
    }

    /**
     * Build an {@see OpenApiDocument} from a Router's current
     * route table. The export is deterministic — repeated calls
     * on the same Router produce the same byte output.
     */
    public function build(\Framework\Http\Router\Router $router): OpenApiDocument
    {
        $paths = [];

        foreach ($router->getRoutes() as $route) {
            $uriPath = $this->convertPath($route->path);
            $params = $this->extractParams($route->path);

            $operation = [
                'parameters' => $this->buildParameters($params, $this->pathConstraints($route)),
                'responses' => [
                    '200' => ['description' => 'OK'],
                ],
            ];

            if ($this->operationDecorator !== null) {
                $decorated = ($this->operationDecorator)($operation, $route);
                if (is_array($decorated)) {
                    $operation = $decorated;
                }
            }

            $method = strtolower($route->method);
            if (!isset($paths[$uriPath])) {
                $paths[$uriPath] = [];
            }
            $paths[$uriPath][$method] = $operation;
        }

        // Deterministic order: sort paths and operations lexicographically
        ksort($paths);
        foreach ($paths as $uriPath => $ops) {
            ksort($ops);
            $paths[$uriPath] = $ops;
        }

        $document = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $this->title,
                'version' => $this->version,
            ],
            'paths' => $paths,
        ];

        if ($this->description !== '') {
            $document['info']['description'] = $this->description;
        }

        return new OpenApiDocument($document);
    }

    /**
     * Convert a Router path (e.g. `/users/{id}`) to an OpenAPI URI
     * template (e.g. `/users/{id}`). Wildcards are mapped to
     * `{restOfPath}`.
     */
    private function convertPath(string $path): string
    {
        if (!str_ends_with($path, '*')) {
            return $path;
        }
        return rtrim($path, '*') . '{restOfPath}';
    }

    /**
     * Extract path-parameter names from `{name}` segments.
     *
     * @return list<string>
     */
    private function extractParams(string $path): array
    {
        $names = [];
        $count = preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $path, $matches);
        if ($count !== false) {
            foreach ($matches[1] as $name) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * @param list<string> $params
     * @param array<string, string> $constraints
     * @return list<array<string, mixed>>
     */
    private function buildParameters(array $params, array $constraints): array
    {
        $out = [];
        foreach ($params as $name) {
            $schema = ['type' => 'string'];
            if (isset($constraints[$name])) {
                $schema['pattern'] = $constraints[$name];
            }
            $parameter = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => $schema,
            ];
            if (isset($this->pathParamDescriptions[$name])) {
                $parameter['description'] = $this->pathParamDescriptions[$name];
            }
            $out[] = $parameter;
        }
        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function pathConstraints(Route $route): array
    {
        return $route->getConstraints();
    }
}
