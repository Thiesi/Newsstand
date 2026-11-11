<?php

declare(strict_types=1);

namespace Newsstand\Nntp;

/**
 * A token bucket for new NNTP connections, shared by all PHP processes through a locked file in var/.
 *
 * Why: every web visitor reaches INN from 127.0.0.1, and the loopback listener runs from inetd with a
 * spawn-rate limit (50 per 60 seconds on 2026-11-11). Going over it does not slow things down; inetd
 * closes the listener for ten minutes, for everyone. This budget keeps Newsstand below that ceiling:
 * when it is empty, pages serve a cached copy or a 503 with Retry-After instead of connecting.
 *
 * In any 60-second window at most  burst + perMinute  connections can start, so keep that sum well below
 * the server's limit.
 */
final class ConnectionBudget
{
    public function __construct(
        private readonly string $file,
        private readonly int $perMinute,
        private readonly int $burst,
    ) {
    }

    /** Takes one token if available. Never blocks. */
    public function take(?float $now = null): bool
    {
        return $this->withState(function (float $tokens) {
            return $tokens >= 1 ? [$tokens - 1, true] : [$tokens, false];
        }, $now);
    }

    /** Seconds until the next token is available (for Retry-After). */
    public function secondsUntilToken(?float $now = null): int
    {
        return $this->withState(function (float $tokens) {
            $missing = max(0.0, 1 - $tokens);
            return [$tokens, (int) ceil($missing * 60 / max(1, $this->perMinute))];
        }, $now);
    }

    /**
     * @template T
     * @param \Closure(float): array{0: float, 1: T} $fn
     * @return T
     */
    private function withState(\Closure $fn, ?float $now): mixed
    {
        $now ??= microtime(true);
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $handle = @fopen($this->file, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            // Fail closed: without the shared state we cannot know the rate.
            throw new NntpException('Connection budget unavailable');
        }
        try {
            $raw = stream_get_contents($handle);
            [$tokens, $last] = array_map('floatval', explode(' ', trim((string) $raw)) + [1 => 0]);
            if ($raw === '' || $raw === false) {
                [$tokens, $last] = [(float) $this->burst, $now];
            }
            $elapsed = max(0.0, $now - $last);
            $tokens = min((float) $this->burst, $tokens + $elapsed * $this->perMinute / 60);
            [$tokens, $result] = $fn($tokens);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, sprintf('%.4f %.4f', $tokens, $now));
            fflush($handle);
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
