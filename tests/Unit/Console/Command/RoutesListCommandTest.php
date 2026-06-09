<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Command;

use Framework\Console\Command\RoutesListCommand;
use Framework\Console\Input\Input;
use Framework\Container\Container;
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
        self::assertSame('Show all registered HTTP routes', $cmd->description());
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
