<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Command;

use Framework\Console\Command\RoutesListCommand;
use Framework\Console\Input\Input;
use Framework\Container\Container;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;
use Framework\Tests\Support\MemoryOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoutesListCommand::class)]
final class RoutesListCommandTest extends TestCase
{
    public function testNameAndDescription(): void
    {
        $cmd = new RoutesListCommand(new Container(), new Router());
        self::assertSame('routes:list', $cmd->name());
        self::assertStringContainsString('Show all registered HTTP routes', $cmd->description());
    }

    public function testJsonOutput(): void
    {
        $router = new Router();
        $router->get('/users', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->post('/users', static fn(Request $r, array $p): Response => Response::empty(201));
        $cmd = new RoutesListCommand(new Container(), $router);

        $output = new MemoryOutput();
        $input = new Input(args: ['routes:list'], options: ['json' => 'true']);
        self::assertSame(0, $cmd->execute($input, $output));

        $body = $output->stdoutText();
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        $first = $this->assertAndReturn($decoded, 0);
        $second = $this->assertAndReturn($decoded, 1);
        self::assertSame('GET', $first['method']);
        self::assertSame('/users', $first['path']);
        self::assertSame('POST', $second['method']);
    }

    public function testJsonOutputIncludesPathParamsAndWhere(): void
    {
        $router = new Router();
        $base = $router->get('/users/{id}', static fn(Request $r, array $p): Response => Response::empty(200));
        $router->add($base->where('id', '[0-9]+'));
        $cmd = new RoutesListCommand(new Container(), $router);

        $output = new MemoryOutput();
        $input = new Input(args: ['routes:list'], options: ['json' => 'true']);
        self::assertSame(0, $cmd->execute($input, $output));

        $body = $output->stdoutText();
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        $constrained = null;
        foreach ($decoded as $entry) {
            self::assertIsArray($entry);
            if (!empty($entry['where'])) {
                $constrained = $entry;
                break;
            }
        }
        self::assertNotNull($constrained, 'A constrained route must appear in the output');
        self::assertSame(['id'], $constrained['params']);
        self::assertSame(['id' => '[0-9]+'], $constrained['where']);
    }

    /**
     * @param array<mixed> $list
     * @return array<string, mixed>
     */
    private function assertAndReturn(array $list, int $index): array
    {
        self::assertArrayHasKey($index, $list);
        $value = $list[$index];
        self::assertIsArray($value);
        /** @var array<string, mixed> $value */
        return $value;
    }

    public function testExecuteRendersRoutesAsTable(): void
    {
        $router = new Router();
        $router->get('/users', static fn() => new \Framework\Http\Response\Response());
        $router->post('/users', static fn() => new \Framework\Http\Response\Response());
        $router->get('/users/{id}', static fn() => new \Framework\Http\Response\Response());

        $cmd = new RoutesListCommand(new Container(), $router);
        $output = new MemoryOutput();

        $code = $cmd->execute(new Input(), $output);

        self::assertSame(0, $code);
        $written = $output->stdoutText();
        self::assertStringContainsString('| Method | Path        |', $written);
        self::assertStringContainsString('/users', $written);
        self::assertStringContainsString('GET', $written);
        self::assertStringContainsString('POST', $written);
    }

    public function testExecuteWithEmptyRouterRendersOnlyHeader(): void
    {
        $cmd = new RoutesListCommand(new Container(), new Router());
        $output = new MemoryOutput();

        $code = $cmd->execute(new Input(), $output);

        self::assertSame(0, $code);
        $written = $output->stdoutText();
        self::assertStringContainsString('| Method | Path |', $written);
    }
}
