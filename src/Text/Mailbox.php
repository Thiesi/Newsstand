<?php

declare(strict_types=1);

namespace Newsstand\Text;

/** Splits a decoded From/Reply-To/Sender value into display name and address. */
final class Mailbox
{
    public function __construct(public readonly string $name, public readonly string $address)
    {
    }

    public static function parse(string $value): self
    {
        $value = trim($value);
        // "Name" <addr>  or  Name <addr>
        if (preg_match('/^(.*?)\s*<([^<>]*)>\s*$/s', $value, $m)) {
            return new self(self::cleanName($m[1]), trim($m[2]));
        }
        // addr (Name)
        if (preg_match('/^(\S+@\S+)\s*\((.*)\)\s*$/s', $value, $m)) {
            return new self(self::cleanName($m[2]), $m[1]);
        }
        return new self('', $value);
    }

    /** What to show in lists: the name, or the address when there is no name. Obfuscate before display. */
    public function label(): string
    {
        return $this->name !== '' ? $this->name : $this->address;
    }

    private static function cleanName(string $name): string
    {
        $name = trim($name);
        if (strlen($name) >= 2 && $name[0] === '"' && str_ends_with($name, '"')) {
            $name = preg_replace('/\\\\(.)/s', '$1', substr($name, 1, -1)) ?? $name;
        }
        return trim($name);
    }
}
