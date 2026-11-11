<?php

declare(strict_types=1);

namespace Newsstand\Groups;

use Newsstand\Nntp\Client;
use Newsstand\Nntp\GroupName;
use Newsstand\Text\Charset;

/**
 * The cached group list, rebuilt by bin/refresh-groups.php (cron, every 15 minutes) from LIST ACTIVE and
 * LIST NEWSGROUPS. Pages never fetch the full list live.
 *
 * Roanoke runs PHP without OPcache, so no page may load the whole list (about 47,000 groups). The cache is
 * split so each request reads only what it needs:
 *
 * - nodes/<prefix>.json  one file per hierarchy level: child component => [groups below, new articles,
 *                        is a group, has children, group record or null]. A browse page reads one file;
 *                        looking up one group reads its parent level's file.
 * - search.tsv           one line per group: "lowercase name \t lowercase description \t name \t high \t
 *                        low \t status \t new \t description". Search scans it line by line.
 * - meta.json            build time, snapshot time, group count, the build directory in use, and the
 *                        precomputed most-active list for the start page.
 * - snapshot.json        high-water marks from up to a day ago, for "recent activity" (read by cron only).
 *
 * Each build goes into a new directory; meta.json is then replaced atomically to switch over, and builds
 * older than the previous one are deleted.
 *
 * A group record is [high, low, status, description, new articles since the daily snapshot].
 */
final class GroupCache
{
    public const HIGH = 0;
    public const LOW = 1;
    public const STATUS = 2;
    public const DESC = 3;
    public const NEW = 4;

    public const N_GROUPS = 0;
    public const N_NEW = 1;
    public const N_IS_GROUP = 2;
    public const N_HAS_CHILDREN = 3;
    public const N_RECORD = 4;

    private const SNAPSHOT_AGE = 86400;
    private const ACTIVE_LIMIT = 50;

    /** @var array<string, mixed>|false|null */
    private array|false|null $meta = null;
    /** @var array<string, array<string, array{0: int, 1: int, 2: bool, 3: bool, 4: array|null}>> */
    private array $nodes = [];

    public function __construct(private readonly string $dir)
    {
    }

    public function exists(): bool
    {
        return $this->meta() !== null;
    }

    /** @return array{built: int, snapshot: int, count: int, build: string, active: list<array{0: string, 1: int}>}|null */
    public function meta(): ?array
    {
        if ($this->meta === null) {
            $this->meta = self::readJson($this->dir . '/meta.json') ?? false;
        }
        return $this->meta === false ? null : $this->meta;
    }

    /** @return array{0: int, 1: int, 2: string, 3: string, 4: int}|null */
    public function group(string $name): ?array
    {
        if (!GroupName::isValid($name)) {
            return null;
        }
        $dot = strrpos($name, '.');
        $parent = $dot === false ? '' : substr($name, 0, $dot);
        $part = $dot === false ? $name : substr($name, $dot + 1);
        return $this->children($parent)[$part][self::N_RECORD] ?? null;
    }

    /** @return array<string, array{0: int, 1: int, 2: bool, 3: bool, 4: array|null}> child component => node data */
    public function children(string $prefix): array
    {
        if (isset($this->nodes[$prefix])) {
            return $this->nodes[$prefix];
        }
        $meta = $this->meta();
        if ($meta === null || ($prefix !== '' && !GroupName::isValid($prefix))) {
            return [];
        }
        $data = self::readJson($this->dir . '/' . $meta['build'] . '/' . self::nodeFile($prefix)) ?? [];
        return $this->nodes[$prefix] = $data;
    }

    public function hasNode(string $prefix): bool
    {
        return $this->children($prefix) !== [];
    }

