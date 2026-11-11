<?php

declare(strict_types=1);

namespace Newsstand\Web;

final class Html
{
    public static function e(string|int|null $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Escapes plain text and turns http(s) URLs into links. Call it on text that has ALREADY been through
     * the Obfuscator: URLs that contain a disguised address are shown but not linked.
     */
    public static function linkify(string $text): string
    {
        $out = '';
        $offset = 0;
        preg_match_all('#\bhttps?://[^\s<>"\'\x{00A0}\x{2028}]+#iu', $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$url, $pos]) {
            $trimmed = rtrim($url, '.,;:!?\'"*_');
            if (str_ends_with($trimmed, ')') && !str_contains($trimmed, '(')) {
                $trimmed = rtrim(substr($trimmed, 0, -1), '.,;:!?');
            }
            if (str_ends_with($trimmed, '>')) {
                $trimmed = substr($trimmed, 0, -1);
            }
            $out .= self::e(substr($text, $offset, $pos - $offset));
            if (str_contains($trimmed, '…') || strlen($trimmed) < 10) {
                $out .= self::e($trimmed);
            } else {
                $out .= '<a href="' . self::e($trimmed) . '" rel="nofollow ugc noopener">' . self::e($trimmed) . '</a>';
            }
            $offset = $pos + strlen($trimmed);
        }
        return $out . self::e(substr($text, $offset));
    }

    public static function groupUrl(string $group, ?int $before = null): string
    {
        return '/g/' . rawurlencode($group) . ($before !== null ? '?before=' . $before : '');
    }

    public static function articleUrl(string $group, int $number): string
    {
        return '/g/' . rawurlencode($group) . '/' . $number;
    }

    public static function browseUrl(string $prefix): string
    {
        return $prefix === '' ? '/browse' : '/browse/' . rawurlencode($prefix);
    }

    public static function number(int $n): string
    {
        return number_format($n, 0, '.', ',');
    }

    public static function date(?int $ts): string
    {
        return $ts === null ? '' : gmdate('j M Y, H:i', $ts) . ' UTC';
    }

    public static function isoDate(?int $ts): string
    {
        return $ts === null ? '' : gmdate('Y-m-d\TH:i\Z', $ts);
    }

    public static function bytes(int $n): string
    {
        return match (true) {
            $n >= 1048576 => number_format($n / 1048576, 1) . ' MB',
            $n >= 1024 => number_format($n / 1024, 0) . ' KB',
            default => $n . ' bytes',
        };
    }
}
