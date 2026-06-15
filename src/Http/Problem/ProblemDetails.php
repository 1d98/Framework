<?php

declare(strict_types=1);

namespace Framework\Http\Problem;

use Framework\Http\Exception\HttpException;
use Framework\Http\Exception\InternalServerErrorHttpException;
use Framework\Http\Exception\MethodNotAllowedHttpException;
use Framework\Http\Exception\UnprocessableEntityHttpException;
use Framework\Http\Response\Response;
use Framework\Http\TraceContext;
use Throwable;

final readonly class ProblemDetails
{
    /**
     * @param string|null $requestId Snapshot of `$request->id` at
     *     error-render time. When non-null, emitted as a
     *     `requestId` field in the body. Independent of
     *     `traceparent` (which is the W3C-style distributed
     *     trace identifier).
     */
    public function __construct(
        private Throwable $exception,
        private string $instance,
        private bool $debug,
        private ?string $requestId = null,
        private ?TraceContext $traceContext = null,
    ) {
    }

    public function status(): int
    {
        if ($this->exception instanceof HttpException) {
            return $this->exception->statusCode;
        }

        return 500;
    }

    public function type(): string
    {
        if ($this->exception instanceof HttpException) {
            return $this->exception->type;
        }

        return 'about:blank';
    }

    public function title(): string
    {
        return HttpException::STATUS_TEXTS[$this->status()] ?? 'Error';
    }

    public function detail(): string
    {
        $message = $this->exception->getMessage();

        if ($this->debug) {
            return $message;
        }

        if ($this->exception instanceof HttpException && $message !== '') {
            return $message;
        }

        return $this->title();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function trace(): ?array
    {
        if (!$this->debug) {
            return null;
        }

        if ($this->status() < 500) {
            return null;
        }

        return $this->formatTrace($this->exception);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function errors(): ?array
    {
        if (!$this->exception instanceof UnprocessableEntityHttpException) {
            return null;
        }

        $errors = $this->exception->errors();
        return $errors === [] ? null : $errors;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = ['Content-Type' => 'application/problem+json'];

        if ($this->status() === 405) {
            $headers['Allow'] = $this->allowHeader();
        }

        if ($this->exception instanceof HttpException) {
            foreach ($this->exception->headers() as $name => $value) {
                $headers[$name] = $value;
            }
        }

        if ($this->traceContext !== null) {
            foreach ($this->traceContext->toW3CHeaders() as $name => $value) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'type' => $this->type(),
            'title' => $this->title(),
            'status' => $this->status(),
            'detail' => $this->detail(),
            'instance' => $this->instance,
        ];

        if ($this->requestId !== null) {
            $body['requestId'] = $this->requestId;
        }

        if ($this->traceContext !== null) {
            $body['traceId'] = $this->traceContext->traceId;
        }

        $trace = $this->trace();
        if ($trace !== null) {
            $body['trace'] = $trace;
        }

        $errors = $this->errors();
        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return $body;
    }

    public function toResponse(): Response
    {
        $array = $this->toArray();
        try {
            $response = Response::json($array, $this->status());
        } catch (InternalServerErrorHttpException $e) {
            // Last line of defense: the structured body could not be JSON-encoded
            // (e.g. a resource in the exception trace under debug). Hand-roll a
            // minimal RFC 7807 body that we know is encodable, so the kernel
            // does not recurse into toResponse() on the rethrown exception.
            $fallback = [
                'type' => 'about:blank',
                'title' => 'Internal Server Error',
                'status' => 500,
                'detail' => 'ProblemDetails body is not JSON-encodable: ' . $e->getMessage(),
                'instance' => $this->instance,
            ];
            if ($this->requestId !== null) {
                $fallback['requestId'] = $this->requestId;
            }
            if ($this->traceContext !== null) {
                $fallback['traceId'] = $this->traceContext->traceId;
            }
            $body = json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                // Truly unrecoverable: the static fallback string itself is somehow
                // not encodable. Surface the original failure as a hard error.
                throw new InternalServerErrorHttpException('ProblemDetails fallback body is not JSON-encodable', $e);
            }
            $response = new Response(500, $body, ['Content-Type' => 'application/problem+json']);
        }
        foreach ($this->headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private function allowHeader(): string
    {
        if ($this->exception instanceof MethodNotAllowedHttpException) {
            $methods = $this->exception->allowedMethods();
            if ($methods !== []) {
                return implode(', ', $methods);
            }
        }

        $custom = $this->exception instanceof HttpException ? $this->exception->headers() : [];
        return $custom['Allow'] ?? '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function formatTrace(Throwable $e): array
    {
        $trace = [];
        foreach ($e->getTrace() as $frame) {
            $rawFile = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : null;
            $line = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : 0;
            $function = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : '';
            $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : '';
            $type = isset($frame['type']) && is_string($frame['type']) ? $frame['type'] : '';
            $trace[] = [
                'file' => TracePathShortener::shorten($rawFile),
                'line' => $line,
                'function' => $function,
                'class' => $class,
                'type' => $type,
            ];
        }

        return $trace;
    }
}
