<?php

declare(strict_types=1);

namespace Newsstand\Text;

/**
 * Decodes header values: RFC 2047 encoded-words, plus raw 8-bit text in an unknown charset.
 * Adjacent encoded-words in the same charset are joined before conversion, because senders split
 * multi-byte characters across words.
 */
final class HeaderDecoder
{
    private const WORD = '/=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=/';

    public static function decode(string $raw): string
    {
        $raw = self::unfold($raw);
        if (!str_contains($raw, '=?')) {
            return trim(Charset::toUtf8($raw));
        }

        $out = '';
        $pendingCharset = null;
        $pendingBytes = '';
        $offset = 0;
        $flush = static function () use (&$out, &$pendingCharset, &$pendingBytes): void {
            if ($pendingCharset !== null) {
                $out .= Charset::toUtf8($pendingBytes, $pendingCharset);
                $pendingCharset = null;
                $pendingBytes = '';
            }
        };

        preg_match_all(self::WORD, $raw, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $m) {
            $start = $m[0][1];
            $between = substr($raw, $offset, $start - $offset);
            $charset = strtolower(preg_replace('/\*.*$/', '', $m[1][0]) ?? $m[1][0]);
            $bytes = self::decodeWord(strtoupper($m[2][0]), $m[3][0]);

            if ($pendingCharset !== null && trim($between) === '') {
                // Whitespace between encoded-words is not displayed (RFC 2047 section 6.2).
                if ($charset === $pendingCharset) {
                    $pendingBytes .= $bytes;
                } else {
                    $flush();
                    $pendingCharset = $charset;
                    $pendingBytes = $bytes;
                }
            } else {
                $flush();
                $out .= Charset::toUtf8($between);
                $pendingCharset = $charset;
                $pendingBytes = $bytes;
            }
            $offset = $start + strlen($m[0][0]);
        }
        $flush();
        $out .= Charset::toUtf8(substr($raw, $offset));

        return trim(preg_replace('/[\r\n\t]+/', ' ', $out) ?? $out);
    }

    public static function unfold(string $raw): string
    {
        return preg_replace('/\r?\n(?=[ \t])/', '', $raw) ?? $raw;
    }

    private static function decodeWord(string $encoding, string $text): string
    {
        if ($encoding === 'B') {
            $decoded = base64_decode($text, false);
            return $decoded === false ? '' : $decoded;
        }
        return quoted_printable_decode(str_replace('_', ' ', $text));
    }
}
