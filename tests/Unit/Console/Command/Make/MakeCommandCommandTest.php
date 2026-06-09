<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Command\Make;

use Framework\Console\Command\Make\MakeCommandCommand;
use Framework\Console\Input\Input;
use Framework\Container\Container;
use Framework\Tests\Support\MakeScaffolderTestCase;
use Framework\Tests\Support\MemoryOutput;
use Framework\Tests\Support\PhpLinter;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MakeCommandCommand::class)]
final class MakeCommandCommandTest extends MakeScaffolderTestCase
{
    public function testNameAndDescription(): void
    {
        $cmd = new MakeCommandCommand(new Container(), $this->tmpDir);
        self::assertSame('make:command', $cmd->name());
        self::assertStringContainsString('Generate', $cmd->description());
    }

    public function testGeneratesClassFile(): void
    {
        $cmd = new MakeCommandCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(args: ['make:command', 'Hello']);

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $path = $this->tmpFile('HelloCommand.php');
        self::assertFileExists($path);

        $contents = (string) file_get_contents($path);
        self::assertStringContainsString('class HelloCommand extends Command', $contents);
        self::assertStringContainsString("return 'hello:run';", $contents);
        self::assertStringContainsString('namespace Framework\Console\Command;', $contents);
        self::assertTrue(PhpLinter::check($path));
    }

    public function testFailsWithoutArg(): void
    {
        $cmd = new MakeCommandCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(args: ['make:command']);

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Usage', $output->stdoutText());
        self::assertCount(0, glob($this->tmpFile('*.php')) ?: []);
    }

    public function testFailsOnInvalidName(): void
    {
        $cmd = new MakeCommandCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(args: ['make:command', '!!!']);

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Invalid', $output->stdoutText());
    }

    public function testRefusesOverwrite(): void
    {
        $cmd = new MakeCommandCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();

        $first = $cmd->execute(new Input(args: ['make:command', 'Hello']), $output);
        self::assertSame(0, $first);

        $output2 = new MemoryOutput();
        $second = $cmd->execute(new Input(args: ['make:command', 'Hello']), $output2);

        self::assertSame(1, $second);
        self::assertStringContainsString('already exists', $output2->stdoutText());
    }

    public function testCustomNameAndDescription(): void
    {
        $cmd = new MakeCommandCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:command', 'SendEmail'],
            options: ['name' => 'mail:send', 'description' => "Send 'an' email"],
        );

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $contents = (string) file_get_contents($this->tmpFile('SendEmailCommand.php'));
        self::assertStringContainsString("return 'mail:send';", $contents);
        self::assertStringContainsString("Send 'an' email", $contents);
    }
}
