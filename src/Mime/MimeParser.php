<?php

declare(strict_types=1);

namespace Newsstand\Mime;

/** Builds a Part tree from a raw article. Depth and part counts are capped against hostile input. */
final class MimeParser
{
    private const MAX_DEPTH = 8;
    private const MAX_PARTS = 64;

    private int $parts = 0;

    public static function parse(string $raw): Part
    {
        return (new self())->entity(str_replace("\r\n", "\n", $raw), 0, 'text/plain');
    }

    private function entity(string $raw, int $depth, string $defaultType): Part
    {
        $this->parts++;
        if (str_starts_with($raw, "\n")) {
            $headerBlock = '';
            $body = substr($raw, 1);
        } else {
            $split = strpos($raw, "\n\n");
            $headerBlock = $split === false ? $raw : substr($raw, 0, $split);
            $body = $split === false ? '' : substr($raw, $split + 2);
        }
        $part = new Part(Headers::parse($headerBlock), $body, [], $defaultType);

        $boundary = $part->params['boundary'] ?? '';
        if ($part->isMultipart() && $boundary !== '' && $depth < self::MAX_DEPTH) {
            $childDefault = $part->type === 'multipart/digest' ? 'message/rfc822' : 'text/plain';
            $children = [];
            foreach ($this->splitMultipart($body, $boundary) as $chunk) {
                if ($this->parts >= self::MAX_PARTS) {
                    break;
                }
                $children[] = $this->entity($chunk, $depth + 1, $childDefault);
            }
            if ($children !== []) {
                $part->children = $children;
            }
        }
        return $part;
    }

    /** @return list<string> */
    private function splitMultipart(string $body, string $boundary): array
    {
        $delimiter = '--' . $boundary;
        $chunks = [];
        $current = null;
        foreach (explode("\n", $body) as $line) {
            $trimmed = rtrim($line, " \t");
            if ($trimmed === $delimiter . '--') {
                if ($current !== null) {
                    $chunks[] = implode("\n", $current);
                }
                return $chunks;
            }
            if ($trimmed === $delimiter) {
                if ($current !== null) {
                    $chunks[] = implode("\n", $current);
                }
                $current = [];
                continue;
            }
            if ($current !== null) {
                $current[] = $line;
            }
        }
        if ($current !== null) {
            $chunks[] = implode("\n", $current); // missing closing delimiter
        }
        return $chunks;
    }
}
