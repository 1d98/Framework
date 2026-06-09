<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Command\Make;

use Framework\Console\Command\Make\MakeMiddlewareCommand;
use Framework\Console\Input\Input;
use Framework\Container\Container;
use Framework\Tests\Support\MakeScaffolderTestCase;
use Framework\Tests\Support\MemoryOutput;
use Framework\Tests\Support\PhpLinter;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MakeMiddlewareCommand::class)]
final class MakeMiddlewareCommandTest extends MakeScaffolderTestCase
{
    public function testNameAndDescription(): void
    {
        $cmd = new MakeMiddlewareCommand(new Container(), $this->tmpDir);
        self::assertSame('make:middleware', $cmd->name());
        self::assertStringContainsString('middleware', $cmd->description());
    }

    public function testGeneratesClassFile(): void
    {
        $cmd = new MakeMiddlewareCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(args: ['make:middleware', 'Auth']);

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $path = $this->tmpFile('AuthMiddleware.php');
        self::assertFileExists($path);

        $contents = (string) file_get_contents($path);
        self::assertStringContainsString('namespace Framework\Http\Middleware;', $contents);
        self::assertStringContainsString('class AuthMiddleware implements MiddlewareInterface', $contents);
        self::assertStringContainsString('public function process(Request $request, callable $next): Response', $contents);
        self::assertTrue(PhpLinter::check($path));
    }

    public function testFailsWithoutArg(): void
    {
        $cmd = new MakeMiddlewareCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(args: ['make:middleware']);

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Usage', $output->stdoutText());
        self::assertCount(0, glob($this->tmpFile('*.php')) ?: []);
    }

    public function testFailsOnInvalidName(): void
    {
        $cmd = new MakeMiddlewareCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(args: ['make:middleware', '!!!']);

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Invalid', $output->stdoutText());
    }

    public function testIdempotentMiddlewareSuffix(): void
    {
        $cmd = new MakeMiddlewareCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();

        $code = $cmd->execute(new Input(args: ['make:middleware', 'AuthMiddleware']), $output);

        self::assertSame(0, $code);
        self::assertFileExists($this->tmpFile('AuthMiddleware.php'));
    }

    public function testRefusesOverwrite(): void
    {
        $cmd = new MakeMiddlewareCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();

        $first = $cmd->execute(new Input(args: ['make:middleware', 'Auth']), $output);
        self::assertSame(0, $first);

        $output2 = new MemoryOutput();
        $second = $cmd->execute(new Input(args: ['make:middleware', 'Auth']), $output2);

        self::assertSame(1, $second);
        self::assertStringContainsString('already exists', $output2->stdoutText());
    }
}
