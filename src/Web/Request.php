<?php

declare(strict_types=1);

namespace Newsstand\Web;

/** The parts of an HTTP request the app uses. Built from PHP's globals in public/index.php, directly in tests. */
final class Request
{
    /**
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $post
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $cookies = [],
        public readonly array $post = [],
        public readonly string $ip = '',
        public readonly string $host = '',
        public readonly ?string $origin = null,
    ) {
    }

    public static function fromGlobals(): self
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            $_COOKIE,
            $_POST,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_HOST'] ?? ''),
            is_string($origin) ? $origin : null,
        );
    }

    public function cookie(string $name): ?string
    {
        $v = $this->cookies[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    /** A single-line or multi-line form field as a string ('' when missing or not a string). */
    public function field(string $name): string
    {
        $v = $this->post[$name] ?? null;
        return is_string($v) ? $v : '';
    }

    /**
     * Cross-site check for state-changing requests: when the browser sends an Origin header, its host
     * must be ours. (Browsers send Origin on every cross-site POST; a missing Origin is accepted because
     * the CSRF token is checked as well.)
     */
    public function sameOrigin(): bool
    {
        if ($this->origin === null) {
            return true;
        }
        $parts = parse_url($this->origin);
        if (!is_array($parts) || !isset($parts['host'])) {
            return false;
        }
        $origin = strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        $host = strtolower($this->host);
        // Default ports are left out of Origin but may be present in Host.
        $host = preg_replace('/:(80|443)$/', '', $host) ?? $host;
        return $origin === $host;
    }
}