    /**
     * Search over names and descriptions. Every term must match; names rank above descriptions.
     *
     * @return array{total: int, results: list<array{0: string, 1: array{0: int, 1: int, 2: string, 3: string, 4: int}}>}
     */
    public function search(string $query, int $limit = 100): array
    {
        $terms = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = array_values(array_unique(array_slice($terms, 0, 8)));
        $meta = $this->meta();
        if ($terms === [] || $meta === null) {
            return ['total' => 0, 'results' => []];
        }
        $handle = @fopen($this->dir . '/' . $meta['build'] . '/search.tsv', 'rb');
        if ($handle === false) {
            return ['total' => 0, 'results' => []];
        }
        // Pass 1 keeps only line number => score, so a query matching every group stays small.
        // The file is sorted by name, so the line number also breaks ties alphabetically.
        $whole = implode(' ', $terms);
        $scores = [];
        $lineNo = -1;
        while (($line = fgets($handle)) !== false) {
            $lineNo++;
            // Cheap prefilter on the whole line before splitting it.
            foreach ($terms as $t) {
                if (!str_contains($line, $t)) {
                    continue 2;
                }
            }
            $f = explode("\t", rtrim($line, "\n"), 8);
            if (count($f) < 8) {
                continue;
            }
            [$lname, $ldesc] = $f;
            $score = 0;
            foreach ($terms as $t) {
                if (str_contains($lname, $t)) {
                    $score += 10;
                } elseif (str_contains($ldesc, $t)) {
                    $score += 3;
                } else {
                    continue 2;
                }
            }
            if ($lname === $whole) {
                $score += 100;
            } elseif (str_starts_with($lname, $terms[0])) {
                $score += 20;
            } elseif (str_contains('.' . $lname . '.', '.' . $terms[0] . '.')) {
                $score += 15;
            }
            $scores[$lineNo] = $score * 1000 - min(999, substr_count($lname, '.') * 50);
        }
        $total = count($scores);
        uksort($scores, static fn (int $a, int $b): int => [$scores[$b], $a] <=> [$scores[$a], $b]);
        $top = array_slice(array_keys($scores), 0, $limit);
        unset($scores);

        // Pass 2 reads the records of the best matches only.
        $wanted = array_flip($top);
        $records = [];
        rewind($handle);
        $lineNo = -1;
        while ($wanted !== [] && ($line = fgets($handle)) !== false) {
            $lineNo++;
            if (isset($wanted[$lineNo])) {
                $f = explode("\t", rtrim($line, "\n"), 8);
                $records[$lineNo] = [$f[2], [(int) $f[3], (int) $f[4], $f[5], $f[7], (int) $f[6]]];
                unset($wanted[$lineNo]);
            }
        }
        fclose($handle);
        $results = [];
        foreach ($top as $n) {
            if (isset($records[$n])) {
                $results[] = $records[$n];
            }
        }
        return ['total' => $total, 'results' => $results];
    }

    /** @return list<array{0: string, 1: int}> [group name, new articles], most active first */
    public function mostActive(int $limit = 20): array
    {
        return array_slice($this->meta()['active'] ?? [], 0, $limit);
    }

    /** Rebuilds the cache from the server. Returns the number of groups. */
    public function refresh(Client $client, ?int $now = null): int
    {
        $now ??= time();
        $groups = [];
        foreach ($client->listActive() as $g) {
            if (!GroupName::isValid($g['name'])) {
                continue;
            }
            $groups[$g['name']] = [$g['high'], $g['low'], $g['status'], '', 0];
        }
        foreach ($client->listNewsgroups() as $name => $desc) {
            if (isset($groups[$name])) {
                $desc = mb_substr(trim(Charset::toUtf8($desc)), 0, 300);
                $groups[$name][self::DESC] = str_replace(["\t", "\n", "\r"], ' ', $desc);
            }
        }
        return $this->store($groups, $now);
    }

