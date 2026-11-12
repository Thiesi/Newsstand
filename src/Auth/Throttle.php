<?php

declare(strict_types=1);

namespace Newsstand\Auth;

/**
 * Sliding-window event counters in small files, shared by all PHP-FPM workers via flock.
 * Used for failed logins (per visitor IP and per account) and post limits (per user).
 */
final class Throttle
{
    /** Events older than this are dropped from every counter file. */
    private const KEEP = 86400;

    public function __construct(private readonly string $dir)
    {
    }

    /** How many events were recorded for $key in the last $window seconds. */
    public function count(string $key, int $window, ?int $now = null): int
    {
        $now ??= time();
        $times = $this->withFile($key, false, static fn (array $t): array => $t);
        return count(array_filter($times, static fn (int $t): bool => $t > $now - $window));
    }

    /** Seconds until the count for $key in $window drops below $limit (0 if it already is). */
    public function retryAfter(string $key, int $window, int $limit, ?int $now = null): int
    {
        $now ??= time();
        $times = array_values(array_filter(
            $this->withFile($key, false, static fn (array $t): array => $t),
            static fn (int $t): bool => $t > $now - $window,
        ));
        if (count($times) < $limit) {
            return 0;
        }
        sort($times);
        return max(1, $times[count($times) - $limit] + $window - $now);
    }

    public function add(string $key, ?int $now = null): void
    {
        $now ??= time();
        $this->withFile($key, true, static function (array $t) use ($now): array {
            $t = array_values(array_filter($t, static fn (int $x): bool => $x > $now - self::KEEP));
            $t[] = $now;
            return array_slice($t, -1000);
        });
    }

    public function clear(string $key): void
    {
        @unlink($this->file($key));
    }

    /** Deletes counter files with no recent events (run from cron). */
    public function prune(?int $now = null): int
    {
        $now ??= time();
        $removed = 0;
        foreach (glob($this->dir . '/*.txt') ?: [] as $file) {
            if (@filemtime($file) < $now - self::KEEP && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    /** Throttle key for a visitor IP: the address itself for IPv4, the /64 for IPv6. */
    public static function ipKey(string $ip): string
    {
        $bin = @inet_pton($ip);
        if (is_string($bin) && strlen($bin) === 16) {
            return 'ip6:' . bin2hex(substr($bin, 0, 8));
        }
        return 'ip:' . $ip;
    }

    /**
     * @param \Closure(list<int>): list<int> $fn
     * @return list<int>
     */
    private function withFile(string $key, bool $write, \Closure $fn): array
    {
        if (!is_dir($this->dir)) {
            if (!$write) {
                return [];
            }
            @mkdir($this->dir, 0700, true);
        }
        $file = $this->file($key);
        if (!$write && !is_file($file)) {
            return [];
        }
        $h = @fopen($file, $write ? 'c+' : 'r');
        if ($h === false) {
            // Fail closed for writes: an unwritable counter must not lift a limit silently.
            if ($write) {
                throw new \RuntimeException('Cannot write a throttle counter');
            }
            return [];
        }
        try {
            flock($h, $write ? LOCK_EX : LOCK_SH);
            $raw = stream_get_contents($h);
            $times = array_map('intval', array_filter(explode("\n", (string) $raw), 'ctype_digit'));
            $new = $fn(array_values($times));
            if ($write) {
                ftruncate($h, 0);
                rewind($h);
                fwrite($h, implode("\n", $new) . "\n");
                fflush($h);
            }
            return $new;
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    private function file(string $key): string
    {
        return $this->dir . '/' . hash('sha256', strtolower($key)) . '.txt';
    }
}
