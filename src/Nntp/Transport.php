<?php

declare(strict_types=1);

namespace Newsstand\Nntp;

/** A line-oriented connection. Lines are exchanged without their CRLF terminator. */
interface Transport
{
    public function writeLine(#[\SensitiveParameter] string $line): void;

    /** @throws NntpException on timeout or end of stream */
    public function readLine(): string;

    public function close(): void;
}
