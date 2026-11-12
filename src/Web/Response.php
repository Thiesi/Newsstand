<?php

declare(strict_types=1);

namespace Newsstand\Web;

final class Response
{
    /** @var array<string, array{value: string, maxAge: int|null}> cookies to set (value '' with maxAge 0 deletes) */
    public array $cookies = [];

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public array $headers = [],
    ) {
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    /** Session-style cookie (no Expires) unless $maxAge is given. Always HttpOnly, SameSite=Lax, Path=/. */
    public function setCookie(string $name, string $value, ?int $maxAge = null): self
    {
        $this->cookies[$name] = ['value' => $value, 'maxAge' => $maxAge];
        return $this;
    }

    public function deleteCookie(string $name): self
    {
        return $this->setCookie($name, '', 0);
    }

    public function send(bool $secureCookies = true): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        foreach ($this->cookies as $name => $c) {
            $options = ['path' => '/', 'secure' => $secureCookies, 'httponly' => true, 'samesite' => 'Lax'];
            if ($c['maxAge'] !== null) {
                $options['expires'] = $c['maxAge'] === 0 ? 1 : time() + $c['maxAge'];
            }
            setcookie($name, $c['value'], $options);
        }
        echo $this->body;
    }
}
