<?php

declare(strict_types=1);

namespace Newsstand\Privacy;

/**
 * Finds addresses that slipped through the Obfuscator in rendered HTML. Deliberately independent of the
 * Obfuscator's own pattern: it looks at every visible @ (after decoding entities, %40 and ＠) and
 * accepts only those that directly follow a disguised local part ("…@") or have no local part at all.
 *
 * Used by tests/ObfuscationTest.php and bin/scan-leaks.php.
 */
final class LeakDetector
{
    /** @return list<string> the leaked snippets */
    public static function find(string $html): array
    {
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = rawurldecode($text);
        $text = str_replace(['＠', '&commat;'], '@', $text);
        $leaks = [];
        preg_match_all('/@/u', $text, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [, $pos]) {
            // Byte offsets can split a multi-byte character; scrub so the /u patterns below work.
            $before = mb_scrub(substr($text, max(0, $pos - 40), min(40, $pos)), 'UTF-8');
            $after = mb_scrub(substr($text, $pos + 1, 30), 'UTF-8');
            if (!preg_match('/^[\p{L}\p{N}\[]/u', $after)) {
                continue; // not followed by a domain: not an address
            }
            if (preg_match('/(…"?|\[(\.\.\.|…)\])$/u', $before)) {
                continue; // disguised by us, or already elided by the author's software ("[...]@example.de")
            }
            if (preg_match('/[\s<>(\[,;:\/=]$/u', $before) || $before === '') {
                continue; // "@domain" without a local part
            }
            $leaks[] = trim(substr($before, -20)) . '@' . substr($after, 0, 20);
        }
        return $leaks;
    }
}
