<?php

declare(strict_types=1);

namespace Framework\Console\Command\Make;

final class ClassNameValidator
{
    public function isValid(string $raw): bool
    {
        return $raw !== '' && (bool) preg_match('/^[A-Z][A-Za-z0-9]*$/', $raw);
    }

    public function normalize(string $raw): string
    {
        $cleaned = preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '';
        if ($cleaned === '') {
            return '';
        }
        $cleaned = ucfirst($cleaned);
        return $this->isValid($cleaned) ? $cleaned : '';
    }

    public function suffixed(string $raw, string $suffix): string
    {
        $base = $this->normalize($raw);
        if ($base === '') {
            return '';
        }
        if ($suffix !== '' && str_ends_with($base, $suffix)) {
            return $base;
        }
        return $base . $suffix;
    }

    public function slug(string $class, string $stripSuffix = ''): string
    {
        $base = $stripSuffix !== '' && str_ends_with($class, $stripSuffix)
            ? substr($class, 0, -strlen($stripSuffix))
            : $class;
        return strtolower(preg_replace('/(?<!^)([A-Z])/', '-$1', $base) ?? $base);
    }
}
