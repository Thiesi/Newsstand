<?php

declare(strict_types=1);

namespace Newsstand\Nntp;

use Newsstand\Text\HeaderDecoder;

/** One line of OVER output, with Subject and From already decoded to UTF-8. */
final class OverviewEntry
{
    /** @param list<string> $references */
    public function __construct(
        public readonly int $number,
        public readonly string $subject,
        public readonly string $from,
        public readonly string $date,
        public readonly string $messageId,
        public readonly array $references,
        public readonly int $bytes,
        public readonly int $lines,
    ) {
    }

    public static function fromLine(string $line): ?self
    {
        $f = explode("\t", $line);
        if (count($f) < 8 || !ctype_digit($f[0])) {
            return null;
        }
        preg_match_all('/<[^<>\s]+>/', $f[5], $refs);
        return new self(
            (int) $f[0],
            HeaderDecoder::decode($f[1]),
            HeaderDecoder::decode($f[2]),
            trim($f[3]),
            trim($f[4]),
            $refs[0],
            (int) $f[6],
            (int) $f[7],
        );
    }

    /** @return list<int|string|list<string>> for ResponseCache (scalars only) */
    public function toArray(): array
    {
        return [$this->number, $this->subject, $this->from, $this->date, $this->messageId, $this->references, $this->bytes, $this->lines];
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self((int) $a[0], (string) $a[1], (string) $a[2], (string) $a[3], (string) $a[4], array_map('strval', (array) $a[5]), (int) $a[6], (int) $a[7]);
    }

    public function timestamp(): ?int
    {
        $t = strtotime(preg_replace('/\s*\([^)]*\)\s*$/', '', $this->date) ?? $this->date);
        return $t === false ? null : $t;
    }
}
