<?php

declare(strict_types=1);

namespace Newsstand\Mime;

/** Undoes format=flowed (RFC 3676): joins soft-broken lines into paragraphs, keeping quote depth. */
final class Flowed
{
    public static function decode(string $text, bool $delSp): string
    {
        $out = [];
        $buffer = null;
        $bufferDepth = 0;
        foreach (explode("\n", $text) as $line) {
            preg_match('/^(>*)(.*)$/s', $line, $m);
            $depth = strlen($m[1]);
            $content = $m[2];
            if (str_starts_with($content, ' ')) {
                $content = substr($content, 1); // space-stuffing
            }
            $isSigSep = $content === '-- ';
            $flowed = !$isSigSep && str_ends_with($content, ' ');

            if ($buffer !== null && $depth !== $bufferDepth) {
                $out[] = self::prefix($bufferDepth) . $buffer;
                $buffer = null;
            }
            if ($flowed && $delSp) {
                $content = substr($content, 0, -1);
            }
            $buffer = ($buffer ?? '') . $content;
            $bufferDepth = $depth;
            if (!$flowed) {
                $out[] = self::prefix($depth) . $buffer;
                $buffer = null;
            }
        }
        if ($buffer !== null) {
            $out[] = self::prefix($bufferDepth) . $buffer;
        }
        return implode("\n", $out);
    }

    private static function prefix(int $depth): string
    {
        return $depth === 0 ? '' : str_repeat('>', $depth) . ' ';
    }
}
