<?php

declare(strict_types=1);

namespace Framework\Http\Response;

use Framework\Http\Cookie\Cookie;

/**
 * Common contract for both buffered and streaming HTTP responses.
 *
 * Concrete implementations:
 *  - {@see Response} — buffered body (small/JSON/text/HTML/redirects).
 *  - {@see StreamedResponse} — body produced at send() time by an emitter
 *    closure; use this for SSE, NDJSON, large-file download, etc.
 *
 * All implementations MUST:
 *  - be `readonly` (immutable; mutators return a new instance);
 *  - validate header names/values and reason phrases at construction,
 *    throwing on CRLF / NUL injection rather than letting a poisoned
 *    value reach the wire at send() time;
 *  - use canonical reason phrases from {@see StatusText} unless the caller
 *    overrides them via the constructor or {@see withStatus()}.
 */
interface ResponseInterface
{
    public int $status { get; }

    /** @var array<string, string> */
    public array $headers { get; }

    /** @var list<Cookie> */
    public array $cookies { get; }

    public ?string $reasonPhrase { get; }

    public function withHeader(string $name, string $value): self;

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self;

    public function withStatus(int $status, ?string $reason = null): self;

    public function withCookie(Cookie $c): self;

    public function withRequestId(string $id): self;

    public function send(): void;
}