<?php

declare(strict_types=1);

namespace Framework\Console;

use Framework\Console\Input\Input;
use Framework\Console\Input\InputInterface;

final class ArgvParser
{
    private const BOOLEAN_FLAGS = [
        'version',
        'help',
        'ansi',
        'no-ansi',
        'no-color',
        'no-debug',
        'verbose',
        'quiet',
        'debug',
    ];

    private const BOOLEAN_SHORT_FLAGS = [
        'v' => true,
        'h' => true,
        'V' => true,
        'q' => true,
        'd' => true,
    ];

    /**
     * @param list<string> $argv
     */
    public static function parse(array $argv): InputInterface
    {
        array_shift($argv);

        $positional = [];
        $options = [];
        $flags = [];
        $shortOptions = [];
        $count = count($argv);
        $i = 0;

        while ($i < $count) {
            $arg = $argv[$i];

            if (str_starts_with($arg, '--')) {
                $eqPos = strpos($arg, '=');
                if ($eqPos !== false) {
                    $name = substr($arg, 2, $eqPos - 2);
                    $value = substr($arg, $eqPos + 1);
                    $options[$name] = $value;
                } else {
                    $name = substr($arg, 2);
                    $next = $argv[$i + 1] ?? null;
                    if (in_array($name, self::BOOLEAN_FLAGS, true)) {
                        $flags[$name] = true;
                    } elseif ($next !== null && !self::looksLikeLongFlag($next)) {
                        $options[$name] = $next;
                        $i++;
                    } else {
                        $flags[$name] = true;
                    }
                }
            } elseif (str_starts_with($arg, '-') && strlen($arg) >= 2) {
                $name = substr($arg, 1, 1);
                $attached = substr($arg, 2);
                if ($attached === '') {
                    $next = $argv[$i + 1] ?? null;
                    if (isset(self::BOOLEAN_SHORT_FLAGS[$name]) && $next !== null) {
                        $flags[$name] = true;
                    } elseif ($next !== null) {
                        $shortOptions[$name] = $next;
                        $i++;
                    } else {
                        $flags[$name] = true;
                    }
                } else {
                    $shortOptions[$name] = $attached;
                }
            } else {
                $positional[] = $arg;
            }

            $i++;
        }

        return new Input($positional, $options, $flags, $shortOptions);
    }

    private static function looksLikeLongFlag(string $candidate): bool
    {
        return str_starts_with($candidate, '--');
    }
}
