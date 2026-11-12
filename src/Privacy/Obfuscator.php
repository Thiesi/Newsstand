<?php

declare(strict_types=1);

namespace Newsstand\Privacy;

/**
 * THE central place where addresses are rendered. Every piece of article-derived text (headers, bodies,
 * subjects, group descriptions, page titles, Message-IDs) goes through text() before it is escaped.
 *
 * Anonymous visitors see `jona…@example.com`; logged-in users (phase 3) see the original.
 * Message-IDs have the same local@domain shape and are disguised the same way.
 *
 * The pattern errs on the side of disguising too much: anything shaped like local@domain is treated as an
 * address, including Message-IDs, `user@localhost`, `%40` and the fullwidth ＠ sign. Already-disguised
 * forms such as `user (at) example.com` contain no @ and are left alone.
 *
 * A miss does not break anything visibly, it only leaks an address. tests/ObfuscationTest.php is the
 * safeguard; extend its fixtures whenever a new real-world format turns up.
 */
final class Obfuscator
{
    private const PATTERN = '/
        (?<local>
            "[^"\r\n]{1,64}"                                  # quoted local part
            # Unquoted local part. RFC 5322 also allows & \/ = ?, but those are rare in real addresses
            # and common in URLs (?mail=a@b), so leaving them out keeps the rest of a URL readable.
            # A trailing dot is allowed: real addresses like "rc.@example.com" exist (found on Usenet).
            # Bounded to 64 characters (the limit in RFC 5321) so a long run without an @ costs linear time,
            # not quadratic backtracking (a 64 KB line of letters must not tie up a worker).
          | [\p{L}\p{N}!\#$%\'*+^_`{|}~-]
            [\p{L}\p{N}!\#$%\'*+^_`{|}~.-]{0,63}
        )
        (?<at>@|%40|＠|&\#0*64;|&\#x0*40;|&commat;)
        (?<domain>
            \[[0-9A-Fa-f:.]{2,45}\]                           # address literal
          | [\p{L}\p{N}_](?:[\p{L}\p{N}_-]*[\p{L}\p{N}_])?
            (?:\.[\p{L}\p{N}_](?:[\p{L}\p{N}_-]*[\p{L}\p{N}_])?)*
        )
    /xiu';

    public function __construct(private readonly bool $reveal = false)
    {
    }

    public static function anonymous(): self
    {
        return new self(false);
    }

    public function reveals(): bool
    {
        return $this->reveal;
    }

    /** Disguises every address-shaped substring of $text. Returns plain text (not yet HTML-escaped). */
    public function text(string $text): string
    {
        if ($this->reveal || $text === '') {
            return $text;
        }
        // Fast path: no form of the @ separator at all (most lines). Invalid UTF-8 still goes to the regex,
        // which fails and hides the text.
        if (!preg_match('/@|%40|＠|&#|&commat;/i', $text) && mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $out = preg_replace_callback(
            self::PATTERN,
            fn (array $m): string => $this->disguiseLocal($m['local']) . $m['at'] . $m['domain'],
            $text,
        );
        if ($out === null) {
            // A regex failure (invalid UTF-8, backtrack limit) must never fall back to the raw text.
            return '[hidden]';
        }
        return $out;
    }

    /** For a single address: same as text(), named for readability at call sites. */
    public function address(string $address): string
    {
        return $this->text($address);
    }

    private function disguiseLocal(string $local): string
    {
        $quoted = str_starts_with($local, '"');
        $inner = $quoted ? substr($local, 1, -1) : $local;
        $len = mb_strlen($inner);
        $keep = $len <= 1 ? 0 : min(4, max(1, $len - 2));
        // Do not let the kept prefix end in a separator that hints at the rest.
        $prefix = rtrim(mb_substr($inner, 0, $keep), '.-_+');
        $disguised = $prefix . '…';
        return $quoted ? '"' . $disguised . '"' : $disguised;
    }
}
