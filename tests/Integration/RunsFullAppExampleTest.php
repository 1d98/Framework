<?php

declare(strict_types=1);

namespace Framework\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for examples/full-app.php.
 *
 * The example is a runnable documentation file: it wires a container,
 * a router, a middleware pipeline, and an HttpKernel, then dispatches
 * a real request via `Request::fromGlobals()`. In Batch A the file
 * had a broken `require_once` path that made it fail to load at all
 * (the vendor dir had been moved). This test boots the example in a
 * short-lived PHP subprocess with a controlled `$_SERVER`, captures
 * the response body that `$response->send()` writes to STDOUT, and
 * asserts the example actually dispatches the GET `/` route.
 *
 * The subprocess approach is intentional: the example is designed
 * to run as a real SAPI entry point (it calls `Request::fromGlobals()`
 * and `$response->send()` at the top level), so requiring it in the
 * test process would pollute `$_SERVER` and conflict with PHPUnit's
 * own SAPI. A subprocess is hermetic — it has its own `$_SERVER`,
 * its own `php.ini`, and it exits when the response is sent.
 */
final class RunsFullAppExampleTest extends TestCase
{
    private string $logFile = '';

    protected function setUp(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $logDir = $projectRoot . '/var/tmp';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $this->logFile = $logDir . '/full_app_example_test.log';
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

    public function testExampleBootsAndDispatchesGetRoot(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $example = $projectRoot . '/examples/full-app.php';

        self::assertFileExists($example, 'examples/full-app.php must exist for this regression test');

        $bootstrap = $projectRoot . '/var/tmp/full_app_example_bootstrap.php';
        $bootstrapSource = <<<'PHP'
            <?php
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/';
            $_SERVER['HTTP_HOST'] = 'localhost';
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $_SERVER['SERVER_NAME'] = 'localhost';
            $_SERVER['SERVER_PORT'] = '80';
            $_SERVER['HTTPS'] = '';
            require __EXAMPLE_PATH__;
            PHP;
        $bootstrapSource = str_replace('__EXAMPLE_PATH__', var_export($example, true), $bootstrapSource);
        file_put_contents($bootstrap, $bootstrapSource);

        $cmd = sprintf(
            '%s -d display_errors=stderr -d display_startup_errors=1 %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($bootstrap),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->logFile, 'w'],
            2 => ['file', $this->logFile, 'a'],
        ];

        $processEnv = getenv();
        $processEnv['APP_ENV'] = 'dev';
        $processEnv['APP_SECRET'] = bin2hex(random_bytes(32));

        $process = proc_open($cmd, $descriptors, $pipes, $projectRoot, $processEnv);
        self::assertIsResource($process, 'Failed to start PHP subprocess for examples/full-app.php');
        $exit = proc_close($process);
        $combined = is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';

        @unlink($bootstrap);

        self::assertSame(
            0,
            $exit,
            sprintf(
                "examples/full-app.php must exit 0 when run as a request handler.\n"
                . "Exit code: %d\n"
                . "Combined output:\n%s",
                $exit,
                $combined,
            ),
        );
        self::assertStringContainsString(
            '<h1>Hello, Framework</h1>',
            $combined,
            'GET / must dispatch to the root route and emit its body. Output: ' . $combined,
        );
        self::assertStringNotContainsString(
            'Fatal error',
            $combined,
            'examples/full-app.php must not throw a fatal error. Output: ' . $combined,
        );
        self::assertStringNotContainsString(
            'Uncaught',
            $combined,
            'examples/full-app.php must not propagate an uncaught exception. Output: ' . $combined,
        );
    }
}
