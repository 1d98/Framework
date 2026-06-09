<?php

declare(strict_types=1);

namespace Framework\Console\Output;

use function fwrite;
use function getenv;
use function stream_isatty;

final class Output implements OutputInterface
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

    public function usesAnsi(): bool
    {
        return $this->useAnsi;
    }

    public function write(string $message, bool $newline = true): void
    {
        fwrite($this->stdout, $message . ($newline ? "\n" : ''));
    }

    public function error(string $message, bool $newline = true): void
    {
        fwrite($this->stderr, $message . ($newline ? "\n" : ''));
    }

    public function success(string $message): void
    {
        $this->write($this->ansi("\033[32m", "\033[0m", '✓ ' . $message));
    }

    public function info(string $message): void
    {
        $this->write($this->ansi("\033[34m", "\033[0m", 'ℹ ' . $message));
    }

    public function warning(string $message): void
    {
        $this->write($this->ansi("\033[33m", "\033[0m", '! ' . $message));
    }

    public function danger(string $message): void
    {
        $this->write($this->ansi("\033[31m", "\033[0m", '✗ ' . $message));
    }

    /**
     * @param list<string>      $headers
     * @param list<list<mixed>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $columnCount = count($headers);
        /** @var list<int> $widths */
        $widths = array_map(static fn(string $h): int => strlen($h), $headers);

        foreach ($rows as $row) {
            $cells = $this->extractCells($row, $columnCount);
            foreach ($cells as $i => $cell) {
                $widths[$i] = max($widths[$i], strlen((string) $cell));
            }
        }

        $sepLine = '+';
        foreach ($widths as $w) {
            $sepLine .= str_repeat('-', $w + 2) . '+';
        }
        $this->write($sepLine);

        $headerLine = '|';
        foreach ($headers as $i => $h) {
            $headerLine .= ' ' . str_pad($h, $widths[$i]) . ' |';
        }
        $this->write($headerLine);

        $this->write($sepLine);

        foreach ($rows as $row) {
            $cells = $this->extractCells($row, $columnCount);
            $rowLine = '|';
            foreach ($cells as $i => $cell) {
                $rowLine .= ' ' . str_pad((string) $cell, $widths[$i]) . ' |';
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

    private function ansi(string $open, string $close, string $text): string
    {
        return $this->useAnsi ? $open . $text . $close : $text;
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
