<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Command\Make;

use Framework\Console\Command\Make\MakeDtoCommand;
use Framework\Console\Command\Make\MakeRuleCommand;
use Framework\Console\Input\Input;
use Framework\Container\Container;
use Framework\Tests\Support\MakeScaffolderTestCase;
use Framework\Tests\Support\MemoryOutput;
use Framework\Tests\Support\PhpLinter;
use Framework\Validation\Rule\RuleInterface;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MakeRuleCommand::class)]
#[CoversClass(MakeDtoCommand::class)]
final class ScaffolderTemplateDriftTest extends MakeScaffolderTestCase
{
    /** @var list<string> */
    private array $generated = [];

    protected function tearDown(): void
    {
        foreach ($this->generated as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->generated = [];

        parent::tearDown();
    }

    public function testRuleTemplateImplementsRuleInterfaceAndExposesName(): void
    {
        $cmd = new MakeRuleCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'Framework\\Validation\\Rule',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:rule', 'DriftTest']);

        self::assertSame(0, $cmd->execute($input, $output));

        $class = 'DriftTestRule';
        $path = $this->tmpFile($class . '.php');
        $this->generated[] = $path;
        self::assertFileExists($path);

        self::assertTrue(PhpLinter::check($path), "Generated rule file has syntax errors: {$path}");

        require_once $path;
        $fqcn = 'Framework\\Validation\\Rule\\' . $class;
        self::assertTrue(class_exists($fqcn, false), "Generated class {$fqcn} is not loaded");

        $instance = new $fqcn();
        self::assertInstanceOf(RuleInterface::class, $instance);

        $name = $instance->name();
        self::assertNotSame('', $name, 'Rule name() must return a non-empty string');
        self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $name, "Rule name '{$name}' is not kebab-case-ish");

        $result = $instance->validate('hello', []);
        $typeOk = $result === null || gettype($result) === 'string';
        self::assertTrue($typeOk, 'validate() must return null or a string');
    }

    public function testDtoTemplateIsFinalReadonlyAndLintsClean(): void
    {
        $cmd = new MakeDtoCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'Framework\\Validation\\Dto',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:dto', 'DriftTest']);

        self::assertSame(0, $cmd->execute($input, $output));

        $class = 'DriftTestRequest';
        $path = $this->tmpFile($class . '.php');
        $this->generated[] = $path;
        self::assertFileExists($path);

        self::assertTrue(PhpLinter::check($path), "Generated DTO file has syntax errors: {$path}");

        $contents = (string) file_get_contents($path);
        self::assertStringContainsString('final readonly class ' . $class, $contents);
    }
}
