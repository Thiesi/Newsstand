<?php

declare(strict_types=1);

namespace Newsstand\Text;

/** Converts article bytes of any (or unknown) charset to valid UTF-8. */
final class Charset
{
    /** Labels that promise nothing; sniff instead. */
    private const UNKNOWN = ['', 'us-ascii', 'ascii', 'x-unknown', 'unknown', 'unknown-8bit', 'x-user-defined', 'default'];

    /** Labels decoded as a superset, as browsers do (WHATWG Encoding). */
    private const ALIASES = [
        'iso-8859-1' => 'Windows-1252',
        'latin1' => 'Windows-1252',
        'iso8859-1' => 'Windows-1252',
        'iso_8859-1' => 'Windows-1252',
        'us-ascii' => 'Windows-1252',
        'iso-8859-9' => 'Windows-1254',
        'ks_c_5601-1987' => 'CP949',
        'gb2312' => 'GB18030',
        'gbk' => 'GB18030',
        'x-mac-roman' => 'MacRoman',
        'utf8' => 'UTF-8',
        'unicode-1-1-utf-7' => 'UTF-7',
    ];

    /**
     * @param string|null $label the declared charset, if any
     */
    public static function toUtf8(string $bytes, ?string $label = null): string
    {
        if ($bytes === '' || !preg_match('/[\x80-\xFF]/', $bytes) && !self::needsConversion($label)) {
            return self::stripControls($bytes);
        }
        $label = strtolower(trim((string) $label, " \t\"'"));
        $label = preg_replace('/\*.*$/', '', $label) ?? $label; // RFC 2231 language suffix

        if (in_array($label, self::UNKNOWN, true) || $label === 'utf-8') {
            if (mb_check_encoding($bytes, 'UTF-8')) {
                return self::stripControls($bytes);
            }
            return self::stripControls(self::convert($bytes, 'Windows-1252'));
        }

        $converted = self::convert($bytes, self::ALIASES[$label] ?? $label);
        return self::stripControls($converted);
    }

    /** 7-bit charsets that still need decoding. */
    private static function needsConversion(?string $label): bool
    {
        $label = strtolower(trim((string) $label));
        return in_array($label, ['utf-7', 'unicode-1-1-utf-7', 'iso-2022-jp', 'iso-2022-kr', 'iso-2022-cn', 'hz-gb-2312'], true);
    }

    private static function convert(string $bytes, string $from): string
    {
        try {
            $out = @mb_convert_encoding($bytes, 'UTF-8', $from);
            if (is_string($out)) {
                return mb_scrub($out, 'UTF-8');
            }
        } catch (\ValueError) {
            // mbstring does not know this charset; try iconv.
        }
        $out = @iconv($from, 'UTF-8//IGNORE', $bytes);
        if (is_string($out) && $out !== '') {
            return mb_scrub($out, 'UTF-8');
        }
        // Unknown charset: best guess.
        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : self::convert($bytes, 'Windows-1252');
    }

    /** Drops C0 controls except tab and newline, plus DEL; keeps the text safe to render. */
    private static function stripControls(string $s): string
    {
        $s = mb_scrub($s, 'UTF-8');
        return preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/', '', $s) ?? '';
    }
}
