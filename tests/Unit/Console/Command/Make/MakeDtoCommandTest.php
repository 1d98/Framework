<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Command\Make;

use Framework\Console\Command\Make\MakeDtoCommand;
use Framework\Console\Input\Input;
use Framework\Container\Container;
use Framework\Tests\Support\MakeScaffolderTestCase;
use Framework\Tests\Support\MemoryOutput;
use Framework\Tests\Support\PhpLinter;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MakeDtoCommand::class)]
final class MakeDtoCommandTest extends MakeScaffolderTestCase
{
    public function testNameAndDescription(): void
    {
        $cmd = new MakeDtoCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Validation\\Dto',
        );
        self::assertSame('make:dto', $cmd->name());
        self::assertStringContainsString('DTO', $cmd->description());
    }

    public function testGeneratesClassFileWithRequestSuffix(): void
    {
        $cmd = new MakeDtoCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Validation\\Dto',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:dto', 'CreateUser']);

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $path = $this->tmpFile('CreateUserRequest.php');
        self::assertFileExists($path);

        $contents = (string) file_get_contents($path);
        self::assertStringContainsString('namespace App\Validation\Dto;', $contents);
        self::assertStringContainsString('class CreateUserRequest', $contents);
        self::assertStringContainsString('use Framework\Validation\Attribute\Validate;', $contents);
        self::assertStringContainsString('use Framework\Validation\Rule\EmailRule;', $contents);
        self::assertStringContainsString('public string $example,', $contents);
        self::assertStringContainsString('TODO: add #[Validate(...)] attributes on each property.', $contents);
        self::assertStringContainsString('#[Validate(EmailRule::class)] on a `string $email` property.', $contents);
        self::assertTrue(PhpLinter::check($path));
    }

    public function testFailsWithoutArg(): void
    {
        $cmd = new MakeDtoCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Validation\\Dto',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:dto']);

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Usage', $output->stdoutText());
        self::assertCount(0, glob($this->tmpFile('*.php')) ?: []);
    }

    public function testFailsOnInvalidName(): void
    {
        $cmd = new MakeDtoCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Validation\\Dto',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:dto', '!!!']);

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Invalid', $output->stdoutText());
    }

    public function testIdempotentRequestSuffix(): void
    {
        $cmd = new MakeDtoCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Validation\\Dto',
        );
        $output = new MemoryOutput();

        $code = $cmd->execute(new Input(args: ['make:dto', 'CreateUserRequest']), $output);

        self::assertSame(0, $code);
        self::assertFileExists($this->tmpFile('CreateUserRequest.php'));
    }

    public function testRefusesOverwrite(): void
    {
        $cmd = new MakeDtoCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Validation\\Dto',
        );
        $output = new MemoryOutput();

        $first = $cmd->execute(new Input(args: ['make:dto', 'CreateUser']), $output);
        self::assertSame(0, $first);

        $output2 = new MemoryOutput();
        $second = $cmd->execute(new Input(args: ['make:dto', 'CreateUser']), $output2);

        self::assertSame(1, $second);
        self::assertStringContainsString('already exists', $output2->stdoutText());
    }

    public function testCustomSuffix(): void
    {
        $cmd = new MakeDtoCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'App\\Validation\\Dto',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:dto', 'User'], options: ['suffix' => 'Payload']);

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        self::assertFileExists($this->tmpFile('UserPayload.php'));
    }

    public function testCreatesMissingTargetDirectory(): void
    {
        $nested = $this->tmpDir . '/Validation/Dto';
        self::assertDirectoryDoesNotExist($nested);

        $cmd = new MakeDtoCommand(
            new Container(),
            $nested,
            namespaceOverride: 'App\\Validation\\Dto',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:dto', 'CreateUser']);

        self::assertSame(0, $cmd->execute($input, $output));
        self::assertDirectoryExists($nested);
        self::assertFileExists($nested . '/CreateUserRequest.php');
        self::assertStringContainsString('Created', $output->stdoutText());
    }
}
