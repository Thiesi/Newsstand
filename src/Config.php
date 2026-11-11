<?php

declare(strict_types=1);

namespace Newsstand;

/**
 * Host-specific settings. The file lives outside the repository on the server; its path comes from the
 * NEWSSTAND_CONFIG environment variable. For development, config.local.php in the project root (ignored
 * by git) is used when the variable is not set. See config.example.php.
 */
final class Config
{
    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function load(?string $path = null): self
    {
        $root = dirname(__DIR__);
        $path ??= getenv('NEWSSTAND_CONFIG') ?: $root . '/config.local.php';
        if (!is_file($path)) {
            throw new \RuntimeException("Configuration file not found: $path (copy config.example.php)");
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new \RuntimeException("Configuration file must return an array: $path");
        }
        return self::fromArray($values);
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $root = dirname(__DIR__);
        $defaults = [
            'nntp_host' => '127.0.0.1',
            'nntp_port' => 119,
            'nntp_timeout' => 15,
            'var_dir' => $root . '/var',
            'site_url' => 'https://News.NetWorkXXIII.de',
            'main_site_url' => 'https://NetWorkXXIII.de',
            'articles_per_page' => 100,
            'max_article_bytes' => 1048576,
            // New NNTP connections: at most burst + per_minute can start in any 60 seconds. Keep that sum
            // well below the loopback listener's inetd spawn limit (50/min on 2026-11-11).
            'nntp_budget_per_minute' => 25,
            'nntp_budget_burst' => 10,
            // Seconds a cached thread list / article counts as fresh, and the age after which cron deletes it.
            'cache_fresh_overview' => 60,
            'cache_fresh_article' => 3600,
            'cache_max_age' => 86400,
        ];
        return new self($values + $defaults);
    }

    public function string(string $key): string
    {
        return (string) $this->values[$key];
    }

    public function int(string $key): int
    {
        return (int) $this->values[$key];
    }
}
