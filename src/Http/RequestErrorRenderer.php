<?php

declare(strict_types=1);

namespace Framework\Http;

use Framework\Http\Problem\ProblemDetails;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Validation\ValidationException;
use Throwable;

final class RequestErrorRenderer
{
    /**
     * @param bool $debug         When `true`, pass `debug=true` through to
     *     {@see ProblemDetails} so exception messages and traces appear in
     *     the response. Always `false` in production by default; flip on
     *     for staging / local development.
     * @param bool $redactTrace   When `true` (default), `debug` is overridden
     *     to `false` so stack traces NEVER appear in the response body —
     *     even if `debug` is `true`. Operators must explicitly opt in to
     *     stack-trace leakage by setting this to `false`. The default is
     *     the safer one: a forgotten `$debug=true` in production must NOT
     *     leak file paths and class names to the public.
     */
    public function __construct(
        private readonly bool $debug,
        private readonly bool $redactTrace = true,
    ) {
    }

    public function render(Throwable $e, Request $request): Response
    {
        if ($e instanceof ValidationException) {
            $e = ValidationExceptionMapper::toHttpException($e);
        }

        return (new ProblemDetails($e, $request->path, $this->debug && !$this->redactTrace))->toResponse()
            ->withRequestId($request->id);
    }
}
