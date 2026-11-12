<?php

declare(strict_types=1);

namespace Newsstand\Post;

/**
 * One line per post attempt that reached INN: time, user, visitor IP, Message-ID, groups, result.
 * INN only sees 127.0.0.1, so this file is the trace for abuse complaints. Tab-separated, UTC.
 */
final class PostLog
{
    public function __construct(private readonly string $file)
    {
    }

    /** @param list<string> $groups */
    public function record(string $user, string $ip, string $messageId, array $groups, string $result, ?int $now = null): void
    {
        $clean = static fn (string $s): string => preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s) ?? '';
        $line = implode("\t", [
            gmdate('Y-m-d\TH:i:s\Z', $now ?? time()),
            $clean($user),
            $clean($ip),
            $clean($messageId),
            $clean(implode(',', $groups)),
            $clean(mb_substr($result, 0, 200)),
        ]) . "\n";
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) === false) {
            // Posting without a trace is not acceptable; the caller turns this into an error page.
            throw new \RuntimeException('Cannot write the post log');
        }
    }
}
