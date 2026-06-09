<?php

declare(strict_types=1);

namespace Framework\Http;

use Framework\Http\Exception\BadRequestHttpException;

/**
 * Bounded wrapper around `parse_str` for untrusted query / form bodies.
 *
 * `parse_str` builds deeply nested arrays from keys like
 * `a[b][c][d][e]...`. A 10 MiB body full of such keys is well
 * under `Request::MAX_BODY_BYTES` and would still allocate an
 * exponential-ish tree, which is an OOM-in-PHP-FPM primitive. This
 * helper enforces two hard caps **before** handing the input to
 * `parse_str`:
 *
 *  - `$maxKeys`: at most this many `&`-separated entries. Anything
 *    more throws `BadRequestHttpException(400, 'Too many form keys')`.
 *  - `$maxDepth`: at most this many levels of `[...]` in any single
 *    key. Anything more throws
 *    `BadRequestHttpException(400, 'Form key nesting too deep')`.
 *
 * The two checks run in a single pass over the raw input, so a
 * pathological body is rejected without ever building the deep
 * array.
 */
final class SafeParseStr
{
    public const int DEFAULT_MAX_KEYS = 1000;

    public const int DEFAULT_MAX_DEPTH = 32;

    /**
     * @param string $input Raw query / form body.
     * @param int $maxKeys Maximum `&`-separated entries allowed.
     * @param int $maxDepth Maximum `[...]` nesting depth per key.
     * @return array<string, string|array<string, mixed>>
     * @throws BadRequestHttpException When the input exceeds either cap.
     */
    public static function parse(string $input, int $maxKeys = self::DEFAULT_MAX_KEYS, int $maxDepth = self::DEFAULT_MAX_DEPTH): array
    {
        if ($input === '') {
            return [];
        }

        $count = 0;
        $length = strlen($input);
        $i = 0;
        while ($i < $length) {
            $amp = strpos($input, '&', $i);
            $end = $amp === false ? $length : $amp;
            self::checkEntryDepth($input, $i, $end, $maxDepth);
            $count++;
            if ($count > $maxKeys) {
                throw new BadRequestHttpException('Too many form keys');
            }
            if ($amp === false) {
                break;
            }
            $i = $amp + 1;
        }

        $result = [];
        parse_str($input, $result);

        /** @var array<string, string|array<string, mixed>> $result */
        return $result;
    }

    /**
     * Walk one `&`-separated entry (`$input[$start..$end)`, end
     * exclusive) and count the `[` brackets. A bracket is a
     * nesting level only when it is not part of the value: `parse_str`
     * treats the first `=` as the key/value separator, and brackets
     * after that belong to the value.
     */
    private static function checkEntryDepth(string $input, int $start, int $end, int $maxDepth): void
    {
        $depth = 0;
        for ($i = $start; $i < $end; $i++) {
            $c = $input[$i];
            if ($c === '=') {
                return;
            }
            if ($c !== '[') {
                continue;
            }
            $depth++;
            if ($depth > $maxDepth) {
                throw new BadRequestHttpException('Form key nesting too deep');
            }
        }
    }
}
