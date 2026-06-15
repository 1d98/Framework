<?php

declare(strict_types=1);

namespace Framework\Logging;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class StreamLogger implements LoggerInterface
{
    private const int LEVEL_DEBUG = 0;
    private const int LEVEL_INFO = 1;
    private const int LEVEL_WARNING = 2;
    private const int LEVEL_ERROR = 3;

    private const array LEVEL_MAP = [
        'debug' => self::LEVEL_DEBUG,
        'info' => self::LEVEL_INFO,
        'warning' => self::LEVEL_WARNING,
        'error' => self::LEVEL_ERROR,
    ];

    /** @var resource */
    private $stream;

    private readonly bool $ownsStream;
    private readonly bool $withLock;

    /**
     * @param resource|string $stream Open stream resource, or a filesystem path that will be opened in append mode.
     * @param bool $withLock When `true` (default for filesystem paths,
     *     `false` for pre-opened stream resources), wrap each write
     *     in `flock(LOCK_EX)` so concurrent processes (PHP-FPM
     *     workers, Octane/Swoole) cannot interleave a single
     *     log line with another. Stdout/stderr streams opened via
     *     `self::stdout()` / `self::stderr()` default to `false`
     *     because `flock` is a no-op on pipes on some platforms.
     */
    public function __construct(
        $stream,
        private readonly string $minLevel = 'debug',
        private readonly bool $withMs = true,
        ?bool $withLock = null,
    ) {
        $ownsStream = false;
        if (is_string($stream)) {
            $opened = fopen($stream, 'a');
            if ($opened === false) {
                throw new RuntimeException("Cannot open stream: {$stream}");
            }
            $stream = $opened;
            $ownsStream = true;
        }
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Stream must be a valid resource or a string path');
        }
        if (!isset(self::LEVEL_MAP[$minLevel])) {
            throw new InvalidArgumentException("Unknown log level: {$minLevel}");
        }
        $this->stream = $stream;
        $this->ownsStream = $ownsStream;
        $this->withLock = $withLock ?? $ownsStream;
    }

    public function __destruct()
    {
        if ($this->ownsStream && is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public static function stderr(): self
    {
        return new self(self::open('php://stderr', 'w'));
    }

    public static function stdout(): self
    {
        return new self(self::open('php://stdout', 'w'));
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write(self::LEVEL_DEBUG, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write(self::LEVEL_INFO, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write(self::LEVEL_WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(int $level, string $message, array $context): void
    {
        if ($level < self::LEVEL_MAP[$this->minLevel]) {
            return;
        }

        $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format($this->withMs ? 'Y-m-d H:i:s.v' : 'Y-m-d H:i:s');

        $levelName = self::reverseLevel($level);

        $contextPart = $context === []
            ? ''
            : ' ' . $this->encodeContext($context);

        $line = "[{$timestamp}] {$levelName} {$message}{$contextPart}\n";

        if ($this->withLock) {
            $this->lockedWrite($line);
        } else {
            fwrite($this->stream, $line);
        }
    }

    /**
     * Write `$line` to the underlying stream under `flock(LOCK_EX)`.
     *
     * **Fail-soft on `flock` rejection.** Some filesystems (notably
     * NFS, and some FUSE mounts) report `flock` as unsupported and
     * return `false` instead of blocking. In that case we fall
     * through to an unlocked `fwrite` rather than lose the log line
     * — better to risk two concurrent writes interleaving than to
     * silently drop a security event. The trade-off is documented
     * in the class-level PHPDoc on `$withLock`.
     */
    private function lockedWrite(string $line): void
    {
        if (!flock($this->stream, LOCK_EX)) {
            fwrite($this->stream, $line);
            return;
        }
        try {
            fwrite($this->stream, $line);
        } finally {
            flock($this->stream, LOCK_UN);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function encodeContext(array $context): string
    {
        try {
            $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            return $encoded;
        } catch (JsonException $e) {
            return $this->encodeUnencodablePlaceholder();
        }
    }

    /**
     * Defense-in-depth last-resort encoder used when the primary `json_encode`
     * of a context array fails (e.g. circular reference, resource, unencodable
     * object). It MUST NEVER throw, because `StreamLogger` is on the hot path
     * for error reporting — a `JsonException` escaping this method would
     * replace the original failure with a logging failure, masking the real
     * problem from operators.
     *
     * We deliberately avoid `JSON_THROW_ON_ERROR` here and, if even the
     * fallback payload cannot be encoded, return a hardcoded literal string.
     * No further `json_encode` call is made after that point, so recursion is
     * impossible.
     */
    private function encodeUnencodablePlaceholder(): string
    {
        $fallback = ['unencodable' => 'array'];
        $encoded = json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return '{"unencodable":"<unencodable>"}';
        }
        return $encoded;
    }

    private static function reverseLevel(int $level): string
    {
        foreach (self::LEVEL_MAP as $name => $value) {
            if ($value === $level) {
                return strtoupper($name);
            }
        }
        return 'UNKNOWN';
    }

    /**
     * @return resource
     */
    private static function open(string $path, string $mode)
    {
        $stream = fopen($path, $mode);
        if ($stream === false) {
            throw new RuntimeException("Cannot open stream: {$path}");
        }
        return $stream;
    }
}
