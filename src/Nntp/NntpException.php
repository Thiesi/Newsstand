<?php

declare(strict_types=1);

namespace Newsstand\Nntp;

final class NntpException extends \RuntimeException
{
    /** @param int $status the NNTP response code, or 0 for transport and protocol errors */
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }

    public function isNoSuchGroup(): bool
    {
        return $this->status === 411;
    }

    public function isNoSuchArticle(): bool
    {
        return in_array($this->status, [420, 421, 422, 423, 430], true);
    }
}
