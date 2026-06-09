<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Command\Make;

use Framework\Console\Command\Make\MakeControllerCommand;
use Framework\Console\Input\Input;
use Framework\Container\Container;
use Framework\Tests\Support\MakeScaffolderTestCase;
use Framework\Tests\Support\MemoryOutput;
use Framework\Tests\Support\PhpLinter;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MakeControllerCommand::class)]
final class MakeControllerCommandTest extends MakeScaffolderTestCase
{
    public function testNameAndDescription(): void
    {
        $cmd = new MakeControllerCommand(new Container(), $this->tmpDir);
        self::assertSame('make:controller', $cmd->name());
        self::assertStringContainsString('controller', $cmd->description());
    }

    public function testGeneratesClassFile(): void
    {
        $cmd = new MakeControllerCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(args: ['make:controller', 'Home']);

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $path = $this->tmpFile('HomeController.php');
        self::assertFileExists($path);

        $contents = (string) file_get_contents($path);
        self::assertStringContainsString('namespace App\Http\Controller;', $contents);
        self::assertStringContainsString('final readonly class HomeController', $contents);
        self::assertStringContainsString('use Framework\Http\Request\Request;', $contents);
        self::assertStringContainsString('use Framework\Http\Response\Response;', $contents);
        self::assertStringContainsString('public function index(Request $request): Response', $contents);
        self::assertStringContainsString('return Response::empty(200);', $contents);
        self::assertTrue(PhpLinter::check($path));
    }

    public function testFailsWithoutArg(): void
    {
        $cmd = new MakeControllerCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(args: ['make:controller']);

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Usage', $output->stdoutText());
        self::assertCount(0, glob($this->tmpFile('*.php')) ?: []);
    }

    public function testFailsOnInvalidName(): void
    {
        $cmd = new MakeControllerCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(args: ['make:controller', '!!!']);

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Invalid', $output->stdoutText());
    }

    public function testIdempotentControllerSuffix(): void
    {
        $cmd = new MakeControllerCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();

        $code = $cmd->execute(new Input(args: ['make:controller', 'HomeController']), $output);

        self::assertSame(0, $code);
        self::assertFileExists($this->tmpFile('HomeController.php'));
    }

    public function testRefusesOverwrite(): void
    {
        $cmd = new MakeControllerCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();

        $first = $cmd->execute(new Input(args: ['make:controller', 'Home']), $output);
        self::assertSame(0, $first);

        $output2 = new MemoryOutput();
        $second = $cmd->execute(new Input(args: ['make:controller', 'Home']), $output2);

        self::assertSame(1, $second);
        self::assertStringContainsString('already exists', $output2->stdoutText());
    }

    public function testDefaultRouteSlugIsKebabOfClassName(): void
    {
        $cmd = new MakeControllerCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(args: ['make:controller', 'UserProfile']);

        self::assertSame(0, $cmd->execute($input, $output));
        self::assertStringContainsString('user-profile', $output->stdoutText());
    }

    public function testCustomNameOption(): void
    {
        $cmd = new MakeControllerCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:controller', 'Home'],
            options: ['name' => 'home:index', 'description' => 'Render homepage'],
        );

        self::assertSame(0, $cmd->execute($input, $output));
        self::assertStringContainsString('home:index', $output->stdoutText());
        self::assertStringContainsString('Render homepage', $output->stdoutText());
    }

    public function testCustomNamespace(): void
    {
        $cmd = new MakeControllerCommand(
            new Container(),
            $this->tmpDir,
            'My\\App\\Http\\Controller',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:controller', 'Home']);

        self::assertSame(0, $cmd->execute($input, $output));

        $path = $this->tmpFile('HomeController.php');
        $contents = (string) file_get_contents($path);
        self::assertStringContainsString('namespace My\App\Http\Controller;', $contents);
    }

    public function testCreatesMissingTargetDirectory(): void
    {
        $nested = $this->tmpDir . '/Http/Controller';
        self::assertDirectoryDoesNotExist($nested);

        $cmd = new MakeControllerCommand(new Container(), $nested);
        $output = new MemoryOutput();
        $input = new Input(args: ['make:controller', 'Health']);

        self::assertSame(0, $cmd->execute($input, $output));
        self::assertDirectoryExists($nested);
        self::assertFileExists($nested . '/HealthController.php');
        self::assertStringContainsString('Created', $output->stdoutText());
    }
}
