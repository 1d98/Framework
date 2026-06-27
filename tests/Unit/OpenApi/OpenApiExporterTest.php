<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\OpenApi;

use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;
use Framework\OpenApi\OpenApiDocument;
use Framework\OpenApi\OpenApiExporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenApiExporter::class)]
#[CoversClass(OpenApiDocument::class)]
final class OpenApiExporterTest extends TestCase
{
    public function testBuildsMinimalDocument(): void
    {
        $router = new Router();
        $exporter = new OpenApiExporter(title: 'My API', version: '1.0.0');

        $doc = $exporter->build($router);

        $payload = $this->payload($doc);
        self::assertSame('3.1.0', $this->stringValue($payload, 'openapi'));
        $info = $this->arrayValue($payload, 'info');
        self::assertSame('My API', $this->stringValue($info, 'title'));
        self::assertSame('1.0.0', $this->stringValue($info, 'version'));
        self::assertSame([], $this->arrayValue($payload, 'paths'));
    }

    public function testEmptyRouterYieldsEmptyPaths(): void
    {
        $exporter = new OpenApiExporter(title: 'T', version: '1.0.0');
        $doc = $exporter->build(new Router());
        self::assertSame([], $this->arrayValue($this->payload($doc), 'paths'));
    }

    public function testIncludesDescriptionWhenSet(): void
    {
        $exporter = new OpenApiExporter(title: 'T', version: '1.0.0', description: 'A test API');
        $doc = $exporter->build(new Router());
        $info = $this->arrayValue($this->payload($doc), 'info');
        self::assertSame('A test API', $this->stringValue($info, 'description'));
    }

    public function testSingleStaticRoute(): void
    {
        $router = new Router();
        $router->get('/users', static fn(Request $r, array $p): Response => Response::empty(200));
        $exporter = new OpenApiExporter(title: 'T', version: '1.0.0');

        $doc = $exporter->build($router);

        $payload = $this->payload($doc);
        $paths = $this->arrayValue($payload, 'paths');
        self::assertArrayHasKey('/users', $paths);
        $users = $this->arrayValue($paths, '/users');
        self::assertArrayHasKey('get', $users);
        $get = $this->arrayValue($users, 'get');
        self::assertSame([], $this->arrayValue($get, 'parameters'));
        self::assertArrayHasKey('200', $this->arrayValue($get, 'responses'));
    }

