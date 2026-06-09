<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Config;

use Framework\Config\Env;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Env::class)]
final class EnvTest extends TestCase
{
    /** @var array<string, string> */
    private array $originalEnv = [];

    /** @var array<string, string> */
    private array $originalServer = [];

    /** @var list<string> */
    private array $touchedKeys = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        /** @var array<string, string> $envSnapshot */
        $envSnapshot = [];
        foreach ($_ENV as $k => $v) {
            if (is_string($k) && (is_string($v) || is_int($v) || is_float($v) || is_bool($v))) {
                $envSnapshot[$k] = (string) $v;
            }
        }
        $this->originalEnv = $envSnapshot;

        /** @var array<string, string> $serverSnapshot */
        $serverSnapshot = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && (is_string($v) || is_int($v) || is_float($v) || is_bool($v))) {
                $serverSnapshot[$k] = (string) $v;
            }
        }
        $this->originalServer = $serverSnapshot;

        $this->touchedKeys = [];
        $this->tempFiles = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedKeys as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $_ENV = $this->originalEnv;
        $_SERVER = $this->originalServer;
    }

    public function testParseSimpleKeyValue(): void
    {
        $vars = Env::parse("FOO=bar\nBAZ=qux");

        self::assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $vars);
    }

    public function testParseEmptyValue(): void
    {
        $vars = Env::parse("EMPTY=\nNON_EMPTY=present");

        self::assertSame(['EMPTY' => '', 'NON_EMPTY' => 'present'], $vars);
    }

    public function testParseDoubleQuotedValue(): void
    {
        $vars = Env::parse('GREETING="Hello, World!"');

        self::assertSame(['GREETING' => 'Hello, World!'], $vars);
    }

    public function testParseDoubleQuotedEscapes(): void
    {
        $vars = Env::parse('PATH="C:\\\\Users\\\\Test"');
        self::assertSame(['PATH' => 'C:\\Users\\Test'], $vars);
    }

    public function testParseSingleQuotedLiteral(): void
    {
        $vars = Env::parse("RAW='literal \\n no escape'");

        self::assertSame(['RAW' => 'literal \\n no escape'], $vars);
    }

    public function testParseCommentsAndEmptyLines(): void
    {
        $contents = <<<ENV
            # This is a comment
            KEY1=value1

            # Another comment
            KEY2=value2

            ENV;
        $vars = Env::parse($contents);

        self::assertSame(['KEY1' => 'value1', 'KEY2' => 'value2'], $vars);
    }

    public function testParseValueWithEquals(): void
    {
        $vars = Env::parse('DATABASE_URL=postgres://user:pass@host:5432/db');

        self::assertSame(
            ['DATABASE_URL' => 'postgres://user:pass@host:5432/db'],
            $vars,
        );
    }

    public function testParseExportPrefix(): void
    {
        $vars = Env::parse("export PATH_DIR=/usr/local/bin\nPATH_DIR2=/bin");

        self::assertSame(
            ['PATH_DIR' => '/usr/local/bin', 'PATH_DIR2' => '/bin'],
            $vars,
        );
    }

    public function testParseCrlfLineEndings(): void
    {
        $vars = Env::parse("FOO=bar\r\nBAZ=qux\r\n");

        self::assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $vars);
    }

    public function testParseStripsBom(): void
    {
        $vars = Env::parse("\xEF\xBB\xBFKEY=value");

        self::assertSame(['KEY' => 'value'], $vars);
    }

    public function testParseSkipsInvalidLines(): void
    {
        $contents = <<<ENV
            GOOD=yes
            =invalid_empty_name
            NOEQUALS
            123STARTS_WITH_DIGIT=skip
            ANOTHER=ok
            ENV;
        $vars = Env::parse($contents);

        self::assertSame(['GOOD' => 'yes', 'ANOTHER' => 'ok'], $vars);
    }

    public function testParseInlineComment(): void
    {
        $vars = Env::parse("KEY=value # trailing comment\nANOTHER=clean");

        self::assertSame(['KEY' => 'value', 'ANOTHER' => 'clean'], $vars);
    }

    public function testParseUnicodeValue(): void
    {
        $vars = Env::parse("GREETING=Привет мир");

        self::assertSame(['GREETING' => 'Привет мир'], $vars);
    }

    public function testParseEmptyContent(): void
    {
        self::assertSame([], Env::parse(''));
    }

    public function testParseWhitespaceAroundValue(): void
    {
        $vars = Env::parse("TRIM=   spaced   \nNO_TRIM=plain");

        self::assertSame(['TRIM' => 'spaced', 'NO_TRIM' => 'plain'], $vars);
    }

    public function testLoadExistingFileAppliesToGetenvAndServer(): void
    {
        $path = $this->createTempEnv("LOAD_TEST_KEY=hello\nLOAD_TEST_OTHER=world");
        $this->touchedKeys = ['LOAD_TEST_KEY', 'LOAD_TEST_OTHER'];

        $count = Env::load($path);

        self::assertSame(2, $count);
        self::assertSame('hello', getenv('LOAD_TEST_KEY'));
        self::assertSame('hello', $_ENV['LOAD_TEST_KEY']);
        self::assertSame('hello', $_SERVER['LOAD_TEST_KEY']);
        self::assertSame('world', getenv('LOAD_TEST_OTHER'));
    }

    public function testLoadMissingFileReturnsZero(): void
    {
        $missingPath = sys_get_temp_dir() . '/env_does_not_exist_' . uniqid() . '.env';

        $count = Env::load($missingPath);

        self::assertSame(0, $count);
    }

    public function testLoadIfExistsReturnsFalseOnMissing(): void
    {
        $missingPath = sys_get_temp_dir() . '/env_missing_' . uniqid() . '.env';

        self::assertFalse(Env::loadIfExists($missingPath));
    }

    public function testLoadIfExistsReturnsTrueOnSuccess(): void
    {
        $path = $this->createTempEnv("LIE_TEST=present");
        $this->touchedKeys[] = 'LIE_TEST';

        self::assertTrue(Env::loadIfExists($path));
        self::assertSame('present', $_ENV['LIE_TEST'] ?? null);
    }

    public function testLoadOverrideFalsePreservesExisting(): void
    {
        $path = $this->createTempEnv("EXISTING=from_file");
        $_ENV['EXISTING'] = 'from_env';
        putenv('EXISTING=from_env');
        $this->touchedKeys[] = 'EXISTING';

        Env::load($path);

        self::assertSame('from_env', $_ENV['EXISTING']);
        self::assertSame('from_env', getenv('EXISTING'));
    }

    public function testLoadOverrideTrueOverwritesExisting(): void
    {
        $path = $this->createTempEnv("OVR_KEY=from_file");
        $_ENV['OVR_KEY'] = 'from_env';
        putenv('OVR_KEY=from_env');
        $this->touchedKeys[] = 'OVR_KEY';

        Env::load($path, override: true);

        self::assertSame('from_file', $_ENV['OVR_KEY']);
        self::assertSame('from_file', getenv('OVR_KEY'));
        self::assertSame('from_file', $_SERVER['OVR_KEY']);
    }

    public function testLoadManyMergesFilesInOrder(): void
    {
        $first = $this->createTempEnv("MULTI_A=alpha\nMULTI_B=beta");
        $second = $this->createTempEnv("MULTI_B=b_from_second\nMULTI_C=gamma");
        $this->touchedKeys = ['MULTI_A', 'MULTI_B', 'MULTI_C'];

        $count = Env::loadMany([$first, $second]);

        self::assertSame(3, $count);
        self::assertSame('alpha', $_ENV['MULTI_A']);
        self::assertSame('beta', $_ENV['MULTI_B']);
        self::assertSame('gamma', $_ENV['MULTI_C']);
    }

    private function createTempEnv(string $contents): string
    {
        $path = sys_get_temp_dir() . '/env_test_' . uniqid('', true) . '.env';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return $path;
    }
}
