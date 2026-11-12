<?php

declare(strict_types=1);

namespace Newsstand\Auth;

/** A logged-in user's session. The password is only available while the cookie's key is at hand. */
final class Session
{
    public function __construct(
        public readonly string $user,
        public readonly string $csrf,
        public string $name,
        public readonly int $created,
        public int $seen,
        private readonly string $password,
    ) {
    }

    /** The mailbox password, for AUTHINFO when posting. Never log or render it. */
    public function password(): string
    {
        return $this->password;
    }

    public function checkCsrf(string $token): bool
    {
        return $token !== '' && hash_equals($this->csrf, $token);
    }

    public function __debugInfo(): array
    {
        return ['user' => $this->user, 'name' => $this->name, 'created' => $this->created, 'seen' => $this->seen];
    }
}
