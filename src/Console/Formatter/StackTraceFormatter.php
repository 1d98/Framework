<?php

declare(strict_types=1);

namespace Framework\Console\Formatter;

use Framework\Http\Problem\TracePathShortener;
use Throwable;

final class StackTraceFormatter
{
    public function __construct(
        private readonly bool $shortenPaths = true,
    ) {
    }

    public function summary(Throwable $e): string
    {
        return sprintf('Error: %s: %s', get_class($e), $e->getMessage());
    }

    /**
     * @return list<string>
     */
    public function frames(Throwable $e): array
    {
        $lines = [];
        $index = 0;
        foreach ($e->getTrace() as $frame) {
            $lines[] = $this->formatFrame($index, $frame);
            $index++;
        }
        return $lines;
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function formatFrame(int $index, array $frame): string
    {
        $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '[internal]';
        $line = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : 0;
        $location = $this->shortenPaths ? TracePathShortener::shorten($file) . ':' . $line : $file . ':' . $line;

        $call = $this->formatCall($frame);

        return sprintf('#%d %s: %s()', $index, $location, $call);
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function formatCall(array $frame): string
    {
        $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : '';
        $type = isset($frame['type']) && is_string($frame['type']) ? $frame['type'] : '';
        $function = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : '';

        $scope = $class !== '' ? $class . $type : '';
        $argsRaw = $frame['args'] ?? [];
        $args = is_array($argsRaw) ? array_values($argsRaw) : [];

        return $scope . $function . $this->renderArgs($args);
    }

    /**
     * @param list<mixed> $args
     */
    private function renderArgs(array $args): string
    {
        if ($args === []) {
            return '()';
        }

        $parts = [];
        foreach ($args as $arg) {
            $parts[] = $this->stringify($arg);
        }
        return '(' . implode(', ', $parts) . ')';
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => "'" . $this->truncate($value) . "'",
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_null($value) => 'null',
            is_array($value) => 'array(' . count($value) . ')',
            is_object($value) => get_debug_type($value),
            default => get_debug_type($value),
        };
    }

    private function truncate(string $value): string
    {
        $max = 64;
        if (strlen($value) <= $max) {
            return $value;
        }
        return substr($value, 0, $max - 1) . '…';
    }
}
