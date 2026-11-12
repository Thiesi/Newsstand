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
    /*
     * What counts as an address (same as this regex, which tests/ObfuscationTest.php keeps as the oracle):
     *
     *   local   "[^"\r\n]{1,64}"                                        quoted local part
     *         | [\p{L}\p{N}!#$%'*+^_`{|}~-][\p{L}\p{N}!#$%'*+^_`{|}~.-]{0,63}
     *   at      @ | %40 | ＠ | &#64; | &#x40; | &commat;                 (case-insensitive)
     *   domain  \[[0-9A-Fa-f:.]{2,45}\] | label(\.label)*
     *
     * RFC 5322 also allows & / = ? in the local part, but those are rare in real addresses and common in
     * URLs (?mail=a@b), so leaving them out keeps the rest of a URL readable. A trailing dot is allowed:
     * real addresses like "rc.@example.com" exist (found on Usenet). The local part is bounded to 64
     * characters, the limit in RFC 5321.
     *
     * Roanoke's PHP has no PCRE JIT, and running that regex over a body costs time for every character
     * (a 1 MB article took seconds). So text() scans instead: it finds the separators, checks the domain
     * after each with an anchored match, and walks back over the local part by hand. The cost grows with
     * the number of separators, not the length of the text.
     */
    private const SEPARATOR = '/@|%40|＠|&\#0*64;|&\#x0*40;|&commat;/i';
    private const DOMAIN = '/\G(?:\[[0-9A-Fa-f:.]{2,45}\]|[\p{L}\p{N}_](?:[\p{L}\p{N}_-]*[\p{L}\p{N}_])?(?:\.[\p{L}\p{N}_](?:[\p{L}\p{N}_-]*[\p{L}\p{N}_])?)*)/u';
    private const LOCAL_ASCII = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!#$%'*+^_`{|}~.-";
    private const LOCAL_MAX = 64;
    /** More separators than this in one piece of text (a header or a paragraph) is not prose: hide it. */
    private const MAX_SEPARATORS = 5000;

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
        if (!mb_check_encoding($text, 'UTF-8')) {
            return '[hidden]'; // never fall back to raw text we cannot read
        }
        $out = '';
        $done = 0; // bytes of $text already copied to $out; also where the last address ended
        $seen = 0;
        $sep = $this->nextSeparator($text, 0);
        while ($sep !== null) {
            if (++$seen > self::MAX_SEPARATORS) {
                return '[hidden: too many addresses to show]';
            }
            [$at, $pos] = $sep;
            $sep = $this->nextSeparator($text, $pos + strlen($at));
            $domain = $this->domainAt($text, $pos + strlen($at));
            if ($domain === null) {
                continue;
            }
            $start = $this->localStart($text, $pos, $done);
            if ($start === null) {
                continue;
            }
            $quoted = $text[$start] !== '"' ? $this->enclosingQuoted($text, $start, $pos, $done) : null;
            if ($quoted !== null) {
                // A quoted local part around this one, ending at a later separator, starts further left:
                // the leftmost match wins, as with the regex.
                [$start, $pos, $at, $domain] = $quoted;
            } elseif ($text[$start] !== '"') {
                // Like the regex's greedy local part: "%40" is made of local-part characters, so a longer
                // local part may run through it to a later separator. Take the farthest one reachable.
                $runEnd = $this->localRunEnd($text, $start);
                while ($sep !== null && $sep[1] <= $runEnd) {
                    ++$seen;
                    $later = $this->domainAt($text, $sep[1] + strlen($sep[0]));
                    if ($later !== null) {
                        [$at, $pos, $domain] = [$sep[0], $sep[1], $later];
                    }
                    $sep = $this->nextSeparator($text, $sep[1] + strlen($sep[0]));
                }
            }
            $out .= substr($text, $done, $start - $done)
                . $this->disguiseLocal(substr($text, $start, $pos - $start))
                . $at . $domain;
            $done = $pos + strlen($at) + strlen($domain);
            if ($sep !== null && $sep[1] < $done) {
                $sep = $this->nextSeparator($text, $done); // none can start inside the address just handled
            }
        }
        return $out . substr($text, $done);
    }

    /** @return array{0: string, 1: int}|null the next separator at or after byte $from, and its offset */
    private function nextSeparator(string $text, int $from): ?array
    {
        if ($from >= strlen($text) || preg_match(self::SEPARATOR, $text, $m, PREG_OFFSET_CAPTURE, $from) !== 1) {
            return null;
        }
        return [$m[0][0], $m[0][1]];
    }

    /**
     * A quoted local part that encloses the unquoted one at $start..$pos: an opening quote before $start
     * (not before $floor), the next quote after $pos, a separator and a domain right after it.
     *
     * @return array{0: int, 1: int, 2: string, 3: string}|null start, separator offset, separator, domain
     */
    private function enclosingQuoted(string $text, int $start, int $pos, int $floor): ?array
    {
        $open = $start > $floor ? strrpos(substr($text, $floor, $start - $floor), '"') : false;
        if ($open === false) {
            return null;
        }
        $open += $floor;
        $close = strpos($text, '"', $pos);
        if ($close === false) {
            return null;
        }
        $inner = substr($text, $open + 1, $close - $open - 1);
        $len = mb_strlen($inner);
        if ($len < 1 || $len > self::LOCAL_MAX || strpbrk($inner, "\r\n") !== false) {
            return null;
        }
        if (preg_match(self::SEPARATOR, substr($text, $close + 1, 64), $sm, PREG_OFFSET_CAPTURE) !== 1 || $sm[0][1] !== 0) {
            return null;
        }
        $at = $sm[0][0];
        $domain = $this->domainAt($text, $close + 1 + strlen($at));
        return $domain === null ? null : [$open, $close + 1, $at, $domain];
    }

    /** The domain starting at byte $offset, or null when there is none there. */
    private function domainAt(string $text, int $offset): ?string
    {
        // A bounded slice (cut on a character boundary), so each check costs the same however long the
        // text is. Domains longer than this are rare; their tail just stays as it is.
        if (preg_match(self::DOMAIN, mb_strcut($text, $offset, 600, 'UTF-8'), $m) !== 1 || $m[0] === '') {
            return null;
        }
        return $m[0];
    }

    /** Byte offset just after the longest local part (at most 64 characters) starting at $start. */
    private function localRunEnd(string $text, int $start): int
    {
        $i = $start;
        $len = strlen($text);
        for ($chars = 0; $chars < self::LOCAL_MAX && $i < $len; $chars++) {
            $byte = $text[$i];
            if (ord($byte) < 0x80) {
                if (strpos(self::LOCAL_ASCII, $byte) === false) {
                    break;
                }
                $i++;
                continue;
            }
            $charLen = ord($byte) >= 0xF0 ? 4 : (ord($byte) >= 0xE0 ? 3 : 2);
            if (!preg_match('/^[\p{L}\p{N}]$/u', substr($text, $i, $charLen))) {
                break;
            }
            $i += $charLen;
        }
        return $i;
    }

    /**
     * Byte offset where the local part ending at $pos starts, or null when there is none. Never earlier
     * than $floor (the end of the previous address). Leftmost start wins, like the regex.
     */
    private function localStart(string $text, int $pos, int $floor): ?int
    {
        if ($pos <= $floor) {
            return null;
        }
        // Quoted: "…" directly before the separator, 1–64 characters, no quotes or line breaks inside.
        if ($text[$pos - 1] === '"') {
            $open = strrpos(substr($text, $floor, $pos - 1 - $floor), '"');
            if ($open === false) {
                return null;
            }
            $open += $floor;
            $inner = substr($text, $open + 1, $pos - 2 - $open);
            $len = mb_strlen($inner);
            return ($len >= 1 && $len <= self::LOCAL_MAX && strpbrk($inner, "\r\n") === false) ? $open : null;
        }
        // Unquoted: walk back over local-part characters, at most 64 of them.
        $i = $pos;
        $chars = 0;
        $starts = []; // byte offsets of the characters walked over, nearest first
        while ($i > $floor && $chars < self::LOCAL_MAX) {
            $byte = $text[$i - 1];
            if (ord($byte) < 0x80) {
                if (strpos(self::LOCAL_ASCII, $byte) === false) {
                    break;
                }
                $i--;
            } else {
                $j = $i - 1;
                while ($j > $floor && $j > $i - 4 && (ord($text[$j]) & 0xC0) === 0x80) {
                    $j--;
                }
                if (!preg_match('/^[\p{L}\p{N}]$/u', substr($text, $j, $i - $j))) {
                    break;
                }
                $i = $j;
            }
            $starts[] = $i;
            $chars++;
        }
        // The first character may not be a dot: move the start forward past leading dots.
        for ($k = count($starts) - 1; $k >= 0; $k--) {
            if ($text[$starts[$k]] !== '.') {
                return $starts[$k];
            }
        }
        return null;
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
