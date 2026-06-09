<?php

declare(strict_types=1);

namespace Framework\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Boots `public/index.php` in a real PHP subprocess with controlled env vars
 * to verify that the AppSecretValidator fails closed in production and
 * stays open in development. We deliberately do NOT spin up the built-in
 * HTTP server here — the validator fires before `$response->send()`, so a
 * plain `require` is enough and avoids the `proc_open` polling loop.
 */
final class AppSecretBootTest extends TestCase
{
    private string $logFile = '';

    protected function setUp(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $logDir = $projectRoot . '/var/tmp';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $this->logFile = $logDir . '/app_secret_boot_test.log';
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    protected function tearDown(): void
    {
        if (getenv('KEEP_TEST_LOG') !== '1' && file_exists($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function bootIndex(array $env): array
    {
        $projectRoot = dirname(__DIR__, 2);
        $cmd = sprintf(
            '%s -d display_errors=stderr -d display_startup_errors=1 -r %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg("require '" . $projectRoot . '/public/index.php' . "';")
        );
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->logFile, 'w'],
            2 => ['file', $this->logFile, 'a'],
        ];
        $processEnv = getenv();
        foreach ($env as $key => $value) {
            $processEnv[$key] = $value;
        }
        $process = proc_open($cmd, $descriptors, $pipes, $projectRoot, $processEnv);
        self::assertIsResource($process, 'Failed to start PHP subprocess');
        $exit = proc_close($process);
        $combined = is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
        return [
            'exit' => $exit,
            'stdout' => $combined,
            'stderr' => $combined,
        ];
    }

    public function testBootInProdWithDevDefaultSecretFailsClosed(): void
    {
        $result = $this->bootIndex([
            'APP_ENV' => 'prod',
            'APP_SECRET' => 'dev-only-secret-change-in-prod',
            'APP_TRUSTED_HOSTS' => 'example.com',
        ]);

        self::assertNotSame(0, $result['exit'], 'Subprocess must exit non-zero when APP_SECRET is the dev default in prod');
        self::assertStringContainsString('ConfigException', $result['stderr']);
        self::assertStringContainsString('well-known development default', $result['stderr']);
    }

    public function testBootInProdWithEmptySecretFailsClosed(): void
    {
        $result = $this->bootIndex([
            'APP_ENV' => 'prod',
            'APP_SECRET' => '',
            'APP_TRUSTED_HOSTS' => 'example.com',
        ]);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('ConfigException', $result['stderr']);
    }

    public function testBootInProdWithShortSecretFailsClosed(): void
    {
        $result = $this->bootIndex([
            'APP_ENV' => 'prod',
            'APP_SECRET' => 'too-short',
            'APP_TRUSTED_HOSTS' => 'example.com',
        ]);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('too short', $result['stderr']);
    }

    public function testBootInProdWithStrongSecretSucceeds(): void
    {
        $result = $this->bootIndex([
            'APP_ENV' => 'prod',
            'APP_SECRET' => bin2hex(random_bytes(32)),
            'APP_TRUSTED_HOSTS' => 'example.com',
        ]);

        self::assertStringNotContainsString('ConfigException', $result['stderr']);
        self::assertStringNotContainsString('well-known development default', $result['stderr']);
    }

    public function testBootInDevWithDevDefaultSecretSucceeds(): void
    {
        $result = $this->bootIndex([
            'APP_ENV' => 'dev',
            'APP_SECRET' => 'dev-only-secret-change-in-prod',
        ]);

        self::assertStringNotContainsString('ConfigException', $result['stderr']);
    }
}
