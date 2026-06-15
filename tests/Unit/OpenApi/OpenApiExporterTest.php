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
