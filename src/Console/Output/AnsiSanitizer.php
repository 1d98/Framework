<?php

declare(strict_types=1);

namespace Framework\Console\Output;

/**
 * Strips ANSI control sequences and other terminal-hazardous bytes
 * from user-supplied messages before they reach a TTY. Used by
 * {@see Output::write()} and {@see Output::error()} as defense in
 * depth — a command that echoes attacker-controlled bytes
 * (filenames, exception messages, JSON strings from a response)
 * must not be able to inject cursor-move / screen-clear / bell /
 * OSC-49 hyperlinks into the user's terminal.
 *
 * Strips (per ECMA-48 §5 + xterm extensions):
 *  - CSI sequences: `ESC [` … letter (color, cursor, erase)
 *  - OSC sequences: `ESC ]` … BEL or `ESC \`
 *  - Single-character escape introducers: `ESC <char>` where char
 *    is in `0x40-0x5F` (e.g. `ESC c` for full reset)
 *  - C0 / C1 control characters except whitespace (`\t \n \r`),
 *    since the terminal interprets them too (`\x07` bell,
 *    `\x08` backspace, `\x0B`/`\x0C` line / form feed).
 *
 * NUL is also stripped — terminals treat it as a "no-op glyph"
 * but some printers and log aggregators reject it.
 *
 * The string length is preserved where possible (replacement is
 * empty), so column-counting in `Output::table()` stays sane.
 */
final class AnsiSanitizer
{
    /**
     * Pattern matches (in priority order):
     *  - `\x1B\][^\x07\x1B\x9C]*(?:\x07|\x1B\\|\x9C|$)` — OSC ending
     *    in BEL, 7-bit ST, 8-bit ST (`\x9C`), or end-of-string.
     *  - `\x1B\[[\x30-\x3F]*[\x20-\x2F]*[\x40-\x7E]` — CSI.
     *  - `\x1B[PX^_][\s\S]*?(?:\x1B\\|\x9C|$)` — DCS / SOS / PM / APC.
     *  - `\x9B[\x30-\x3F]*[\x20-\x2F]*[\x40-\x7E]` — 8-bit CSI: the
     *    single-byte form of CSI used on terminals with
     *    `stty -istrip` cleared. Without this, an attacker could
     *    bypass the `\x1B[` introducer by sending `\x9B` directly.
     *  - `\x1B[\x30-\x3F\x40-\x5F\x60-\x7E]` — 2-byte escape
     *    sequences: introducers in `0x30-0x3F` (DEC `ESC =` /
     *    `ESC >` keypad), `0x40-0x5F` (standard ECMA-48 Fp set),
     *    and `0x60-0x7E` (DEC `ESC c` reset, etc.).
     *  - `\x9C` — 8-bit String Terminator (C1 control) outside
     *    the OSC / DCS arms above.
     *  - `\x00` — NUL
     *  - `[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]` — C0 controls except
     *    `\t \n \r`
     */
    private const string PATTERN = '/\x1B\][^\x07\x1B\x9C]*(?:\x07|\x1B\\\\|\x9C|$)|'
        . '\x1B\[[\x30-\x3F]*[\x20-\x2F]*[\x40-\x7E]|'
        . '\x1B[PX^_][\s\S]*?(?:\x1B\\\\|\x9C|$)|'
        . '\x9B[\x30-\x3F]*[\x20-\x2F]*[\x40-\x7E]|'
        . '\x1B[\x30-\x3F\x40-\x5F\x60-\x7E]|'
        . '\x9C|'
        . '\x00|'
        . '[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    /**
     * Byte set that *might* trigger the regex (covers every byte
     * the pattern can match). If a string contains none of these
     * bytes, it is plain ASCII / UTF-8 printable and the regex
     * cannot possibly match — return as-is to avoid the `preg_replace`
     * setup cost on the common CLI path (no escape sequences in
     * 99% of `Output::write()` calls in a normal session).
     */
    private const string FAST_PATH_TRIGGER = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0E\x0F"
        . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F\x9B\x9C";

    public static function sanitize(string $text): string
    {
        if (strpbrk($text, self::FAST_PATH_TRIGGER) === false) {
            return $text;
        }
        $result = preg_replace(self::PATTERN, '', $text);
        return $result ?? '';
    }
}
