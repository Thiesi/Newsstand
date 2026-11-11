<?php

declare(strict_types=1);

namespace Newsstand\Nntp;

final class GroupName
{
    /** Conservative newsgroup-name syntax: dot-separated components of letters, digits, + - _. */
    public static function isValid(string $name): bool
    {
        return strlen($name) <= 200
            && preg_match('/^[A-Za-z0-9+_-]+(?:\.[A-Za-z0-9+_-]+)*$/D', $name) === 1;
    }
}
