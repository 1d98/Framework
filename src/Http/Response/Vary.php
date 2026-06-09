<?php

declare(strict_types=1);

namespace Framework\Http\Response;

final readonly class Vary
{
    private const string DEFAULT_SEPARATOR = ', ';

    public static function merge(string $existing, string $token, string $separator = self::DEFAULT_SEPARATOR): string
    {
        $token = trim($token);
        if ($token === '') {
            return $existing;
        }
        $existing = trim($existing);
        if ($existing === '') {
            return $token;
        }
        foreach (self::tokens($existing, $separator) as $existingToken) {
            if (strcasecmp($existingToken, $token) === 0) {
                return $existing;
            }
        }
        return $existing . $separator . $token;
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $vary, string $separator = self::DEFAULT_SEPARATOR): array
    {
        if ($separator === '') {
            $separator = self::DEFAULT_SEPARATOR;
        }
        $tokens = array_map('trim', explode($separator, $vary));
        return array_values(array_filter($tokens, static fn(string $token): bool => $token !== ''));
    }
}
