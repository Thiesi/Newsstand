<?php

declare(strict_types=1);

namespace Newsstand\Mime;

use Newsstand\Text\HeaderDecoder;

/** An ordered, case-insensitive header list. Values are unfolded but otherwise raw. */
final class Headers
{
    /** @param list<array{0: string, 1: string}> $fields */
    private function __construct(private readonly array $fields)
    {
    }

    public static function parse(string $block): self
    {
        $fields = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $block)) as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $fields !== []) {
                $fields[count($fields) - 1][1] .= $line;
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }
            $fields[] = [substr($line, 0, $colon), ltrim(substr($line, $colon + 1), " \t")];
        }
        return new self($fields);
    }

    public function get(string $name): ?string
    {
        foreach ($this->fields as [$n, $v]) {
            if (strcasecmp($n, $name) === 0) {
                return rtrim($v);
            }
        }
        return null;
    }

    /** The value decoded to UTF-8 (RFC 2047 and raw 8-bit). */
    public function decoded(string $name): ?string
    {
        $v = $this->get($name);
        return $v === null ? null : HeaderDecoder::decode($v);
    }

    /** @return list<array{0: string, 1: string}> */
    public function all(): array
    {
        return $this->fields;
    }
}
