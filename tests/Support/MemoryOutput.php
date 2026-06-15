<?php

declare(strict_types=1);

namespace Framework\Tests\Support;

use Framework\Console\Output\OutputInterface;

final class MemoryOutput extends \Framework\Console\Output\BaseOutput
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    public function __construct()
    {
        $this->stdout = MemoryStream::open();
        $this->stderr = MemoryStream::open();
    }

    private bool $useAnsi = false;

    public function write(string $message, bool $newline = true): void
    {
        fwrite($this->stdout, $message . ($newline ? "\n" : ''));
    }

    public function withAnsi(bool $useAnsi): self
    {
        $clone = clone $this;
        $clone->useAnsi = $useAnsi;
        return $clone;
    }

    public function useAnsi(): bool
    {
        return $this->useAnsi;
    }

    public function error(string $message, bool $newline = true): void
    {
        fwrite($this->stderr, $message . ($newline ? "\n" : ''));
    }

    protected function writeDecorated(string $open, string $close, string $icon, string $message): void
    {
        $line = $this->useAnsi ? $open . $icon . $message . $close : $icon . $message;
        fwrite($this->stdout, $line . "\n");
    }

    /**
     * @param list<string>           $headers
     * @param list<list<mixed>>      $rows
     */
    public function table(array $headers, array $rows): void
    {
        $columnCount = count($headers);
        /** @var list<int> $widths */
        $widths = array_map(static fn(string $h): int => strlen($h), $headers);

        foreach ($rows as $row) {
            for ($i = 0; $i < $columnCount; $i++) {
                $value = $row[$i] ?? '';
                $cell = match (true) {
                    is_string($value) => $value,
                    is_scalar($value) => (string) $value,
                    default => '',
                };
                $widths[$i] = max($widths[$i] ?? 0, strlen($cell));
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
            $rowLine = '|';
            for ($i = 0; $i < $columnCount; $i++) {
                $value = $row[$i] ?? '';
                $cell = match (true) {
                    is_string($value) => $value,
                    is_scalar($value) => (string) $value,
                    default => '',
                };
                $rowLine .= ' ' . str_pad($cell, $widths[$i]) . ' |';
            }
            $this->write($rowLine);
        }
    }

    public function stdoutText(): string
    {
        return MemoryStream::contents($this->stdout);
    }

    public function stderrText(): string
    {
        return MemoryStream::contents($this->stderr);
    }
}
