<?php

declare(strict_types=1);

namespace Newsstand\Nntp;

/**
 * A small file cache for NNTP results (thread-list overview data, articles), so repeat views and crawlers
 * do not each need a new connection. Values are arrays of scalars, stored with serialize() and read back
 * with allowed_classes => false, so a cache file can never create objects.
 *
 * Entries are kept past their freshness so they can be served stale when the connection budget is empty;
 * prune() (run from cron) deletes them after a hard maximum age.
 */
final class ResponseCache
{
    public function __construct(private readonly string $dir)
    {
    }

    /**
     * @return array{0: array<mixed>, 1: int}|null the value and its age in seconds
     */
    public function get(string $key, ?int $now = null): ?array
    {
        $raw = @file_get_contents($this->path($key));
        if ($raw === false) {
            return null;
        }
        $entry = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($entry) || !isset($entry['t'], $entry['k'], $entry['v']) || $entry['k'] !== $key || !is_array($entry['v'])) {
            return null;
        }
        return [$entry['v'], max(0, ($now ?? time()) - (int) $entry['t'])];
    }

    /** @param array<mixed> $value */
    public function put(string $key, array $value, ?int $now = null): void
    {
        $file = $this->path($key);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return; // caching is best effort
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, serialize(['t' => $now ?? time(), 'k' => $key, 'v' => $value])) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    /** Deletes entries older than $maxAge seconds. Returns the number deleted. */
    public function prune(int $maxAge, ?int $now = null): int
    {
        $limit = ($now ?? time()) - $maxAge;
        $deleted = 0;
        foreach (glob($this->dir . '/*/*') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $limit && @unlink($file)) {
                $deleted++;
            }
        }
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            @rmdir($sub); // only succeeds when empty
        }
        return $deleted;
    }

    private function path(string $key): string
    {
        $hash = sha1($key);
        return $this->dir . '/' . substr($hash, 0, 2) . '/' . $hash;
    }
}
