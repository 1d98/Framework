<?php

declare(strict_types=1);

namespace Framework\Tests\Unit;

use Framework\Config\Config;
use Framework\Config\ConfigInterface;
use Framework\Exception\ConfigException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    public function testFromArrayReturnsConfigWithData(): void
    {
        $config = Config::fromArray(['app' => 'demo', 'debug' => true]);

        self::assertInstanceOf(ConfigInterface::class, $config);
        self::assertSame('demo', $config->get('app'));
        self::assertTrue($config->get('debug'));
    }

    public function testGetReturnsDefaultWhenKeyMissing(): void
    {
        $config = Config::fromArray(['a' => 1]);

        self::assertSame('fallback', $config->get('missing', 'fallback'));
        self::assertNull($config->get('missing'));
    }

    public function testGetReturnsNullForExplicitNullValue(): void
    {
        $config = Config::fromArray(['nullable' => null]);

        self::assertTrue($config->has('nullable'));
        self::assertNull($config->get('nullable'));
        self::assertNull($config->get('nullable', 'default'));
    }

    public function testHasReturnsTrueOnlyForExistingKeys(): void
    {
        $config = Config::fromArray(['present' => 'x', 'null_value' => null]);

        self::assertTrue($config->has('present'));
        self::assertTrue($config->has('null_value'));
        self::assertFalse($config->has('absent'));
    }

    public function testWithReturnsNewInstanceWithoutMutation(): void
    {
        $original = Config::fromArray(['a' => 1, 'b' => 2]);
        $modified = $original->with(['b' => 20, 'c' => 3]);

        self::assertNotSame($original, $modified);
        self::assertSame(1, $original->get('a'));
        self::assertSame(2, $original->get('b'));
        self::assertFalse($original->has('c'));

        self::assertSame(1, $modified->get('a'));
        self::assertSame(20, $modified->get('b'));
        self::assertSame(3, $modified->get('c'));
    }

    public function testAllReturnsCompleteData(): void
    {
        $data = ['a' => 1, 'b' => 'x'];
        $config = Config::fromArray($data);

        self::assertSame($data, $config->all());
    }

    public function testFromFileLoadsArrayFromPhpFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cfg_');
        file_put_contents($tmp, '<?php return ["loaded" => "yes", "n" => 42];');

        try {
            $config = Config::fromFile($tmp);

            self::assertSame('yes', $config->get('loaded'));
            self::assertSame(42, $config->get('n'));
        } finally {
            unlink($tmp);
        }
    }

    public function testFromFileThrowsWhenFileMissing(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config file not found');

        Config::fromFile('/nonexistent/path/to/config.php');
    }

    public function testFromFileThrowsWhenFileDoesNotReturnArray(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cfg_');
        file_put_contents($tmp, '<?php return "not an array";');

        try {
            $this->expectException(ConfigException::class);
            $this->expectExceptionMessage('must return an array');

            Config::fromFile($tmp);
        } finally {
            unlink($tmp);
        }
    }
}
