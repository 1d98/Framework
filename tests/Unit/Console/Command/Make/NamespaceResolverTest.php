<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Command\Make;

use Framework\Console\Command\Make\NamespaceResolver;
use Framework\Tests\Support\MakeScaffolderTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(NamespaceResolver::class)]
final class NamespaceResolverTest extends MakeScaffolderTestCase
{
    public function testReturnsAppFallbackWhenNoComposerJsonAbove(): void
    {
        $resolver = new NamespaceResolver();
        $result = $resolver->resolveForTargetDir($this->tmpDir . '/Http/Controller');

        self::assertSame('App\\Http\\Controller', $result);
    }

    public function testReturnsAppWithLastTwoSegmentsWhenTargetOutsideCwd(): void
    {
        $resolver = new NamespaceResolver();
        $result = $resolver->resolveForTargetDir($this->tmpDir);

        self::assertStringStartsWith('App\\', $result, 'Fallback must start with "App\\"');
        $segments = explode('\\', $result);
        self::assertGreaterThanOrEqual(2, count($segments), 'Fallback must include at least one extra segment beyond "App"');
        self::assertLessThanOrEqual(4, count($segments), 'Fallback must not include more than 3 path segments beyond "App"');
    }

    public function testPrefersMostSpecificPsr4Mapping(): void
    {
        $project = $this->tmpDir . '/project';
        mkdir($project . '/src/Http/Controller', 0o777, true);
        file_put_contents($project . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'Acme\\App\\' => 'src/',
                    'Acme\\App\\Http\\' => 'src/Http/',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $resolver = new NamespaceResolver();
        $result = $resolver->resolveForTargetDir($project . '/src/Http/Controller');

        self::assertSame('Acme\\App\\Http\\Controller', $result);
    }

    public function testBuildsNamespaceFromSubdirBelowPsr4Base(): void
    {
        $project = $this->tmpDir . '/project';
        mkdir($project . '/src/Http/Controller', 0o777, true);
        file_put_contents($project . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'Acme\\' => 'src/',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $resolver = new NamespaceResolver();
        $result = $resolver->resolveForTargetDir($project . '/src/Http/Controller');

        self::assertSame('Acme\\Http\\Controller', $result);
    }

    public function testReturnsExactNamespaceWhenTargetIsPsr4Base(): void
    {
        $project = $this->tmpDir . '/project';
        mkdir($project . '/src', 0o777, true);
        file_put_contents($project . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'Acme\\' => 'src/',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $resolver = new NamespaceResolver();
        $result = $resolver->resolveForTargetDir($project . '/src');

        self::assertSame('Acme', $result);
    }

    public function testFallsBackWhenTargetIsAboveAnyPsr4Base(): void
    {
        $project = $this->tmpDir . '/project';
        mkdir($project . '/src', 0o777, true);
        file_put_contents($project . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'Acme\\' => 'src/',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $resolver = new NamespaceResolver();
        $result = $resolver->resolveForTargetDir($project . '/var/cache');

        self::assertSame('App\\var\\cache', $result);
    }

    public function testReadsAutoloadDevPsr4(): void
    {
        $project = $this->tmpDir . '/project';
        mkdir($project . '/tests/Hook', 0o777, true);
        file_put_contents($project . '/composer.json', json_encode([
            'autoload-dev' => [
                'psr-4' => [
                    'Acme\\Test\\' => 'tests/',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $resolver = new NamespaceResolver();
        $result = $resolver->resolveForTargetDir($project . '/tests/Hook');

        self::assertSame('Acme\\Test\\Hook', $result);
    }

    public function testHandlesMultiplePathsForSinglePrefix(): void
    {
        $project = $this->tmpDir . '/project';
        mkdir($project . '/modules/billing', 0o777, true);
        file_put_contents($project . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'Acme\\' => ['src/', 'modules/'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $resolver = new NamespaceResolver();
        $result = $resolver->resolveForTargetDir($project . '/modules/billing');

        self::assertSame('Acme\\billing', $result);
    }

    public function testFallsBackWhenComposerJsonIsMalformed(): void
    {
        $project = $this->tmpDir . '/project';
        mkdir($project . '/src', 0o777, true);
        file_put_contents($project . '/composer.json', '{ not valid json');

        $resolver = new NamespaceResolver();
        $result = $resolver->resolveForTargetDir($project . '/src');

        self::assertStringStartsWith('App\\', $result);
        self::assertStringEndsWith('\\project\\src', $result, 'Fallback must include the project/src suffix relative to the working directory');
    }

    public function testFallsBackWhenComposerJsonHasNoAutoload(): void
    {
        $project = $this->tmpDir . '/project';
        mkdir($project . '/src', 0o777, true);
        file_put_contents($project . '/composer.json', json_encode([
            'name' => 'acme/project',
            'description' => 'no autoload at all',
        ], JSON_THROW_ON_ERROR));

        $resolver = new NamespaceResolver();
        $result = $resolver->resolveForTargetDir($project . '/src');

        self::assertStringStartsWith('App\\', $result);
        self::assertStringEndsWith('\\project\\src', $result);
    }

    public function testFallsBackWhenPsr4ValueIsEmpty(): void
    {
        $project = $this->tmpDir . '/project';
        mkdir($project . '/src', 0o777, true);
        file_put_contents($project . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [],
            ],
        ], JSON_THROW_ON_ERROR));

        $resolver = new NamespaceResolver();
        $result = $resolver->resolveForTargetDir($project . '/src');

        self::assertStringStartsWith('App\\', $result);
        self::assertStringEndsWith('\\project\\src', $result);
    }
}
