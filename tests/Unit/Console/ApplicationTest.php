<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console;

use Framework\Console\Application;
use Framework\Console\Command\CommandInterface;
use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;
use Framework\Container\Container;
use Framework\Tests\Support\MemoryOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Application::class)]
final class ApplicationTest extends TestCase
{
    private Container $container;

    private MemoryOutput $output;

    private string|false $previousAppDebug;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->output = new MemoryOutput();
        $this->previousAppDebug = getenv('APP_DEBUG');
    }

    protected function tearDown(): void
    {
        if ($this->previousAppDebug === false) {
            putenv('APP_DEBUG');
        } else {
            putenv('APP_DEBUG=' . $this->previousAppDebug);
        }
    }

    public function testNameAndVersionDefaults(): void
    {
        $app = new Application($this->container, defaultOutput: $this->output);
        self::assertSame('Framework Console', $app->name());
        self::assertSame(\Framework\Framework::VERSION, $app->version());
    }

    public function testNameAndVersionCustom(): void
    {
        $app = new Application($this->container, 'My Tool', '1.2.3', $this->output);
        self::assertSame('My Tool', $app->name());
        self::assertSame('1.2.3', $app->version());
    }

    public function testAddReturnsSelf(): void
    {
        $app = $this->makeApp();
        $cmd = $this->makeCommand('test', 'A test command');
        self::assertSame($app, $app->add($cmd));
    }

    public function testCommandsReturnsMap(): void
    {
        $app = $this->makeApp();
        $app->add($this->makeCommand('foo', 'Foo command'));
        $app->add($this->makeCommand('bar', 'Bar command'));
        self::assertSame(['foo' => 'Foo command', 'bar' => 'Bar command'], $app->commands());
    }

    public function testAddLastWinsOnCollision(): void
    {
        $app = $this->makeApp();
        $app->add($this->makeCommand('foo', 'First'));
        $app->add($this->makeCommand('foo', 'Second'));
        self::assertSame(['foo' => 'Second'], $app->commands());
    }

    public function testInputThrowsBeforeRun(): void
    {
        $app = $this->makeApp();
        $this->expectException(\LogicException::class);
        $app->input();
    }

    public function testOutputThrowsBeforeRun(): void
    {
        $app = $this->makeApp();
        $this->expectException(\LogicException::class);
        $app->output();
    }

    public function testRunReturnsZeroForVersionFlag(): void
    {
        $app = $this->makeApp('X', '9.9.9');
        $code = $app->run(['bin/framework', '--version']);
        self::assertSame(0, $code);
        self::assertSame("X 9.9.9\n", $this->output->stdoutText());
    }

    public function testRunReturnsZeroForHelpFlag(): void
    {
        $app = $this->makeApp('X', '9.9.9');
        $code = $app->run(['bin/framework', '--help']);
        self::assertSame(0, $code);
        $captured = $this->output->stdoutText();
        self::assertStringContainsString('X 9.9.9', $captured);
        self::assertStringContainsString('--version', $captured);
        self::assertStringContainsString('--help', $captured);
    }

    public function testRunExecutesDefaultListWhenNoArgs(): void
    {
        $app = $this->makeApp();
        $executed = false;
        $app->add(new class ('list', 'List', function () use (&$executed): int {
            $executed = true;
            return 0;
        }) extends FakeCommand {});
        $code = $app->run(['bin/framework']);
        self::assertSame(0, $code);
        self::assertTrue($executed);
    }

    public function testRunDispatchesByCommandName(): void
    {
        $app = $this->makeApp();
        $executed = false;
        $app->add(new class ('hello', 'Hello', function () use (&$executed): int {
            $executed = true;
            return 0;
        }) extends FakeCommand {});
        $code = $app->run(['bin/framework', 'hello']);
        self::assertSame(0, $code);
        self::assertTrue($executed);
    }

    public function testRunReturnsOneForUnknownCommand(): void
    {
        $app = $this->makeApp();
        $code = $app->run(['bin/framework', 'unknown']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Command not found: unknown', $this->output->stderrText());
    }

    public function testRunReturnsTwoWhenCommandThrows(): void
    {
        $app = $this->makeApp();
        $app->add(new class ('boom', 'Boom', static function (): int {
            throw new RuntimeException('kaboom');
        }) extends FakeCommand {});
        $code = $app->run(['bin/framework', 'boom']);
        self::assertSame(2, $code);
        $stderr = $this->output->stderrText();
        self::assertStringContainsString('Error: RuntimeException: kaboom', $stderr);
        self::assertStringNotContainsString('#0', $stderr);
    }

    public function testNonDebugModeOmitsStackTrace(): void
    {
        putenv('APP_DEBUG');
        $app = $this->makeApp();
        $app->add(new class ('boom', 'Boom', static function (): int {
            throw new RuntimeException('kaboom');
        }) extends FakeCommand {});

        $code = $app->run(['bin/framework', 'boom']);

        self::assertSame(2, $code);
        $stderr = $this->output->stderrText();
        self::assertStringContainsString('Error: RuntimeException: kaboom', $stderr);
        self::assertStringNotContainsString('#0', $stderr);
        self::assertStringEndsWith("\n", $stderr);
        self::assertSame(1, substr_count($stderr, "\n"));
    }

    public function testEnvDebugOneEmitsStackTrace(): void
    {
        putenv('APP_DEBUG=1');
        $app = $this->makeApp();
        $app->add(new class ('boom', 'Boom', static function (): int {
            throw new RuntimeException('kaboom');
        }) extends FakeCommand {});

        $code = $app->run(['bin/framework', 'boom']);

        self::assertSame(2, $code);
        $stderr = $this->output->stderrText();
        self::assertStringContainsString('Error: RuntimeException: kaboom', $stderr);
        self::assertStringContainsString('#0', $stderr);
        self::assertStringContainsString("\n#0 ", $stderr);
    }

    public function testEnvDebugTrueEmitsStackTrace(): void
    {
        putenv('APP_DEBUG=true');
        $app = $this->makeApp();
        $app->add(new class ('boom', 'Boom', static function (): int {
            throw new RuntimeException('kaboom');
        }) extends FakeCommand {});

        $code = $app->run(['bin/framework', 'boom']);

        self::assertSame(2, $code);
        self::assertStringContainsString('#0', $this->output->stderrText());
    }

    public function testDebugFlagEmitsStackTraceWithoutEnv(): void
    {
        putenv('APP_DEBUG');
        $app = $this->makeApp();
        $app->add(new class ('boom', 'Boom', static function (): int {
            throw new RuntimeException('kaboom');
        }) extends FakeCommand {});

        $code = $app->run(['bin/framework', '--debug', 'boom']);

        self::assertSame(2, $code);
        self::assertStringContainsString('#0', $this->output->stderrText());
    }

    public function testNoDebugFlagOverridesEnvDebug(): void
    {
        putenv('APP_DEBUG=1');
        $app = $this->makeApp();
        $app->add(new class ('boom', 'Boom', static function (): int {
            throw new RuntimeException('kaboom');
        }) extends FakeCommand {});

        $code = $app->run(['bin/framework', '--no-debug', 'boom']);

        self::assertSame(2, $code);
        $stderr = $this->output->stderrText();
        self::assertStringContainsString('Error: RuntimeException: kaboom', $stderr);
        self::assertStringNotContainsString('#0', $stderr);
    }

    public function testStackTraceFramesAreMultiLine(): void
    {
        putenv('APP_DEBUG=1');
        $app = $this->makeApp();
        $app->add(new class ('boom', 'Boom', static function (): int {
            throw new RuntimeException('kaboom');
        }) extends FakeCommand {});

        $app->run(['bin/framework', 'boom']);

        $stderr = $this->output->stderrText();
        self::assertGreaterThan(2, substr_count($stderr, "\n"));
    }

    public function testDebugConstructorArgOverridesEnvOff(): void
    {
        putenv('APP_DEBUG');
        $app = new Application($this->container, 'X', '0.0.0', $this->output, true);
        $app->add(new class ('boom', 'Boom', static function (): int {
            throw new RuntimeException('kaboom');
        }) extends FakeCommand {});

        $code = $app->run(['bin/framework', 'boom']);

        self::assertSame(2, $code);
        self::assertStringContainsString('#0', $this->output->stderrText());
    }

    public function testDebugConstructorArgFalseOverridesEnvOn(): void
    {
        putenv('APP_DEBUG=1');
        $app = new Application($this->container, 'X', '0.0.0', $this->output, false);
        $app->add(new class ('boom', 'Boom', static function (): int {
            throw new RuntimeException('kaboom');
        }) extends FakeCommand {});

        $code = $app->run(['bin/framework', 'boom']);

        self::assertSame(2, $code);
        self::assertStringNotContainsString('#0', $this->output->stderrText());
    }

    public function testRunCommandExitCodePropagates(): void
    {
        $app = $this->makeApp();
        $app->add(new class ('fail', 'Fail', static fn(): int => 42) extends FakeCommand {});
        $code = $app->run(['bin/framework', 'fail']);
        self::assertSame(42, $code);
    }

    public function testParseArgvLongOptionWithEquals(): void
    {
        $app = $this->makeApp();
        $app->run(['bin/framework', 'cmd', '--key=value']);
        $input = $app->input();
        self::assertSame('cmd', $input->command());
        self::assertSame('value', $input->option('key'));
    }

    public function testParseArgvLongOptionWithSpace(): void
    {
        $app = $this->makeApp();
        $app->run(['bin/framework', 'cmd', '--key', 'value']);
        self::assertSame('value', $app->input()->option('key'));
    }

    public function testParseArgvLongFlag(): void
    {
        $app = $this->makeApp();
        $app->run(['bin/framework', 'cmd', '--verbose']);
        self::assertTrue($app->input()->flag('verbose'));
    }

    public function testParseArgvShortOption(): void
    {
        $app = $this->makeApp();
        $app->run(['bin/framework', 'cmd', '-k', 'value']);
        self::assertSame('value', $app->input()->option('k'));
    }

    public function testParseArgvShortFlag(): void
    {
        $app = $this->makeApp();
        $app->run(['bin/framework', 'cmd', '-v']);
        self::assertTrue($app->input()->flag('v'));
    }

    public function testParseArgvPositionalArgs(): void
    {
        $app = $this->makeApp();
        $app->run(['bin/framework', 'cmd', 'arg1', 'arg2']);
        $input = $app->input();
        self::assertSame('cmd', $input->command());
        self::assertSame('cmd', $input->arg(0));
        self::assertSame('arg1', $input->arg(1));
        self::assertSame('arg2', $input->arg(2));
    }

    public function testParseArgvEmptyValue(): void
    {
        $app = $this->makeApp();
        $app->run(['bin/framework', 'cmd', '--key=']);
        self::assertSame('', $app->input()->option('key'));
    }

    public function testParseArgvNegativeNumberAsValue(): void
    {
        $app = $this->makeApp();
        $app->run(['bin/framework', 'cmd', '--bytes=-1']);
        self::assertSame('-1', $app->input()->option('bytes'));
    }

    public function testParseArgvFlagStopsBeforeNextDash(): void
    {
        $app = $this->makeApp();
        $app->run(['bin/framework', 'cmd', '--verbose', '--other']);
        $input = $app->input();
        self::assertTrue($input->flag('verbose'));
        self::assertTrue($input->flag('other'));
    }

    public function testContainerGetterReturnsInjected(): void
    {
        $app = $this->makeApp();
        self::assertSame($this->container, $app->container());
    }

    public function testNoAnsiFlagForcesPlainOutputFromCommands(): void
    {
        $app = $this->makeApp();
        $app->add(new class ('colored', 'Colored', static function (InputInterface $i, OutputInterface $o): int {
            $o->success('hello');
            $o->info('note');
            $o->warning('warn');
            $o->danger('bad');
            return 0;
        }) extends FakeCommand {});

        $code = $app->run(['bin/framework', '--no-ansi', 'colored']);

        self::assertSame(0, $code);
        $captured = $this->output->stdoutText();
        self::assertStringNotContainsString("\033[", $captured);
        self::assertStringContainsString("✓ hello\n", $captured);
        self::assertStringContainsString("ℹ note\n", $captured);
        self::assertStringContainsString("! warn\n", $captured);
        self::assertStringContainsString("✗ bad\n", $captured);
    }

    public function testAnsiFlagForcesColoredOutputFromCommands(): void
    {
        $ansiOutput = (new MemoryOutput())->withAnsi(false);
        $app = new Application($this->container, 'X', '0.0.0', $ansiOutput);
        $app->add(new class ('colored', 'Colored', static function (InputInterface $i, OutputInterface $o): int {
            $o->success('hello');
            return 0;
        }) extends FakeCommand {});

        $code = $app->run(['bin/framework', '--ansi', 'colored']);

        self::assertSame(0, $code);
        self::assertStringContainsString("\033[32m", $ansiOutput->stdoutText());
    }

    public function testNoAnsiFlagOnAppSecretEmitsCleanHex(): void
    {
        $app = $this->makeApp();
        $app->add(new \Framework\Console\Command\AppSecretCommand($this->container));

        $code = $app->run(['bin/framework', '--no-ansi', 'app:secret']);

        self::assertSame(0, $code);
        $captured = $this->output->stdoutText();
        self::assertStringNotContainsString("\033[", $captured);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\n$/', $captured);
    }

    private function makeApp(string $name = 'X', string $version = '0.0.0'): Application
    {
        return new Application($this->container, $name, $version, $this->output);
    }

    private function makeCommand(string $name, string $description): CommandInterface
    {
        return new class ($name, $description, static fn(): int => 0) extends FakeCommand {};
    }
}

abstract class FakeCommand implements CommandInterface
{
    /** @var callable(InputInterface, OutputInterface): int */
    private $handler;

    public function __construct(
        private readonly string $cmdName,
        private readonly string $cmdDescription,
        callable $handler,
    ) {
        $this->handler = $handler;
    }

    public function name(): string
    {
        return $this->cmdName;
    }

    public function description(): string
    {
        return $this->cmdDescription;
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        return ($this->handler)($input, $output);
    }
}
