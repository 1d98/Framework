<?php

declare(strict_types=1);

namespace Framework\Http;

/**
 * Immutable value object for a W3C-tracecontext-style trace identifier.
 * Holds a 16-hex-char `trace-id`, an 8-hex-char `span-id`, and a
 * flags byte. Can be created from a parsed incoming `traceparent`
 * header ({@see self::fromTraceparentHeader()}) or freshly minted
 * with cryptographically-secure randomness ({@see self::mint()}).
 *
 * The class is the single source of truth for the
 * "X-Request-Id-like opaque correlation identifier" that the
 * framework emits on every error response. It deliberately does
 * NOT do anything with the OpenTelemetry SDK (no OTLP exporter,
 * no span nesting) — that is a 5-line user hook over
 * {@see self::traceId()} and {@see self::toTraceparent()}.
 *
 * Validation is strict: a malformed `traceparent` header value
 * is rejected and {@see self::fromTraceparentHeader()} returns
 * a fresh {@see self::mint()} instead. Operators do not want
 * a missing or broken `traceparent` to break the request.
 */
final readonly class TraceContext
{
    private const string TRACEPARENT_PATTERN = '/\A[0-9a-f]{2}-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}\z/';

    public function __construct(
        public string $traceId,
        public string $spanId,
        public int $flags = 1,
    ) {
        if (strlen($traceId) !== 32) {
            throw new \InvalidArgumentException('traceId must be 32 lowercase hex chars (16 bytes)');
        }
        if (preg_match('/\A[0-9a-f]{32}\z/', $traceId) !== 1) {
            throw new \InvalidArgumentException('traceId must be 32 lowercase hex chars (got: ' . $traceId . ')');
        }
        if (strlen($spanId) !== 16) {
            throw new \InvalidArgumentException('spanId must be 16 lowercase hex chars (8 bytes)');
        }
        if (preg_match('/\A[0-9a-f]{16}\z/', $spanId) !== 1) {
            throw new \InvalidArgumentException('spanId must be 16 lowercase hex chars (got: ' . $spanId . ')');
        }
        if ($flags < 0 || $flags > 0xff) {
            throw new \InvalidArgumentException('flags must be a single byte (0-255)');
        }
    }

    /**
     * Parse an incoming W3C `traceparent` header value. Returns a
     * fresh minted context on parse failure (null, empty, or
     * malformed) — the request continues normally, just without
     * trace correlation to the upstream caller.
     */
    public static function fromTraceparentHeader(?string $header): self
    {
        if ($header === null) {
            return self::mint();
        }
        $header = trim($header);
        if ($header === '' || preg_match(self::TRACEPARENT_PATTERN, $header) !== 1) {
            return self::mint();
        }
        $parts = explode('-', $header);
        // Layout: version-traceId-spanId-flags (4 fields).
        return new self(
            traceId: $parts[1],
            spanId: $parts[2],
            flags: (int) hexdec($parts[3]),
        );
    }

    /**
     * Generate a fresh trace context with 16 random bytes for the
     * trace-id and 8 random bytes for the span-id. Flags default
     * to 0x01 ("sampled" per W3C spec — request was sampled, but
     * the framework itself does not propagate sampling decisions).
     */
    public static function mint(): self
    {
        return new self(
            traceId: bin2hex(random_bytes(16)),
            spanId: bin2hex(random_bytes(8)),
            flags: 1,
        );
    }

    /**
     * Emit the W3C `traceparent` header value: `00-<trace>-<span>-<flags>`.
     * The version byte is hard-coded to `00` (the current W3C version);
     * future versions are additive and the framework can be updated.
     */
    public function toTraceparent(): string
    {
        return sprintf('00-%s-%s-%02x', $this->traceId, $this->spanId, $this->flags);
    }

    /**
     * Map onto the framework's error-response headers. Currently
     * only `traceparent` is emitted; future revisions can add
     * `tracestate` (vendor-specific data) without breaking this
     * method's signature.
     *
     * @return array<string, string>
     */
    public function toW3CHeaders(): array
    {
        return ['traceparent' => $this->toTraceparent()];
    }
}
