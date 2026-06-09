<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Command;

use Framework\Console\Application;
use Framework\Console\Command\ListCommand;
use Framework\Console\Command\CommandInterface;
use Framework\Console\Input\Input;
use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;
use Framework\Container\Container;
use Framework\Tests\Support\MemoryOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListCommand::class)]
final class ListCommandTest extends TestCase
{
    public function testNameAndDescription(): void
    {
        $cmd = new ListCommand(new Container(), $this->makeApp());
        self::assertSame('list', $cmd->name());
        self::assertSame('Show all registered commands', $cmd->description());
    }

    public function testExecuteWritesHeaderAndCommands(): void
    {
        $container = new Container();
        $app = new Application($container, 'My App', '1.0.0');
        $app->add(new class implements CommandInterface {
            public function name(): string { return 'foo'; }
            public function description(): string { return 'Foo cmd'; }
            public function execute(InputInterface $i, OutputInterface $o): int { return 0; }
        });
        $app->add(new class implements CommandInterface {
            public function name(): string { return 'bar'; }
            public function description(): string { return 'Bar cmd'; }
            public function execute(InputInterface $i, OutputInterface $o): int { return 0; }
        });
        $cmd = new ListCommand($container, $app);

        $output = new MemoryOutput();

        $code = $cmd->execute(new Input(), $output);

        self::assertSame(0, $code);
        $written = $output->stdoutText();
        self::assertStringContainsString('My App 1.0.0', $written);
        self::assertStringContainsString('foo', $written);
        self::assertStringContainsString('Foo cmd', $written);
        self::assertStringContainsString('bar', $written);
        self::assertStringContainsString('Bar cmd', $written);
    }

    private function makeApp(): Application
    {
        return new Application(new Container(), 'T', '0');
    }
}