    public function testParameterizedRouteExtractsPathParams(): void
    {
        $router = new Router();
        $base = $router->get('/users/{id}', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->add($base->where('id', '[0-9]+'));
        $exporter = new OpenApiExporter(title: 'T', version: '1.0.0');

        $doc = $exporter->build($router);

        $paths = $this->arrayValue($this->payload($doc), 'paths');
        self::assertArrayHasKey('/users/{id}', $paths);
        $params = $this->arrayValue($this->arrayValue($this->arrayValue($paths, '/users/{id}'), 'get'), 'parameters');
        // The constrained route is the one whose `where` is non-empty.
        $constrained = array_values(array_filter(
            $this->arrayOfArrays($params),
            fn(array $p): bool => $this->hasPattern($p),
        ));
        self::assertNotEmpty($constrained);
        $first = $constrained[0];
        self::assertSame('id', $this->stringValue($first, 'name'));
        self::assertSame('path', $this->stringValue($first, 'in'));
        self::assertTrue($this->boolValue($first, 'required'));
        $schema = $this->arrayValue($first, 'schema');
        self::assertSame('[0-9]+', $this->stringValue($schema, 'pattern'));
        self::assertSame('string', $this->stringValue($schema, 'type'));
    }

    public function testOperationDecoratorReceivesRoute(): void
    {
        $router = new Router();
        $router->post('/users', static fn(Request $r, array $p): Response => Response::empty(201));

        $captured = null;
        $exporter = new OpenApiExporter(
            title: 'T',
            version: '1.0.0',
            operationDecorator: static function (array $op, \Framework\Http\Router\Route $r) use (&$captured): array {
                $captured = $r;
                $op['requestBody'] = ['$ref' => '#/components/requestBodies/CreateUser'];
                return $op;
            },
        );

        $doc = $exporter->build($router);

        self::assertNotNull($captured);
        self::assertSame('POST', $captured->method);
        self::assertSame('/users', $captured->path);
        $post = $this->arrayValue(
            $this->arrayValue($this->arrayValue($this->payload($doc), 'paths'), '/users'),
            'post',
        );
        self::assertSame(
            ['$ref' => '#/components/requestBodies/CreateUser'],
            $this->arrayValue($post, 'requestBody'),
        );
    }

    public function testWildcardRouteMapsToRestOfPath(): void
    {
        $router = new Router();
        $router->get('/files/*', static fn(Request $r, array $p): Response => Response::empty(200));
        $exporter = new OpenApiExporter(title: 'T', version: '1.0.0');

        $doc = $exporter->build($router);

        $paths = $this->arrayValue($this->payload($doc), 'paths');
        self::assertArrayHasKey('/files/{restOfPath}', $paths);
    }

    public function testPathParamDescriptionIncluded(): void
    {
        $router = new Router();
        $base = $router->get('/users/{id}', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->add($base->where('id', '[0-9]+'));
        $exporter = new OpenApiExporter(
            title: 'T',
            version: '1.0.0',
            pathParamDescriptions: ['id' => 'User identifier (UUID)'],
        );

        $doc = $exporter->build($router);

        $get = $this->arrayValue(
            $this->arrayValue(
                $this->arrayValue($this->payload($doc), 'paths'),
                '/users/{id}',
            ),
            'get',
        );
        $params = $this->arrayOfArrays($this->arrayValue($get, 'parameters'));
        $id = null;
        foreach ($params as $p) {
            if ($this->stringValue($p, 'name') === 'id') {
                $id = $p;
                break;
            }
        }
        self::assertNotNull($id);
        self::assertSame('User identifier (UUID)', $this->stringValue($id, 'description'));
    }

    public function testToJsonIsValidJson(): void
    {
        $router = new Router();
        $router->get('/health', static fn(Request $r, array $p): Response => Response::empty(200));
        $exporter = new OpenApiExporter(title: 'T', version: '1.0.0');

        $doc = $exporter->build($router);
        $json = $doc->toJson();

        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame('3.1.0', $decoded['openapi']);
    }

    public function testBuildIsDeterministic(): void
    {
        $router = new Router();
        $router->get('/a', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/b', static fn(Request $r, array $p): Response => Response::empty(200));
        $exporter = new OpenApiExporter(title: 'T', version: '1.0.0');

        $first = $exporter->build($router)->toJson();
        $second = $exporter->build($router)->toJson();

        self::assertSame($first, $second);
    }

    public function testPathsSortedLexicographically(): void
    {
        $router = new Router();
        $router->get('/zzz', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/aaa', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/mmm', static fn(Request $r, array $p): Response => Response::empty(200));
        $exporter = new OpenApiExporter(title: 'T', version: '1.0.0');

        $doc = $exporter->build($router);

        $paths = $this->arrayValue($this->payload($doc), 'paths');
        self::assertSame(['/aaa', '/mmm', '/zzz'], array_keys($paths));
    }

    public function testEmptyExcludePatternsKeepsAllRoutes(): void
    {
        // No exclusions at all → every registered route shows up in the
        // document. This is the regression-guard for the default config:
        // someone wiring up an exporter for the first time should see
        // their full route table, not a surprise filtered view.
        $router = new Router();
        $router->get('/users', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/_internal/health', static fn(Request $r, array $p): Response => Response::empty(200));
        $exporter = new OpenApiExporter(title: 'T', version: '1.0.0');

        $doc = $exporter->build($router);

        $paths = $this->arrayValue($this->payload($doc), 'paths');
        self::assertArrayHasKey('/users', $paths);
        self::assertArrayHasKey('/_internal/health', $paths);
    }

    public function testExcludesByLiteralPrefix(): void
    {
        // The `/_internal/` prefix is the canonical "hide the operator
        // surface from the public spec" pattern. Anything that starts
        // with `/_internal/` (or equals it exactly) must be dropped.
        $router = new Router();
        $router->get('/users', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/_internal/health', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/_internal/metrics', static fn(Request $r, array $p): Response => Response::empty(200));

        $exporter = new OpenApiExporter(
            title: 'T',
            version: '1.0.0',
            excludePatterns: ['/_internal/'],
        );

        $doc = $exporter->build($router);

        $paths = $this->arrayValue($this->payload($doc), 'paths');
        self::assertArrayHasKey('/users', $paths);
        self::assertArrayNotHasKey('/_internal/health', $paths);
        self::assertArrayNotHasKey('/_internal/metrics', $paths);
    }

    public function testExcludesByRegex(): void
    {
        // Delimiter-wrapped regex syntax: `'#^/admin/#'`. Anything that
        // starts and ends with the same delimiter character is treated
        // as a PCRE pattern. This is the escape hatch when literal
        // prefixes are not precise enough.
        $router = new Router();
        $router->get('/users', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/admin/dashboard', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/admin/users', static fn(Request $r, array $p): Response => Response::empty(200));

        $exporter = new OpenApiExporter(
            title: 'T',
            version: '1.0.0',
            excludePatterns: ['#^/admin/#'],
        );

        $doc = $exporter->build($router);

        $paths = $this->arrayValue($this->payload($doc), 'paths');
        self::assertArrayHasKey('/users', $paths);
        self::assertArrayNotHasKey('/admin/dashboard', $paths);
        self::assertArrayNotHasKey('/admin/users', $paths);
    }

    public function testWithExcludePatternsReturnsNewInstanceWithMergedList(): void
    {
        // `withExcludePatterns()` is the immutable builder pattern:
        // the original exporter MUST stay untouched (immutability), and
        // the returned instance must have its exclusion list MERGED with
        // the additional patterns — existing patterns are preserved,
        // new patterns are appended.
        $router = new Router();
        $router->get('/users', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/_internal/health', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/admin/dashboard', static fn(Request $r, array $p): Response => Response::empty(200));

        $base = new OpenApiExporter(
            title: 'T',
            version: '1.0.0',
            excludePatterns: ['/_internal/'],
        );

        // Sanity: the original exporter's pattern is in effect on the base.
        $baseDoc = $base->build($router);
        $basePaths = $this->arrayValue($this->payload($baseDoc), 'paths');
        self::assertArrayNotHasKey('/_internal/health', $basePaths);
        self::assertArrayHasKey('/admin/dashboard', $basePaths);

        $extended = $base->withExcludePatterns(['#^/admin/#']);
        self::assertNotSame($base, $extended, 'withExcludePatterns must return a new instance');

        $extendedDoc = $extended->build($router);
        $extendedPaths = $this->arrayValue($this->payload($extendedDoc), 'paths');
        self::assertArrayNotHasKey('/_internal/health', $extendedPaths);
        self::assertArrayNotHasKey('/admin/dashboard', $extendedPaths);
        self::assertArrayHasKey('/users', $extendedPaths);

        // Original exporter MUST still be untouched: re-running it
        // produces the same document as the first build, with only the
        // `/_internal/` exclusion.
        $baseDocAgain = $base->build($router);
        $basePathsAgain = $this->arrayValue($this->payload($baseDocAgain), 'paths');
        self::assertArrayHasKey('/admin/dashboard', $basePathsAgain, 'base exporter must remain immutable');
        self::assertArrayNotHasKey('/_internal/health', $basePathsAgain);
    }

    public function testExcludesExactMatchWithoutTrailingSlash(): void
    {
        // `/_internal` (no trailing slash) excludes:
        //   - the exact path `/_internal` (`$path === $pattern` branch), and
        //   - every sub-path under `/_internal/` (the prefix-with-slash
        //     branch: `str_starts_with($path, '/_internal/')`).
        // It does NOT exclude the lookalike `/internal` (which does not
        // start with `/_internal/`).
        $router = new Router();
        $router->get('/_internal', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/_internal/health', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/internal', static fn(Request $r, array $p): Response => Response::empty(200));

        $exporter = new OpenApiExporter(
            title: 'T',
            version: '1.0.0',
            excludePatterns: ['/_internal'],
        );

        $doc = $exporter->build($router);
        $paths = $this->arrayValue($this->payload($doc), 'paths');
        self::assertArrayNotHasKey('/_internal', $paths, 'exact path must be excluded');
        self::assertArrayNotHasKey('/_internal/health', $paths, 'sub-path must be excluded');
        self::assertArrayHasKey('/internal', $paths, 'lookalike /internal must NOT be excluded');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(OpenApiDocument $doc): array
    {
        return $doc->toArray();
    }

    /**
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    private function arrayValue(array $array, string $key): array
    {
        self::assertArrayHasKey($key, $array);
        $value = $array[$key];
        self::assertIsArray($value);
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $array
     */
    private function stringValue(array $array, string $key): string
    {
        self::assertArrayHasKey($key, $array);
        $value = $array[$key];
        self::assertIsString($value);
        return $value;
    }

    /**
     * @param array<string, mixed> $array
     */
    private function boolValue(array $array, string $key): bool
    {
        self::assertArrayHasKey($key, $array);
        $value = $array[$key];
        self::assertIsBool($value);
        return $value;
    }

    /**
     * @param array<string, mixed> $param
     */
    private function hasPattern(array $param): bool
    {
        if (!isset($param['schema']) || !is_array($param['schema'])) {
            return false;
        }
        /** @var array<string, mixed> $schema */
        $schema = $param['schema'];
        return isset($schema['pattern']) && is_string($schema['pattern']);
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private function arrayOfArrays(mixed $value): array
    {
        self::assertIsArray($value);
        $out = [];
        foreach ($value as $entry) {
            self::assertIsArray($entry);
            /** @var array<string, mixed> $entry */
            $out[] = $entry;
        }
        return $out;
    }
}
