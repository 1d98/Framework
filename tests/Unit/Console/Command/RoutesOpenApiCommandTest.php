<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Command;

use Framework\Console\Command\RoutesOpenApiCommand;
use Framework\Console\Input\Input;
use Framework\Container\Container;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;
use Framework\OpenApi\OpenApiExporter;
use Framework\Tests\Support\MemoryOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoutesOpenApiCommand::class)]
final class RoutesOpenApiCommandTest extends TestCase
{
    public function testNameAndDescription(): void
    {
        $cmd = $this->makeCommand();

        self::assertSame('routes:openapi', $cmd->name());
        self::assertStringContainsString('Export the route table', $cmd->description());
        self::assertStringContainsString('--exclude', $cmd->description());
    }

    public function testNoExcludeFlagEmitsAllRoutes(): void
    {
        $router = new Router();
        $router->get('/users', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/_internal/health', static fn(Request $r, array $p): Response => Response::empty(200));

        $cmd = $this->makeCommand($router);
        $output = new MemoryOutput();
        $input = new Input();

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $decoded = $this->assertDecodedObject(json_decode($output->stdoutText(), true));
        $paths = $this->arrayValue($decoded, 'paths');
        self::assertArrayHasKey('/users', $paths);
        self::assertArrayHasKey('/_internal/health', $paths);
    }

    public function testExcludeFlagFiltersRoutes(): void
    {
        // `--exclude=/_internal/` drops every route whose path is the
        // exact pattern OR starts with the pattern + `/`. The other
        // routes remain visible.
        $router = new Router();
        $router->get('/users', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/_internal/health', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/_internal/metrics', static fn(Request $r, array $p): Response => Response::empty(200));

        $cmd = $this->makeCommand($router);
        $output = new MemoryOutput();
        $input = new Input(options: ['exclude' => '/_internal/']);

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $decoded = $this->assertDecodedObject(json_decode($output->stdoutText(), true));
        $paths = $this->arrayValue($decoded, 'paths');
        self::assertArrayHasKey('/users', $paths);
        self::assertArrayNotHasKey('/_internal/health', $paths);
        self::assertArrayNotHasKey('/_internal/metrics', $paths);
    }

    public function testCommaSeparatedExcludeList(): void
    {
        // `--exclude=/_internal/,/admin/` — the CLI parses one
        // comma-separated string and forwards each trimmed non-empty
        // piece as an exclusion pattern.
        $router = new Router();
        $router->get('/users', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/_internal/health', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/admin/dashboard', static fn(Request $r, array $p): Response => Response::empty(200));

        $cmd = $this->makeCommand($router);
        $output = new MemoryOutput();
        $input = new Input(options: ['exclude' => '/_internal/,/admin/']);

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $decoded = $this->assertDecodedObject(json_decode($output->stdoutText(), true));
        $paths = $this->arrayValue($decoded, 'paths');
        self::assertArrayHasKey('/users', $paths);
        self::assertArrayNotHasKey('/_internal/health', $paths);
        self::assertArrayNotHasKey('/admin/dashboard', $paths);
    }

    public function testEmptyExcludeOptionTreatedAsAbsent(): void
    {
        // An empty string is the same as omitting the flag — defensive
        // parsing so a stray `--exclude=` on the command line does not
        // accidentally filter every route.
        $router = new Router();
        $router->get('/users', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->get('/_internal/health', static fn(Request $r, array $p): Response => Response::empty(200));

        $cmd = $this->makeCommand($router);
        $output = new MemoryOutput();
        $input = new Input(options: ['exclude' => '']);

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $decoded = $this->assertDecodedObject(json_decode($output->stdoutText(), true));
        $paths = $this->arrayValue($decoded, 'paths');
        self::assertArrayHasKey('/users', $paths);
        self::assertArrayHasKey('/_internal/health', $paths);
    }

    public function testExcludeWithOnlyCommasAndSpacesTreatedAsAbsent(): void
    {
        // The implementation trims each comma-separated piece and skips
        // empty entries — so `--exclude=,, ,` is the same as omitting
        // the flag.
        $router = new Router();
        $router->get('/_internal/health', static fn(Request $r, array $p): Response => Response::empty(200));

        $cmd = $this->makeCommand($router);
        $output = new MemoryOutput();
        $input = new Input(options: ['exclude' => '  , ,  ']);

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $decoded = $this->assertDecodedObject(json_decode($output->stdoutText(), true));
        $paths = $this->arrayValue($decoded, 'paths');
        self::assertArrayHasKey('/_internal/health', $paths);
    }

    public function testOutOptionWritesToFile(): void
    {
        // `--out=/path/to/openapi.json` writes the document to disk
        // instead of stdout. The success line goes to stdout regardless.
        $router = new Router();
        $router->get('/users', static fn(Request $r, array $p): Response => Response::empty(200));

        $tmp = tempnam(sys_get_temp_dir(), 'openapi_cmd_');
        self::assertIsString($tmp);

        try {
            $cmd = $this->makeCommand($router);
            $output = new MemoryOutput();
            $input = new Input(options: ['out' => $tmp]);

            $code = $cmd->execute($input, $output);

            self::assertSame(0, $code);
            self::assertFileExists($tmp);
            $contents = file_get_contents($tmp);
            self::assertIsString($contents);
            $decoded = $this->assertDecodedObject(json_decode($contents, true));
            self::assertSame('3.1.0', $decoded['openapi']);

            $stdout = $output->stdoutText();
            self::assertStringContainsString('Wrote', $stdout);
            self::assertStringContainsString($tmp, $stdout);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function testOutOptionFailureReturnsNonZeroExit(): void
    {
        // Writing to a path the user can't write to (a directory that
        // does not exist) must produce a danger message AND a non-zero
        // exit code, not a silent failure.
        $router = new Router();
        $router->get('/users', static fn(Request $r, array $p): Response => Response::empty(200));

        $bogusPath = sys_get_temp_dir() . '/nonexistent_dir_' . uniqid() . '/openapi.json';

        $cmd = $this->makeCommand($router);
        $output = new MemoryOutput();
        $input = new Input(options: ['out' => $bogusPath]);

        $code = $cmd->execute($input, $output);

        self::assertSame(1, $code);
        self::assertStringContainsString('Failed to write', $output->stdoutText());
    }

    private function makeCommand(?Router $router = null): RoutesOpenApiCommand
    {
        $router ??= new Router();
        $exporter = new OpenApiExporter(title: 'T', version: '1.0.0');
        return new RoutesOpenApiCommand(new Container(), $router, $exporter);
    }

    /**
     * @param mixed $decoded
     * @return array<string, mixed>
     */
    private function assertDecodedObject(mixed $decoded): array
    {
        self::assertIsArray($decoded);
        self::assertNotEmpty($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
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
}