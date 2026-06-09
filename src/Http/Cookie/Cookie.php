<?php

declare(strict_types=1);

namespace Framework\Http\Cookie;

use InvalidArgumentException;

final readonly class Cookie
{
    public const SAME_SITE_VALUES = ['Lax', 'Strict', 'None'];

    public function __construct(
        public string $name,
        public string $value,
        public int $expiresAt = 0,
        public string $path = '/',
        public ?string $domain = null,
        public bool $secure = false,
        public bool $httpOnly = true,
        public string $sameSite = 'Lax',
    ) {
        self::assertNoCrlf('name', $this->name);
        self::assertNoCrlf('value', $this->value);
        self::assertNoCrlf('path', $this->path);
        if ($this->domain !== null) {
            self::assertNoCrlf('domain', $this->domain);
        }

        if (!in_array($this->sameSite, self::SAME_SITE_VALUES, true)) {
            throw new InvalidArgumentException(
                'Cookie: sameSite must be "Lax", "Strict", or "None", got "' . $this->sameSite . '"',
            );
        }

        if ($this->sameSite === 'None' && !$this->secure) {
            throw new InvalidArgumentException(
                "Cookie '{$this->name}': SameSite=None requires Secure=true (RFC 6265bis). Browsers will silently drop this cookie.",
            );
        }
    }

    public function toHeaderValue(): string
    {
        self::assertNoCrlf('name', $this->name);
        self::assertNoCrlf('value', $this->value);
        self::assertNoCrlf('path', $this->path);
        if ($this->domain !== null) {
            self::assertNoCrlf('domain', $this->domain);
        }

        $parts = [$this->name . '=' . $this->value];

        if ($this->expiresAt > 0) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', $this->expiresAt) . ' GMT';
            $parts[] = 'Max-Age=' . ($this->expiresAt - time());
        }

        if ($this->path !== '/') {
            $parts[] = 'Path=' . $this->path;
        }

        if ($this->domain !== null) {
            $parts[] = 'Domain=' . $this->domain;
        }

        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=' . $this->sameSite;

        return implode('; ', $parts);
    }

    private static function assertNoCrlf(string $field, string $value): void
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new InvalidArgumentException(
                "Cookie {$field} contains CRLF: {$value}",
            );
        }
    }
}
