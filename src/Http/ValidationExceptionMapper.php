<?php

declare(strict_types=1);

namespace Framework\Http;

use Framework\Http\Exception\UnprocessableEntityHttpException;
use Framework\Validation\ValidationError;
use Framework\Validation\ValidationException;
use Throwable;

final class ValidationExceptionMapper
{
    /**
     * Translate a transport-agnostic ValidationException into an HTTP-shaped
     * UnprocessableEntityHttpException (422) carrying the same error collection
     * in RFC 7807 detail-array form.
     */
    public static function toHttpException(ValidationException $e): UnprocessableEntityHttpException
    {
        $errors = array_map(
            static fn(ValidationError $err): array => $err->toArray(),
            $e->errors()->all(),
        );

        return new UnprocessableEntityHttpException(
            $e->getMessage(),
            $e->getPrevious(),
            $errors,
        );
    }
}
