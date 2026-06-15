<?php

declare(strict_types=1);

namespace Framework\Http\Response;

use Framework\Http\Cookie\Cookie;
use Framework\Http\Exception\InternalServerErrorHttpException;
use InvalidArgumentException;

final readonly class Response
{
    /**
     * @param array<string, string> $headers
     * @param list<Cookie> $cookies
     */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = [],
        public array $cookies = [],
        public ?string $reasonPhrase = null,
    ) {
        if ($this->reasonPhrase !== null) {
            self::assertValidReasonPhrase($this->reasonPhrase);
        }
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new InternalServerErrorHttpException('json_encode failed: ' . json_last_error_msg());
        }

        return new self($status, $encoded, ['Content-Type' => 'application/json']);
    }

    public static function empty(int $status = 204): self
    {
        return new self($status, '');
    }

    public static function noContent(): self
    {
        return new self(204, '');
    }

    /**
     * @param array<string, string> $headers
     */
    public static function redirect(string $location, int $status = 302, array $headers = []): self
    {
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new InvalidArgumentException(
                sprintf('Redirect status must be a 3xx redirect code (301, 302, 303, 307, 308); got %d', $status),
            );
        }

        self::assertValidHeaderValue($location);

        return new self($status, '', array_merge(['Location' => $location], $headers));
    }

    public function withHeader(string $name, string $value): self
    {
        self::assertValidHeaderName($name);
        self::assertValidHeaderValue($value);

        return new self($this->status, $this->body, array_merge($this->headers, [$name => $value]), $this->cookies, $this->reasonPhrase);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            self::assertValidHeaderName((string) $name);
            self::assertValidHeaderValue((string) $value);
        }

        return new self($this->status, $this->body, array_merge($this->headers, $headers), $this->cookies, $this->reasonPhrase);
    }

    public function withRequestId(string $id): self
    {
        return $this->withHeader('X-Request-Id', $id);
    }

    /**
     * @deprecated since 0.5.x — use {@see self::withStatus()} instead.
     *     The method is kept for backward compatibility; new code should
     *     use the immutable builder.
     */
    public function setStatus(int $code, ?string $reason = null): self
    {
        if ($reason !== null) {
            self::assertValidReasonPhrase($reason);
        }

        return new self($code, $this->body, $this->headers, $this->cookies, $reason);
    }

    public function withStatus(int $status, ?string $reason = null): self
    {
        return $this->setStatus($status, $reason);
    }

    public function withBody(string $body): self
    {
        return new self($this->status, $body, $this->headers, $this->cookies, $this->reasonPhrase);
    }

    public function withCookie(Cookie $c): self
    {
        return new self($this->status, $this->body, $this->headers, array_merge($this->cookies, [$c]), $this->reasonPhrase);
    }

    /**
     * @return list<Cookie>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * @return list<string>
     */
    public function toHeaderLines(): array
    {
        $lines = [];
        foreach ($this->headers as $name => $value) {
            self::assertValidHeaderName($name);
            self::assertValidHeaderValue($value);
            $lines[] = "{$name}: {$value}";
        }
        foreach ($this->cookies as $cookie) {
            $lines[] = 'Set-Cookie: ' . $cookie->toHeaderValue();
        }

        return $lines;
    }

    public function send(): void
    {
        $statusLine = $this->buildStatusLine();
        self::assertNoHeaderLineInjection($statusLine);
        header($statusLine, true, $this->status);
        foreach ($this->toHeaderLines() as $line) {
            self::assertNoHeaderLineInjection($line);
            header($line);
        }
        echo $this->body;
    }

    private function buildStatusLine(): string
    {
        $reason = $this->reasonPhrase ?? StatusText::for($this->status) ?? '';

        return 'HTTP/1.1 ' . $this->status . ($reason !== '' ? ' ' . $reason : '');
    }

    private static function assertValidHeaderName(string $name): void
    {
        if (preg_match('/[\r\n\0:]/', $name) === 1) {
            throw new InvalidArgumentException("Header name contains invalid character: {$name}");
        }
    }

    private static function assertValidHeaderValue(string $value): void
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException("Header value contains control character: {$value}");
        }
    }

    private static function assertNoHeaderLineInjection(string $line): void
    {
        if (preg_match('/[\r\n]/', $line) === 1) {
            throw new InvalidArgumentException("Header line contains CRLF: {$line}");
        }
    }

    private static function assertValidReasonPhrase(string $reason): void
    {
        if (preg_match('/[\r\n\0]/', $reason) === 1) {
            throw new InvalidArgumentException("Status reason phrase contains CRLF: {$reason}");
        }
    }
}
