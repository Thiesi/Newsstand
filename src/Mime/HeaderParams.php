<?php

declare(strict_types=1);

namespace Newsstand\Mime;

use Newsstand\Text\Charset;
use Newsstand\Text\HeaderDecoder;

/**
 * Parses structured header values such as Content-Type and Content-Disposition:
 * `value; name="x"; name*=utf-8''%C3%A4; name*0*=...; name*1=...` (RFC 2045, RFC 2231).
 * Also accepts RFC 2047 words inside quoted parameters, which many clients send.
 */
final class HeaderParams
{
    /** @return array{0: string, 1: array<string, string>} lowercased value, params keyed by lowercase name */
    public static function parse(?string $raw): array
    {
        if ($raw === null) {
            return ['', []];
        }
        $parts = self::split(HeaderDecoder::unfold($raw));
        $value = strtolower(trim((string) array_shift($parts)));

        /** @var array<string, array<int, array{0: string, 1: bool}>> $sections */
        $sections = [];
        $plain = [];
        foreach ($parts as $part) {
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            $name = strtolower(trim(substr($part, 0, $eq)));
            $val = self::unquote(trim(substr($part, $eq + 1)));
            if (preg_match('/^([^*]+)\*(\d+)(\*?)$/', $name, $m)) {
                $sections[$m[1]][(int) $m[2]] = [$val, $m[3] === '*'];
            } elseif (str_ends_with($name, '*')) {
                $sections[substr($name, 0, -1)][0] = [$val, true];
            } else {
                $plain[$name] = $val;
            }
        }

        $params = [];
        foreach ($plain as $name => $val) {
            $params[$name] = HeaderDecoder::decode($val);
        }
        foreach ($sections as $name => $secs) {
            ksort($secs);
            $charset = null;
            $bytes = '';
            foreach ($secs as $i => [$val, $encoded]) {
                if ($encoded) {
                    if ($i === array_key_first($secs) && preg_match("/^([^']*)'[^']*'(.*)$/s", $val, $m)) {
                        $charset = $m[1];
                        $val = $m[2];
                    }
                    $val = rawurldecode($val);
                }
                $bytes .= $val;
            }
            $params[$name] = Charset::toUtf8($bytes, $charset);
        }
        return [$value, $params];
    }

    /** @return list<string> */
    private static function split(string $raw): array
    {
        $parts = [];
        $buf = '';
        $quoted = false;
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $c = $raw[$i];
            if ($c === '\\' && $quoted && $i + 1 < $len) {
                $buf .= $c . $raw[++$i];
                continue;
            }
            if ($c === '"') {
                $quoted = !$quoted;
            } elseif ($c === ';' && !$quoted) {
                $parts[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $parts[] = $buf;
        return $parts;
    }

    private static function unquote(string $v): string
    {
        if (strlen($v) >= 2 && $v[0] === '"' && str_ends_with($v, '"')) {
            return preg_replace('/\\\\(.)/s', '$1', substr($v, 1, -1)) ?? $v;
        }
        return $v;
    }
}
