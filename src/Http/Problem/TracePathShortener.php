<?php

declare(strict_types=1);

namespace Framework\Http\Problem;

final class TracePathShortener
{
    public static function shorten(?string $path): string
    {
        if ($path === null || $path === '') {
            return '[internal]';
        }

        return basename($path);
    }
}