    /** @param array<string, array{0: int, 1: int, 2: string, 3: string, 4: int}> $groups */
    public function store(array $groups, int $now): int
    {
        self::ensureDir($this->dir);
        ksort($groups, SORT_STRING);

        // Recent activity: growth of the high-water mark since a snapshot taken up to a day ago.
        $snapshotFile = $this->dir . '/snapshot.json';
        $snapshot = self::readJson($snapshotFile);
        $highs = $snapshot['high'] ?? [];
        $snapshotTime = (int) ($snapshot['time'] ?? $now);
        foreach ($groups as $name => &$g) {
            if (isset($highs[$name])) {
                $g[self::NEW] = max(0, $g[self::HIGH] - (int) $highs[$name]);
            }
        }
        unset($g);
        if ($snapshot === null || $now - $snapshotTime >= self::SNAPSHOT_AGE) {
            self::writeAtomic($snapshotFile, self::json(['time' => $now, 'high' => array_map(static fn ($g) => $g[self::HIGH], $groups)]));
            $snapshotTime = $snapshot === null ? $now : $snapshotTime;
        }

        // Write the new build into its own directory.
        $build = 'build-' . gmdate('YmdHis', $now) . '-' . bin2hex(random_bytes(3));
        $buildDir = $this->dir . '/' . $build;
        self::ensureDir($buildDir . '/nodes');
        foreach (self::buildTree($groups) as $prefix => $children) {
            self::writeFile($buildDir . '/' . self::nodeFile((string) $prefix), self::json($children));
        }
        $search = fopen($buildDir . '/search.tsv', 'wb');
        if ($search === false) {
            throw new \RuntimeException("Cannot write $buildDir/search.tsv");
        }
        $active = [];
        foreach ($groups as $name => $g) {
            $name = (string) $name;
            fwrite($search, implode("\t", [strtolower($name), mb_strtolower($g[self::DESC]), $name, $g[self::HIGH], $g[self::LOW], $g[self::STATUS], $g[self::NEW], $g[self::DESC]]) . "\n");
            if ($g[self::NEW] > 0) {
                $active[$name] = $g[self::NEW];
            }
        }
        fclose($search);
        arsort($active);
        $active = array_slice($active, 0, self::ACTIVE_LIMIT, true);

        // Switch over, then remove builds older than the previous one (a request may still be reading it).
        $previous = $this->meta()['build'] ?? null;
        self::writeAtomic($this->dir . '/meta.json', self::json([
            'built' => $now,
            'snapshot' => $snapshotTime,
            'count' => count($groups),
            'build' => $build,
            'active' => array_map(null, array_map('strval', array_keys($active)), array_values($active)),
        ]));
        foreach (glob($this->dir . '/build-*', GLOB_ONLYDIR) ?: [] as $old) {
            $base = basename($old);
            if ($base !== $build && $base !== $previous) {
                self::removeDir($old);
            }
        }
        $this->meta = null;
        $this->nodes = [];
        return count($groups);
    }

    /**
     * @param array<string, array{0: int, 1: int, 2: string, 3: string, 4: int}> $groups
     * @return array<string, array<string, array{0: int, 1: int, 2: bool, 3: bool, 4: array|null}>>
     */
    public static function buildTree(array $groups): array
    {
        $tree = ['' => []];
        foreach ($groups as $name => $g) {
            $parts = explode('.', (string) $name);
            $prefix = '';
            $last = count($parts) - 1;
            foreach ($parts as $i => $part) {
                $node = &$tree[$prefix][$part];
                $node ??= [0, 0, false, false, null];
                $node[self::N_GROUPS]++;
                $node[self::N_NEW] += $g[self::NEW];
                if ($i === $last) {
                    $node[self::N_IS_GROUP] = true;
                    $node[self::N_RECORD] = $g;
                } else {
                    $node[self::N_HAS_CHILDREN] = true;
                }
                unset($node);
                $prefix = $prefix === '' ? $part : $prefix . '.' . $part;
            }
        }
        foreach ($tree as $prefix => $children) {
            ksort($tree[$prefix], SORT_STRING);
        }
        return $tree;
    }

    /**
     * File name for a hierarchy level. Group names may differ only in case, and development happens on a
     * case-insensitive file system, so upper-case letters are escaped. "@" never occurs in group names.
     */
    public static function nodeFile(string $prefix): string
    {
        if ($prefix === '') {
            return 'nodes/@top.json';
        }
        return 'nodes/' . preg_replace('/[A-Z]/', '^$0', $prefix) . '.json';
    }

    private static function readJson(string $file): ?array
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private static function json(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function writeFile(string $file, string $data): void
    {
        if (file_put_contents($file, $data) === false) {
            throw new \RuntimeException("Cannot write $file");
        }
    }

    private static function writeAtomic(string $file, string $data): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $data, LOCK_EX) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write $file");
        }
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory $dir");
        }
    }

    private static function removeDir(string $dir): void
    {
        foreach (glob($dir . '/nodes/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir . '/nodes');
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
