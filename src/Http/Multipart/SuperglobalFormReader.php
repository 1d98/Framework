<?php

declare(strict_types=1);

namespace Framework\Http\Multipart;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Exception\PayloadTooLargeHttpException;
use Framework\Http\UploadedFile;

/**
 * Pulls a parsed form/file map out of PHP's `$_POST` / `$_FILES`
 * superglobals, the SAPI-coupled fallback path used when the
 * request body is empty (i.e. PHP already parsed it via
 * `enable_post_data_reading`) but the application still wants
 * `Request::form()` / `Request::files()` populated.
 *
 * Lives in the parser namespace so middleware can swap in a
 * fake during tests; only this class touches the superglobals.
 */
final class SuperglobalFormReader
{
    public const int MAX_FORM_KEYS = 1000;

    public const int MAX_FORM_VALUE_BYTES = 65536;

    public static function hasUploads(): bool
    {
        return !empty($_FILES);
    }

    /**
     * True when either `$_POST` or `$_FILES` is non-empty — the
     * broader "the SAPI already parsed something for us" check used
     * by the multipart middleware to decide whether to fall back to
     * the superglobals on an empty `php://input`. Replaces the
     * older `hasUploads()`-only check, which silently dropped
     * form-only POSTs (e.g. a login form with no uploads) because
     * `$_FILES` was empty even when `$_POST` was populated.
     */
    public static function hasFormData(): bool
    {
        return !empty($_POST) || !empty($_FILES);
    }

    /**
     * @return array<string, string|list<string>>
     * @throws PayloadTooLargeHttpException
     */
    public static function readPostFields(): array
    {
        $post = $_POST;
        $count = count($post);
        if ($count > self::MAX_FORM_KEYS) {
            throw new PayloadTooLargeHttpException(sprintf(
                'Too many form fields in $_POST (max %d)',
                self::MAX_FORM_KEYS,
            ));
        }

        /** @var array<string, string|list<string>> $sanitized */
        $sanitized = [];
        foreach ($post as $key => $value) {
            $sanitized[(string) $key] = self::assertFormValueWithinCap($value);
        }
        return $sanitized;
    }

    /**
     * @return array<string, UploadedFile|list<UploadedFile>>
     * @throws PayloadTooLargeHttpException
     * @throws BadRequestHttpException
     */
    public static function readUploadedFiles(): array
    {
        $files = [];
        $fileCount = count($_FILES);
        if ($fileCount > self::MAX_FORM_KEYS) {
            throw new PayloadTooLargeHttpException(sprintf(
                'Too many form fields in $_FILES (max %d)',
                self::MAX_FORM_KEYS,
            ));
        }

        foreach ($_FILES as $field => $entry) {
            if (!is_array($entry)) {
                throw new BadRequestHttpException(sprintf(
                    'Malformed $_FILES entry "%s": expected an array',
                    (string) $field,
                ));
            }

            $requiredKeys = ['name', 'type', 'tmp_name', 'error', 'size'];
            foreach ($requiredKeys as $key) {
                if (!array_key_exists($key, $entry)) {
                    throw new BadRequestHttpException(sprintf(
                        'Malformed $_FILES entry "%s": missing key "%s"',
                        (string) $field,
                        $key,
                    ));
                }
            }

            $names = self::castToStringArray($entry['name']);
            $types = self::castToStringArray($entry['type']);
            $tmpNames = self::castToStringArray($entry['tmp_name']);
            $errors = self::castToIntArray($entry['error']);
            $sizes = self::castToIntArray($entry['size']);

            $count = count($names);
            $collected = [];
            for ($i = 0; $i < $count; $i++) {
                $collected[] = new UploadedFile(
                    name: $names[$i],
                    type: $types[$i] ?? '',
                    tmpPath: $tmpNames[$i] ?? '',
                    error: $errors[$i] ?? 0,
                    size: $sizes[$i] ?? 0,
                );
            }

            $files[(string) $field] = $count === 1 ? $collected[0] : $collected;
        }

        return $files;
    }

    /**
     * @return string|list<string>
     * @throws PayloadTooLargeHttpException
     */
    private static function assertFormValueWithinCap(mixed $value): string|array
    {
        if (is_string($value)) {
            if (strlen($value) > self::MAX_FORM_VALUE_BYTES) {
                throw new PayloadTooLargeHttpException(sprintf(
                    'Form value exceeds %d bytes',
                    self::MAX_FORM_VALUE_BYTES,
                ));
            }
            return $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $v) {
                $out[] = self::assertFormValueWithinCap($v);
            }
            /** @var list<string> $out */
            return $out;
        }
        return '';
    }

    /**
     * @return list<string>
     */
    private static function castToStringArray(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $v) {
                if (is_string($v) || is_int($v) || is_float($v) || is_bool($v)) {
                    $out[] = (string) $v;
                }
            }
            return $out;
        }
        return [];
    }

    /**
     * @return list<int>
     */
    private static function castToIntArray(mixed $value): array
    {
        if (is_int($value)) {
            return [$value];
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $v) {
                if (is_int($v)) {
                    $out[] = $v;
                } elseif (is_string($v) && is_numeric($v)) {
                    $out[] = (int) $v;
                }
            }
            return $out;
        }
        return [];
    }
}
