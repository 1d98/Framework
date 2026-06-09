<?php

declare(strict_types=1);

namespace Framework\Tests\Support;

final class MemoryStream
{
    /**
     * @return resource
     */
    public static function open()
    {
        $r = fopen('php://memory', 'r+');
        if (!is_resource($r)) {
            throw new \RuntimeException('Failed to open memory stream');
        }
        return $r;
    }

    /**
     * @param resource $stream
     */
    public static function contents($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        return $contents === false ? '' : $contents;
    }
}
