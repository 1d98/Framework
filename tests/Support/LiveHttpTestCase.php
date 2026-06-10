<?php

declare(strict_types=1);

namespace Framework\Tests\Support;

use PHPUnit\Framework\TestCase;

abstract class LiveHttpTestCase extends TestCase
{
    protected string $host = '127.0.0.1';

    protected int $port = 18765;

    protected string $logFile = '';

    protected string $baseUrl = '';

    /** @var resource|null */
    private $serverProcess = null;

    /**
     * stdout / stderr pipes of the spawned server.
     *
     * @var array<int, resource>|null
     */
    private ?array $serverPipes = null;

    protected function appEnv(): string
    {
        return 'dev';
    }

    /**
     * Extra environment variables for the spawned PHP server.
     *
     * @return array<string, string>
     */
    protected function extraEnv(): array
    {
        return [];
    }

    protected function logFileName(): string
    {
        return 'framework_test_server.log';
    }

    protected function setUp(): void
    {
        $this->baseUrl = 'http://' . $this->host . ':' . $this->port;
        $projectRoot = dirname(__DIR__, 2);
        $this->logFile = $projectRoot . '/var/tmp/' . $this->logFileName();
        $publicDir = $projectRoot . '/public';

        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cmd = sprintf(
            '%s -S %s:%d -t %s',
            PHP_BINARY,
            $this->host,
            $this->port,
            escapeshellarg($publicDir),
        );

        $env = getenv();
        $env['APP_ENV'] = $this->appEnv();
        foreach ($this->extraEnv() as $key => $value) {
            $env[$key] = $value;
        }

        $pipes = [];
        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            self::fail('Failed to start PHP built-in server (proc_open returned non-resource)');
        }
        $this->serverProcess = $process;
        $this->serverPipes = $pipes;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        for ($i = 0; $i < 30; $i++) {
            $ch = curl_init($this->baseUrl . '/');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);
            if ($code > 0) {
                return;
            }
            usleep(100_000);
        }

        self::fail('PHP built-in server did not start within 3 seconds');
    }

    protected function tearDown(): void
    {
        if ($this->serverPipes !== null) {
            foreach ([1, 2] as $idx) {
                if (isset($this->serverPipes[$idx]) && is_resource($this->serverPipes[$idx])) {
                    @fclose($this->serverPipes[$idx]);
                }
            }
            $this->serverPipes = null;
        }

        if (is_resource($this->serverProcess)) {
            $status = proc_get_status($this->serverProcess);
            if ($status['running']) {
                $signal = defined('SIGTERM') ? SIGTERM : 15;
                proc_terminate($this->serverProcess, $signal);
                usleep(1_000_000);
            }
            proc_close($this->serverProcess);
            $this->serverProcess = null;
        }

        if (getenv('KEEP_TEST_LOG') !== '1' && is_file($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    /**
     * @param non-empty-string $method
     * @param list<string> $extraHeaders
     * @return array{code: int, headers: array<string, string>, body: string, raw: string}
     */
    protected function liveRaw(
        string $method,
        string $path,
        array $extraHeaders = [],
        ?string $body = null,
        bool $assumeHttps = true,
    ): array {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        $defaults = $assumeHttps ? ['X-Forwarded-Proto: https'] : [];
        $headers = $assumeHttps
            ? array_merge($defaults, self::stripHeader($extraHeaders, 'X-Forwarded-Proto'))
            : $extraHeaders;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        [$rawHeaders, $respBody] = HttpResponseParser::split($raw, $headerSize);

        return [
            'code' => $code,
            'headers' => HttpResponseParser::parseHeaders($rawHeaders),
            'body' => $respBody,
            'raw' => $raw,
        ];
    }

    /**
     * @param non-empty-string $method
     * @param list<string> $extraHeaders
     * @return array{code: int, headers: array<string, string>, body: string}
     */
    protected function liveRequest(string $method, string $path, array $extraHeaders = [], bool $assumeHttps = true): array
    {
        $r = $this->liveRaw($method, $path, $extraHeaders, null, $assumeHttps);
        return ['code' => $r['code'], 'headers' => $r['headers'], 'body' => $r['body']];
    }

    /**
     * Concatenated `Set-Cookie` header(s) for assertions. Multiple Set-Cookie
     * headers are joined with newline so the test can `assertStringContainsString`
     * for individual flag tokens (`Secure`, `HttpOnly`, etc.) without depending
     * on the first cookie only.
     *
     * @param array{headers: array<string, string>} $response
     */
    protected function cookieHeader(array $response): string
    {
        $lines = [];
        foreach ($response['headers'] as $name => $value) {
            if (strtolower($name) === 'set-cookie') {
                $lines[] = $value;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Filter out any entry in `$headers` whose name (the part before the
     * first `:`) matches `$name` case-insensitively. Used by the
     * `assumeHttps` default to avoid emitting duplicate `X-Forwarded-Proto`
     * headers — duplicate values are joined with a comma by the SAPI, which
     * the framework would correctly treat as a multi-value / untrusted
     * header.
     *
     * @param list<string> $headers
     * @return list<string>
     */
    private static function stripHeader(array $headers, string $name): array
    {
        $needle = strtolower($name);
        $out = [];
        foreach ($headers as $header) {
            if (strtolower(trim(explode(':', $header, 2)[0])) !== $needle) {
                $out[] = $header;
            }
        }
        return $out;
    }
}
