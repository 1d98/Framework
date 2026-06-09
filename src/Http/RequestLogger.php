<?php

declare(strict_types=1);

namespace Framework\Http;

use Framework\Http\Exception\HttpException;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Logging\LoggerInterface;
use Throwable;

final class RequestLogger
{
    public function __construct(
        private readonly ?LoggerInterface $logger,
    ) {
    }

    public function logger(): ?LoggerInterface
    {
        return $this->logger;
    }

    public function logHttpException(Throwable $e, Request $request, Response $response): void
    {
        if (!$e instanceof HttpException) {
            return;
        }

        $context = $this->logContext($request, $e);
        $context['status'] = $response->status;

        if ($response->status >= 500) {
            $this->logger?->error('http_exception', $context);
            return;
        }

        $this->logger?->warning('http_exception', $context);
    }

    public function logUnhandledException(Throwable $e, Request $request, Response $response): void
    {
        $context = $this->logContext($request, $e);
        $context['status'] = $response->status;

        $this->logger?->error('unhandled_exception', $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function logContext(Request $request, ?Throwable $e = null): array
    {
        return [
            'request_id' => $request->id,
            'method' => $request->method,
            'path' => $request->path,
            'status' => $this->statusFor($e),
            'exception' => $e === null ? null : $e::class,
            'message' => $e === null ? '' : $this->sanitize($e->getMessage()),
        ];
    }

    private function statusFor(?Throwable $e): int
    {
        if ($e instanceof HttpException) {
            return $e->statusCode;
        }

        return 500;
    }

    private function sanitize(string $msg): string
    {
        $msg = strtr($msg, ["\r" => ' ', "\n" => ' ', "\t" => ' ']);
        $msg = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $msg) ?? '';

        return mb_substr($msg, 0, 256);
    }
}
