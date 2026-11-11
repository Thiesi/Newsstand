<?php

declare(strict_types=1);

namespace Newsstand\Mime;

/** Splits body text into runs of equal quote depth, plus the signature after the last "-- " line. */
final class BodyFormatter
{
    /** @return list<array{level: int, signature: bool, text: string}> */
    public static function blocks(string $text): array
    {
        $lines = explode("\n", str_replace("\r", '', $text));
        $sigStart = null;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if ($lines[$i] === '-- ' || $lines[$i] === '--') {
                // A bare "--" only counts near the end, where broken clients leave it.
                if ($lines[$i] === '-- ' || count($lines) - $i <= 10) {
                    $sigStart = $i;
                }
                break;
            }
        }

        $blocks = [];
        foreach ($lines as $i => $line) {
            $signature = $sigStart !== null && $i >= $sigStart;
            $level = 0;
            if (!$signature && preg_match('/^((?:>[ \t]?)+)/', $line, $m)) {
                $level = substr_count($m[1], '>');
            }
            $last = count($blocks) - 1;
            if ($last >= 0 && $blocks[$last]['level'] === $level && $blocks[$last]['signature'] === $signature) {
                $blocks[$last]['text'] .= "\n" . $line;
            } else {
                $blocks[] = ['level' => $level, 'signature' => $signature, 'text' => $line];
            }
        }
        // Trim blank lines at the edges of the whole body.
        while ($blocks !== [] && trim($blocks[0]['text']) === '') {
            array_shift($blocks);
        }
        while ($blocks !== [] && trim($blocks[count($blocks) - 1]['text']) === '') {
            array_pop($blocks);
        }
        return $blocks;
    }
}
