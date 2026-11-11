<?php

declare(strict_types=1);

namespace Newsstand\Nntp;

/** Thrown instead of connecting when the shared connection budget is empty. */
final class BudgetExhausted extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('NNTP connection budget exhausted');
    }
}
