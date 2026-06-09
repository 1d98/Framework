<?php

declare(strict_types=1);

namespace Framework\Config;

final class Env
{
    /**
     * Loads .env file into getenv(), $_ENV, $_SERVER.
     * Silent skip if file does not exist (production may have no .env).
     * Real env vars always win when override=false (12-factor compliant).
     *
     * @param string $path Path to .env file
     * @param bool $override If true, overwrites existing env vars. Default false.
     * @return int Number of vars actually loaded (for diagnostics)
     */
    public static function load(string $path, bool $override = false): int
    {
        if (!is_file($path)) {
            return 0;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return 0;
        }

        return self::applyVars(self::parse($contents), $override);
    }

    /**
     * Loads multiple .env files in order. Later files do not overwrite earlier
     * ones (when override=false): a.env values are defaults, b.env may add
     * new keys but cannot clobber ones already set by a.env.
     *
     * @param list<string> $paths Files in priority order
     * @param bool $override If true, later files overwrite earlier ones and real env
     * @return int Total number of vars loaded across all files
     */
    public static function loadMany(array $paths, bool $override = false): int
    {
        $total = 0;
        foreach ($paths as $path) {
            $total += self::load($path, $override);
        }
        return $total;
    }

    /**
     * Convenience: load if file exists, return true on load, false on skip.
     *
     * @return bool true if file was loaded, false if it did not exist
     */
    public static function loadIfExists(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        self::load($path);
        return true;
    }

    /**
     * Parses .env contents into a name=>value map. Pure function for testing.
     *
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $vars = [];
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            $eqPos = strpos($line, '=');
            if ($eqPos === false || $eqPos === 0) {
                continue;
            }

            $name = trim(substr($line, 0, $eqPos));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                continue;
            }

            $rawValue = substr($line, $eqPos + 1);
            $value = self::processValue($rawValue);

            $vars[$name] = $value;
        }

        return $vars;
    }

    private static function processValue(string $rawValue): string
    {
        $trimmed = trim($rawValue);

        $len = strlen($trimmed);
        if ($len >= 2) {
            $first = $trimmed[0];
            $last = $trimmed[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $inner = substr($trimmed, 1, -1);
                if ($first === '"') {
                    return self::unescapeDoubleQuoted($inner);
                }
                return $inner;
            }
        }

        $hashPos = strpos($trimmed, ' #');
        if ($hashPos !== false) {
            $trimmed = rtrim(substr($trimmed, 0, $hashPos));
        } elseif (str_ends_with($trimmed, '#') && !str_ends_with($trimmed, '\\#')) {
            $trimmed = rtrim(substr($trimmed, 0, -1));
        }

        return $trimmed;
    }

    private static function unescapeDoubleQuoted(string $value): string
    {
        $result = '';
        $len = strlen($value);
        $i = 0;
        while ($i < $len) {
            $ch = $value[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $next = $value[$i + 1];
                $result .= match ($next) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '"' => '"',
                    '\\' => '\\',
                    "'" => "'",
                    default => $ch . $next,
                };
                $i += 2;
                continue;
            }
            $result .= $ch;
            $i++;
        }
        return $result;
    }

    /**
     * @param array<string, string> $vars
     */
    private static function applyVars(array $vars, bool $override): int
    {
        $loaded = 0;
        foreach ($vars as $name => $value) {
            if (!$override && (getenv($name) !== false || array_key_exists($name, $_ENV))) {
                continue;
            }
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            $loaded++;
        }
        return $loaded;
    }
}
