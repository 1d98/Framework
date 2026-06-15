<?php

declare(strict_types=1);

namespace Framework\Console\Output;

/**
 * Shared ANSI-decoration logic for the production {@see Output} and
 * the test-double in {@see \Framework\Tests\Support\MemoryOutput}.
 * Extracted to a small base class so the prod class and the
 * test-helper stay in sync without duplication: both decorate their
 * user-supplied message with SGR wrappers around a fixed icon
 * (success / info / warning / danger), with the user-message
 * sanitized via {@see AnsiSanitizer::sanitize()} before concatenation.
 *
 * The base is the single source of truth for the decoration
 * contract — if a future framework release changes colors or icons,
 * both classes pick the change up automatically.
 *
 * `useAnsi()` is abstract so each subclass owns its own boolean
 * (real CLI auto-detects via TTY; test-double is configured
 * explicitly). `writeDecorated()` is the single point that
 * concatenates the icon + sanitized message and dispatches to the
 * subclass-specific write (real `fwrite` vs in-memory stream).
 */
abstract class BaseOutput implements OutputInterface
{
    abstract public function useAnsi(): bool;

    /**
     * Subclass-specific decorator: concatenate the icon + user-message
     * inside the supplied SGR wrapper and dispatch to the subclass's
     * underlying write mechanism. **The base class intentionally does
     * NOT sanitize `$message` here** — each subclass owns that
     * decision:
     *
     *   - {@see Output} (production) calls {@see AnsiSanitizer::sanitize()}
     *     on the message before concatenation so terminal-injection
     *     payloads cannot reach the TTY.
     *   - {@see \Framework\Tests\Support\MemoryOutput} (test-double)
     *     passes the message through verbatim so byte-exact assertions
     *     work. Tests that need sanitization explicitly call
     *     `AnsiSanitizer::sanitize()` on the captured output.
     *
     * @param string $open  SGR open sequence (e.g. `"\033[32m"`)
     * @param string $close SGR close sequence (e.g. `"\033[0m"`)
     * @param string $icon  Fixed prefix (e.g. `'✓ '`)
     * @param string $message  User-supplied message (sanitization is the subclass's responsibility)
     */
    abstract protected function writeDecorated(string $open, string $close, string $icon, string $message): void;

    public function success(string $message): void
    {
        $this->writeDecorated("\033[32m", "\033[0m", '✓ ', $message);
    }

    public function info(string $message): void
    {
        $this->writeDecorated("\033[34m", "\033[0m", 'ℹ ', $message);
    }

    public function warning(string $message): void
    {
        $this->writeDecorated("\033[33m", "\033[0m", '! ', $message);
    }

    public function danger(string $message): void
    {
        $this->writeDecorated("\033[31m", "\033[0m", '✗ ', $message);
    }
}
