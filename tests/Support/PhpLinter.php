<?php

declare(strict_types=1);

namespace Framework\Tests\Support;

final class PhpLinter
{
    public static function check(string $path): bool
    {
        $output = [];
        $rc = 0;
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $rc);
        return $rc === 0;
    }
}
