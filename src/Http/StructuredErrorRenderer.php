<?php

declare(strict_types=1);

namespace Framework\Http;

use Framework\Http\Problem\ProblemDetails;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Validation\ValidationException;
use Throwable;

/**
 * RFC 7807 (`application/problem+json`) error renderer with
 * W3C-tracecontext propagation and a configurable `redactTrace`
 * knob that lets a misconfigured production deployment never
 * leak stack frames.
 *
 * Replaces the leaner {@see RequestErrorRenderer} when the
 * caller wants:
 *
 * 1. `X-Request-Id` / `requestId` correlation field
 * 2. `traceparent` header + `traceId` body field
 * 3. Explicit `redactTrace: bool` so a `debug: true` prod build
 *    can be locked down without changing the renderer
 * 4. A `type` field that can be suppressed in non-debug mode for
 *    a cleaner error response
 *
 * The renderer is the only class that knows about the
 * {@see TraceContext} and {@see ProblemDetails} pair; the kernel
 * sees the same input/output as {@see RequestErrorRenderer}.
 */
final class StructuredErrorRenderer
{
    /**
     * @param bool $includeRequestId Emit `X-Request-Id` header and
     *     `requestId` body field. Default `true` because the
     *     request id is the cheap, low-risk correlation
     *     identifier the operator's log search needs.
     * @param bool $includeTraceId Honour the incoming `traceparent`
     *     header (or mint a new trace id) and emit `traceparent`
     *     + `traceId`. Default `true` so distributed traces line
     *     up across services.
     * @param bool $redactTrace When `true`, the `trace` body
     *     field is suppressed regardless of debug mode. The
     *     explicit knob exists so a `debug: true` production
     *     build can ship without leaking stack frames; default
     *     `true` is the safe default.
     * @param bool $exposeType When `true`, the `type` field is
     *     emitted in the body. Default `false` is the cleaner
     *     non-debug shape (`about:blank` per RFC 7807 default).
     *     Set to `true` when a custom {@see HttpException::type}
     *     should be visible to the client.
     */
    public function __construct(
        private readonly bool $includeRequestId = true,
        private readonly bool $includeTraceId = true,
        private readonly bool $redactTrace = true,
        private readonly bool $exposeType = false,
        private readonly bool $debug = false,
    ) {
    }

    public function render(Throwable $e, Request $request): Response
    {
        if ($e instanceof ValidationException) {
            $e = ValidationExceptionMapper::toHttpException($e);
        }

        $traceContext = $this->includeTraceId
            ? TraceContext::fromTraceparentHeader($request->header('traceparent'))
            : null;

        $requestId = $this->includeRequestId ? $request->id : null;

        $details = new ProblemDetails(
            exception: $e,
            instance: $request->path,
            debug: $this->debug && !$this->redactTrace,
            requestId: $requestId,
            traceContext: $traceContext,
        );

        $response = $details->toResponse();

        if ($this->includeRequestId) {
            $response = $response->withRequestId($request->id);
        }

        if (!$this->exposeType) {
            // Suppress the `type` field for a cleaner default response.
            // Done by re-encoding without it. We mutate the response
            // body to a JSON that omits the `type` key.
            $body = $response->body;
            $decoded = json_decode($body, true);
            if (is_array($decoded) && array_key_exists('type', $decoded)) {
                unset($decoded['type']);
                $reEncoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($reEncoded !== false) {
                    $response = $response->withBody($reEncoded);
                }
            }
        }

        return $response;
    }
}
