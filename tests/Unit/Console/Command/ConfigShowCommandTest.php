<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Command;

use Framework\Config\Config;
use Framework\Console\Command\ConfigShowCommand;
use Framework\Console\Input\Input;
use Framework\Container\Container;
use Framework\Tests\Support\MemoryOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigShowCommand::class)]
final class ConfigShowCommandTest extends TestCase
{
    public function testNameAndDescription(): void
    {
        $cmd = new ConfigShowCommand(new Container(), Config::fromArray([]));
        self::assertSame('config:show', $cmd->name());
        self::assertSame('Display all loaded configuration values', $cmd->description());
    }

    public function testExecuteRendersConfigAsTable(): void
    {
        $config = Config::fromArray([
            'app.name' => 'framework',
            'app.version' => '0.4.0',
            'debug' => true,
        ]);
        $cmd = new ConfigShowCommand(new Container(), $config);
        $output = new MemoryOutput();

        $code = $cmd->execute(new Input(), $output);

        self::assertSame(0, $code);
        $written = $output->stdoutText();
        self::assertStringContainsString('| Key         | Value     |', $written);
        self::assertStringContainsString('| app.name    | framework |', $written);
        self::assertStringContainsString('| app.version | 0.4.0     |', $written);
        self::assertStringContainsString('| debug       | 1         |', $written);
    }

    public function testExecuteWithEmptyConfigRendersOnlyHeader(): void
    {
        $cmd = new ConfigShowCommand(new Container(), Config::fromArray([]));
        $output = new MemoryOutput();

        $code = $cmd->execute(new Input(), $output);

        self::assertSame(0, $code);
        $written = $output->stdoutText();
        self::assertStringContainsString('| Key | Value |', $written);
        self::assertStringNotContainsString('| app', $written);
    }
}
