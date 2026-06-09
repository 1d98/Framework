<?php

declare(strict_types=1);

namespace Framework\Http;

use InvalidArgumentException;
use RuntimeException;

final readonly class UploadedFile
{
    public function __construct(
        public string $name,
        public string $type,
        public string $tmpPath,
        public int $error,
        public int $size,
    ) {
        if ($size < 0) {
            throw new InvalidArgumentException('UploadedFile: size cannot be negative');
        }
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK;
    }

    public function moveTo(string $target): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(sprintf(
                'UploadedFile: cannot move file with error code %d',
                $this->error,
            ));
        }

        set_error_handler(static fn (int $errno, string $errstr): bool => true);
        try {
            $moved = is_uploaded_file($this->tmpPath)
                ? move_uploaded_file($this->tmpPath, $target)
                : rename($this->tmpPath, $target);
        } finally {
            restore_error_handler();
        }

        if (!$moved) {
            $lastError = error_get_last();
            throw new RuntimeException(sprintf(
                'UploadedFile: failed to move from "%s" to "%s"%s',
                $this->tmpPath,
                $target,
                $lastError !== null ? ': ' . $lastError['message'] : '',
            ));
        }
    }
}
