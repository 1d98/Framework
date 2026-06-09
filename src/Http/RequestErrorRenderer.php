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
    public function __construct(
        private readonly bool $debug,
    ) {
    }

    public function render(Throwable $e, Request $request): Response
    {
        if ($e instanceof ValidationException) {
            $e = ValidationExceptionMapper::toHttpException($e);
        }

        return (new ProblemDetails($e, $request->path, $this->debug))->toResponse()
            ->withRequestId($request->id);
    }
}
