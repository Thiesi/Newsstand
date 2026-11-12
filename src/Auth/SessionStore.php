<?php

declare(strict_types=1);

namespace Newsstand\Auth;

/**
 * File-based sessions whose stored password is useless without the visitor's cookie.
 *
 * The cookie holds two random values, "<id>.<key>". The server keeps one file per session, named by a
 * hash of the id, with the password encrypted under AES-256-GCM with the key. The key is never written
 * on the server, so a copied session directory reveals the user names but no passwords.
 *
 * Sessions end after `idle` seconds without a request or `lifetime` seconds after login.
 */
final class SessionStore
{
    private const CIPHER = 'aes-256-gcm';

    public function __construct(
        private readonly string $dir,
        private readonly int $idle = 7200,
        private readonly int $lifetime = 86400,
    ) {
    }

    /** @return array{0: string, 1: Session} the cookie value and the new session */
    public function create(string $user, #[\SensitiveParameter] string $password, string $name = '', ?int $now = null): array
    {
        $now ??= time();
        $id = random_bytes(18);
        $key = random_bytes(32);
        $session = new Session($user, self::b64(random_bytes(24)), $name, $now, $now, $password);
        $this->write($id, $key, $session);
        return [self::b64($id) . '.' . self::b64($key), $session];
    }

    /** The session for a cookie value, or null when it is unknown, expired or tampered with. */
    public function load(#[\SensitiveParameter] ?string $cookie, ?int $now = null): ?Session
    {
        $now ??= time();
        $parsed = self::parseCookie($cookie);
        if ($parsed === null) {
            return null;
        }
        [$id, $key] = $parsed;
        $file = $this->file($id);
        $json = @file_get_contents($file);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || !isset($data['user'], $data['csrf'], $data['created'], $data['seen'], $data['iv'], $data['tag'], $data['ct'])) {
            return null;
        }
        if ($now - (int) $data['seen'] > $this->idle || $now - (int) $data['created'] > $this->lifetime) {
            @unlink($file);
            return null;
        }
        $password = openssl_decrypt(
            (string) base64_decode((string) $data['ct'], true),
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            (string) base64_decode((string) $data['iv'], true),
            (string) base64_decode((string) $data['tag'], true),
            basename($file),
        );
        if (!is_string($password)) {
            return null; // wrong key: someone else's id with a made-up key
        }
        $session = new Session((string) $data['user'], (string) $data['csrf'], (string) ($data['name'] ?? ''), (int) $data['created'], (int) $data['seen'], $password);
        if ($now - $session->seen >= 60) {
            // Refresh the idle timer, at most once a minute to keep writes down. Only while the file still
            // exists: a logout in a parallel request must not be undone by this write.
            $session->seen = $now;
            clearstatcache(true, $file);
            if (is_file($file)) {
                $this->write($id, $key, $session);
            }
        }
        return $session;
    }

    /** Saves changed fields (the display name). */
    public function save(#[\SensitiveParameter] string $cookie, Session $session): void
    {
        $parsed = self::parseCookie($cookie);
        if ($parsed !== null && is_file($this->file($parsed[0]))) {
            $this->write($parsed[0], $parsed[1], $session);
        }
    }

    public function destroy(#[\SensitiveParameter] ?string $cookie): void
    {
        $parsed = self::parseCookie($cookie);
        if ($parsed !== null) {
            @unlink($this->file($parsed[0]));
        }
    }

    /** Deletes expired session files (run from cron). Returns how many were removed. */
    public function prune(?int $now = null): int
    {
        $now ??= time();
        $removed = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            $expired = !is_array($data)
                || $now - (int) ($data['seen'] ?? 0) > $this->idle
                || $now - (int) ($data['created'] ?? 0) > $this->lifetime;
            if ($expired && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    private function write(#[\SensitiveParameter] string $id, #[\SensitiveParameter] string $key, Session $session): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Cannot create the session directory');
        }
        $file = $this->file($id);
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($session->password(), self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, basename($file));
        if (!is_string($ct)) {
            throw new \RuntimeException('Encryption failed');
        }
        $json = json_encode([
            'user' => $session->user,
            'name' => $session->name,
            'csrf' => $session->csrf,
            'created' => $session->created,
            'seen' => $session->seen,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ct' => base64_encode($ct),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write the session file');
        }
        @chmod($file, 0600);
    }

    private function file(string $id): string
    {
        return $this->dir . '/' . hash('sha256', $id) . '.json';
    }

    /** @return array{0: string, 1: string}|null raw id and key */
    private static function parseCookie(#[\SensitiveParameter] ?string $cookie): ?array
    {
        if ($cookie === null || !preg_match('/^([A-Za-z0-9_-]{24})\.([A-Za-z0-9_-]{43})$/D', $cookie, $m)) {
            return null;
        }
        $id = self::unb64($m[1]);
        $key = self::unb64($m[2]);
        return (strlen($id) === 18 && strlen($key) === 32) ? [$id, $key] : null;
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'), true);
    }
}
