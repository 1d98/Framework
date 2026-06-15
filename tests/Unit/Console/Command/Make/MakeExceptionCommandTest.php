<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Command\Make;

use Framework\Console\Command\Make\MakeExceptionCommand;
use Framework\Console\Input\Input;
use Framework\Container\Container;
use Framework\Http\Exception\HttpException;
use Framework\Tests\Support\MakeScaffolderTestCase;
use Framework\Tests\Support\MemoryOutput;
use Framework\Tests\Support\PhpLinter;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MakeExceptionCommand::class)]
final class MakeExceptionCommandTest extends MakeScaffolderTestCase
{
    public function testNameAndDescription(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        self::assertSame('make:exception', $cmd->name());
        self::assertStringContainsString('exception', $cmd->description());
    }

    public function testGeneratesClassFileWithStatusAndMessage(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:exception', 'PaymentRequired'],
            options: ['status' => '402', 'message' => 'Payment required'],
        );

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $path = $this->tmpFile('PaymentRequiredException.php');
        self::assertFileExists($path);

        $contents = (string) file_get_contents($path);
        self::assertStringContainsString('namespace App\Http\Exception;', $contents);
        self::assertStringContainsString('use Framework\Http\Exception\HttpException;', $contents);
        self::assertStringContainsString('final class PaymentRequiredException extends HttpException', $contents);
        self::assertStringContainsString("public function __construct(string \$message = 'Payment required', ?Throwable \$previous = null)", $contents);
        self::assertStringContainsString("parent::__construct(402, \$message, 'about:blank', \$previous);", $contents);
        self::assertTrue(PhpLinter::check($path));
    }

    public function testFailsWithoutArg(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:exception']);

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Usage', $output->stdoutText());
        self::assertCount(0, glob($this->tmpFile('*.php')) ?: []);
    }

    public function testFailsOnInvalidName(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:exception', '!!!']);

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Invalid', $output->stdoutText());
    }

    public function testIdempotentExceptionSuffix(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();

        $code = $cmd->execute(
            new Input(args: ['make:exception', 'TeapotException']),
            $output,
        );

        self::assertSame(0, $code);
        self::assertFileExists($this->tmpFile('TeapotException.php'));
    }

    public function testRefusesOverwrite(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();

        $first = $cmd->execute(
            new Input(
                args: ['make:exception', 'PaymentRequired'],
                options: ['status' => '402'],
            ),
            $output,
        );
        self::assertSame(0, $first);

        $output2 = new MemoryOutput();
        $second = $cmd->execute(
            new Input(
                args: ['make:exception', 'PaymentRequired'],
                options: ['status' => '402'],
            ),
            $output2,
        );

        self::assertSame(1, $second);
        self::assertStringContainsString('already exists', $output2->stdoutText());
    }

    public function testRejectsStatusBelowErrorRange(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:exception', 'Foo'],
            options: ['status' => '200'],
        );

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Invalid status', $output->stdoutText());
        self::assertCount(0, glob($this->tmpFile('*.php')) ?: []);
    }

    public function testRejectsNonNumericStatus(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:exception', 'Foo'],
            options: ['status' => 'abc'],
        );

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Invalid', $output->stdoutText());
    }

    public function testRejectsStatusAboveMax(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:exception', 'Foo'],
            options: ['status' => '600'],
        );

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Invalid status', $output->stdoutText());
    }

    public function testDefaultStatusIs500(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:exception', 'Server']);

        self::assertSame(0, $cmd->execute($input, $output));

        $path = $this->tmpFile('ServerException.php');
        $contents = (string) file_get_contents($path);
        self::assertStringContainsString('parent::__construct(500,', $contents);
    }

    public function testGeneratedFileLintsWithPhpL(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:exception', 'Foo'],
            options: ['message' => "hello\nworld"],
        );

        self::assertSame(0, $cmd->execute($input, $output));
        self::assertTrue(PhpLinter::check($this->tmpFile('FooException.php')));
    }

    public function testMessageWithApostropheIsEscaped(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:exception', 'Foo'],
            options: ['message' => "it's mine"],
        );

        self::assertSame(0, $cmd->execute($input, $output));

        $contents = (string) file_get_contents($this->tmpFile('FooException.php'));
        self::assertStringContainsString(var_export("it's mine", true), $contents);
        self::assertTrue(PhpLinter::check($this->tmpFile('FooException.php')));
    }

    public function testMessageWithRealNewlineIsPreserved(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:exception', 'Foo'],
            options: ['message' => "line1\nline2"],
        );

        self::assertSame(0, $cmd->execute($input, $output));

        $contents = (string) file_get_contents($this->tmpFile('FooException.php'));
        self::assertStringContainsString(var_export("line1\nline2", true), $contents);
        self::assertStringNotContainsString('\\n', $contents);
        self::assertTrue(PhpLinter::check($this->tmpFile('FooException.php')));
    }

    public function testMessageWithDoubleQuotesRoundTrips(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:exception', 'Foo'],
            options: ['message' => 'with "quotes"'],
        );

        self::assertSame(0, $cmd->execute($input, $output));

        $contents = (string) file_get_contents($this->tmpFile('FooException.php'));
        self::assertStringContainsString(var_export('with "quotes"', true), $contents);
        self::assertTrue(PhpLinter::check($this->tmpFile('FooException.php')));
    }

    public function testMessageWithBackslashIsEscaped(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:exception', 'Foo'],
            options: ['message' => 'back\slash'],
        );

        self::assertSame(0, $cmd->execute($input, $output));

        $contents = (string) file_get_contents($this->tmpFile('FooException.php'));
        self::assertStringContainsString(var_export('back\slash', true), $contents);
        self::assertTrue(PhpLinter::check($this->tmpFile('FooException.php')));
    }

    public function testRejectsNameCollidingWithBuiltInHttpException(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:exception', 'Conflict']);

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('ConflictHttpException', $output->stdoutText());
        self::assertStringContainsString('already exists', $output->stdoutText());
        self::assertCount(0, glob($this->tmpFile('*.php')) ?: []);
    }

    public function testRejectsNotFoundNameBecauseBuiltInExists(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:exception', 'NotFound']);

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('NotFoundHttpException', $output->stdoutText());
        self::assertCount(0, glob($this->tmpFile('*.php')) ?: []);
    }

    public function testAcceptsNotImplementedNameBecauseNoBuiltInExists(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:exception', 'NotImplemented'],
            options: ['status' => '501'],
        );

        self::assertSame(0, $cmd->execute($input, $output));
        self::assertFileExists($this->tmpFile('NotImplementedException.php'));
    }

    public function testAcceptsTeapotNameBecauseNoBuiltInExists(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:exception', 'Teapot'],
            options: ['status' => '418'],
        );

        self::assertSame(0, $cmd->execute($input, $output));
        self::assertFileExists($this->tmpFile('TeapotException.php'));
    }

    public function testAcceptsClearlyCustomName(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:exception', 'Foo']);

        self::assertSame(0, $cmd->execute($input, $output));
        self::assertFileExists($this->tmpFile('FooException.php'));
    }

    public function testCollisionCheckAlsoCatchesBaseClassItself(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:exception', 'HttpException'],
            options: ['status' => '500'],
        );

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('HttpException', $output->stdoutText());
        self::assertCount(0, glob($this->tmpFile('*.php')) ?: []);
    }

    public function testGeneratedClassInstantiatesWithOriginalMessage(): void
    {
        $cmd = new MakeExceptionCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $message = "line1\nline2 with it's tricky \\back\\slash and \"quotes\"";
        $input = new Input(
            args: ['make:exception', 'Foo'],
            options: ['status' => '418', 'message' => $message],
        );

        self::assertSame(0, $cmd->execute($input, $output));

        $path = $this->tmpFile('FooException.php');
        self::assertTrue(PhpLinter::check($path));

        $contents = (string) file_get_contents($path);
        $className = 'Framework\\Tests\\Unit\\Console\\Command\\Make\\Generated_FooException_' . bin2hex(random_bytes(4));
        $separatorPos = strrpos($className, '\\');
        self::assertIsInt($separatorPos);
        $shortName = substr($className, $separatorPos + 1);
        $ns = substr($className, 0, $separatorPos);
        $prefixed = preg_replace(
            '/^namespace\s+[^;]+;/m',
            'namespace ' . $ns . ';',
            $contents,
            1,
        );
        self::assertNotNull($prefixed);
        $prefixed = preg_replace(
            '/final\s+class\s+FooException\s+extends\s+HttpException/',
            'final class ' . $shortName . ' extends \\Framework\\Http\\Exception\\HttpException',
            $prefixed,
            1,
        );
        self::assertNotNull($prefixed);

        $tmp = sys_get_temp_dir() . '/mec-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($tmp, $prefixed);
        try {
            require_once $tmp;
            $instance = new $className();
            self::assertInstanceOf(HttpException::class, $instance);
            self::assertSame($message, $instance->getMessage());
            self::assertSame(418, $instance->statusCode);
        } finally {
            @unlink($tmp);
        }
    }

    public function testCreatesMissingTargetDirectory(): void
    {
        $nested = $this->tmpDir . '/Http/Exception';
        self::assertDirectoryDoesNotExist($nested);

        $cmd = new MakeExceptionCommand(
            new Container(),
            $nested,
            namespaceOverride: 'App\\Http\\Exception',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:exception', 'Teapot']);

        self::assertSame(0, $cmd->execute($input, $output));
        self::assertDirectoryExists($nested);
        self::assertFileExists($nested . '/TeapotException.php');
        self::assertStringContainsString('Created', $output->stdoutText());
    }
}
