<?php

declare(strict_types=1);

namespace Framework\OpenApi;

/**
 * Immutable OpenAPI 3.1 document value object.
 *
 * Built once by {@see OpenApiExporter::build()}; the caller
 * either serialises to JSON via {@see self::toJson()} for
 * disk emission, or hands the document to a downstream consumer
 * (a Redocly pipeline, a CLI post-processor, a CI lint step).
 */
final readonly class OpenApiDocument
{
    /**
     * @param array<string, mixed> $payload The OpenAPI document
     *     body. Always contains at minimum `openapi: "3.1.0"`,
     *     `info: {title, version}`, and `paths: {}`.
     */
    public function __construct(
        public array $payload,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    /**
     * @param int $flags `json_encode` flags (default UNESCAPED_*
     *     for human-readable on-disk files; pass `JSON_PRETTY_PRINT`
     *     for git-friendly diffs)
     */
    public function toJson(int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): string
    {
        try {
            $encoded = json_encode($this->payload, $flags | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                'OpenApiDocument::toJson: json_encode failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
        return $encoded;
    }
}
