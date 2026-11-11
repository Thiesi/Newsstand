<?php

declare(strict_types=1);

namespace Newsstand\Web;

use Newsstand\Groups\GroupCache as G;

/**
 * Keeps big hierarchy levels usable (the top level has over 500 hierarchies, alt.* over 3,000 entries).
 *
 * Small levels show everything. Big levels show the largest entries first, plus an A–Z index; one
 * letter shows its entries alphabetically, paged if it is still long. Everything works without JS.
 */
final class LevelPager
{
    public const SMALL = 150;
    public const LARGEST = 40;
    public const PAGE = 200;

    /** @var array<string, array> entries to show, component => node */
    public array $entries = [];
    /** @var array<string, int> index key => entry count, for big levels */
    public array $letters = [];
    public ?string $letter = null;
    public int $page = 1;
    public int $pages = 1;
    public bool $big = false;

    /** @param array<string, array> $children */
    public function __construct(public readonly array $children, mixed $letter = null, mixed $page = null)
    {
        $this->big = count($children) > self::SMALL;
        if (!$this->big) {
            $this->entries = $children;
            return;
        }
        foreach (array_keys($children) as $part) {
            $key = self::key((string) $part);
            $this->letters[$key] = ($this->letters[$key] ?? 0) + 1;
        }
        ksort($this->letters, SORT_STRING);

        if (is_string($letter) && isset($this->letters[$letter])) {
            $this->letter = $letter;
            $inLetter = array_filter($children, static fn ($part) => self::key((string) $part) === $letter, ARRAY_FILTER_USE_KEY);
            $this->pages = max(1, (int) ceil(count($inLetter) / self::PAGE));
            $this->page = (is_string($page) && ctype_digit($page)) ? max(1, min($this->pages, (int) $page)) : 1;
            $this->entries = array_slice($inLetter, ($this->page - 1) * self::PAGE, self::PAGE, true);
            return;
        }
        $this->entries = self::largest($children, self::LARGEST);
    }

    /** The biggest entries by number of groups below them, keeping their names as keys. */
    public static function largest(array $children, int $limit): array
    {
        uksort($children, static fn ($a, $b) => [$children[$b][G::N_GROUPS], (string) $a] <=> [$children[$a][G::N_GROUPS], (string) $b]);
        return array_slice($children, 0, $limit, true);
    }

    /** Index key: the lower-case first letter, or "0-9" and "other". */
    public static function key(string $part): string
    {
        $c = strtolower($part[0] ?? '');
        return match (true) {
            $c >= 'a' && $c <= 'z' => $c,
            $c >= '0' && $c <= '9' => '0-9',
            default => 'other',
        };
    }
}
