<?php

declare(strict_types=1);

namespace Framework\Console\Output;

use function fwrite;
use function getenv;
use function stream_isatty;

final class Output extends BaseOutput
{
    private readonly bool $useAnsi;

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private $stdout,
        private $stderr,
        ?bool $useAnsi = null,
    ) {
        $this->useAnsi = $useAnsi ?? self::detectAnsi($stdout);
    }

    public static function stdoutStderr(): self
    {
        return new self(\STDOUT, \STDERR);
    }

    public function withAnsi(bool $useAnsi): self
    {
        $clone = new self($this->stdout, $this->stderr, $useAnsi);
        return $clone;
    }

    public function useAnsi(): bool
    {
        return $this->useAnsi;
    }

    public function write(string $message, bool $newline = true): void
    {
        fwrite($this->stdout, AnsiSanitizer::sanitize($message) . ($newline ? "\n" : ''));
    }

    public function error(string $message, bool $newline = true): void
    {
        fwrite($this->stderr, AnsiSanitizer::sanitize($message) . ($newline ? "\n" : ''));
    }

    protected function writeDecorated(string $open, string $close, string $icon, string $message): void
    {
        $sanitized = AnsiSanitizer::sanitize($message);
        $line = $this->useAnsi ? $open . $icon . $sanitized . $close : $icon . $sanitized;
        fwrite($this->stdout, $line . "\n");
    }

    /**
     * @param list<string>      $headers
     * @param list<list<mixed>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $columnCount = count($headers);
        $sanitizedHeaders = array_map(
            static fn(string $h): string => AnsiSanitizer::sanitize($h),
            $headers,
        );
        /** @var list<int> $widths */
        $widths = array_map(static fn(string $h): int => strlen($h), $sanitizedHeaders);

        $sanitizedRows = [];
        foreach ($rows as $row) {
            $cells = $this->extractCells($row, $columnCount);
            $sanitizedCells = array_map(
                static fn(string $c): string => AnsiSanitizer::sanitize($c),
                $cells,
            );
            $sanitizedRows[] = $sanitizedCells;
            foreach ($sanitizedCells as $i => $cell) {
                $widths[$i] = max($widths[$i], strlen($cell));
            }
        }

        $sepLine = '+';
        foreach ($widths as $w) {
            $sepLine .= str_repeat('-', $w + 2) . '+';
        }
        $this->write($sepLine);

        $headerLine = '|';
        foreach ($sanitizedHeaders as $i => $h) {
            $headerLine .= ' ' . str_pad($h, $widths[$i]) . ' |';
        }
        $this->write($headerLine);

        $this->write($sepLine);

        foreach ($sanitizedRows as $sanitizedCells) {
            $rowLine = '|';
            foreach ($sanitizedCells as $i => $cell) {
                $rowLine .= ' ' . str_pad($cell, $widths[$i]) . ' |';
            }
            $this->write($rowLine);
        }
    }

    /**
     * @param list<mixed> $row
     * @return array<int, string>
     */
    private function extractCells(array $row, int $columnCount): array
    {
        $cells = [];
        for ($i = 0; $i < $columnCount; $i++) {
            $value = $row[$i] ?? '';
            $cells[$i] = match (true) {
                is_string($value) => $value,
                is_scalar($value) => (string) $value,
                is_object($value) && method_exists($value, '__toString') => (string) $value,
                default => '',
            };
        }
        return $cells;
    }

    /**
     * Detects whether ANSI escape sequences should be emitted.
     *
     * Respects the {@see https://no-color.org no-color.org} spec: NO_COLOR disables
     * ANSI only when present AND non-empty; an explicitly empty value (e.g.
     * `Environment=NO_COLOR=` in a systemd unit) is a no-op and defaults apply.
     *
     * @param resource $stdout
     */
    private static function detectAnsi($stdout): bool
    {
        $noColor = getenv('NO_COLOR');
        if ($noColor !== false && $noColor !== '') {
            return false;
        }
        return is_resource($stdout) && stream_isatty($stdout);
    }

    /** @return resource */
    public function stdout()
    {
        return $this->stdout;
    }

    /** @return resource */
    public function stderr()
    {
        return $this->stderr;
    }
}
