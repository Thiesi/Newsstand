<?php

declare(strict_types=1);

namespace Newsstand\Threads;

use Newsstand\Nntp\OverviewEntry;

final class ThreadNode
{
    public ?OverviewEntry $entry = null;
    public ?ThreadNode $parent = null;
    /** @var list<ThreadNode> */
    public array $children = [];

    public function __construct(public readonly string $id)
    {
    }

    public function adopt(ThreadNode $child): void
    {
        $child->parent = $this;
        $this->children[] = $child;
    }

    public function release(ThreadNode $child): void
    {
        $this->children = array_values(array_filter($this->children, static fn ($c) => $c !== $child));
        $child->parent = null;
    }

    public function isAncestorOf(ThreadNode $other): bool
    {
        for ($n = $other->parent, $guard = 0; $n !== null && $guard < 10000; $n = $n->parent, $guard++) {
            if ($n === $this) {
                return true;
            }
        }
        return false;
    }

    /** Highest article number in this subtree. */
    public function newest(): int
    {
        $max = $this->entry?->number ?? 0;
        foreach ($this->children as $child) {
            $max = max($max, $child->newest());
        }
        return $max;
    }

    public function oldest(): int
    {
        $min = $this->entry?->number ?? PHP_INT_MAX;
        foreach ($this->children as $child) {
            $min = min($min, $child->oldest());
        }
        return $min;
    }

    public function count(): int
    {
        $n = $this->entry !== null ? 1 : 0;
        foreach ($this->children as $child) {
            $n += $child->count();
        }
        return $n;
    }

    public function sortRecursive(): void
    {
        usort($this->children, static fn (ThreadNode $a, ThreadNode $b): int => $a->oldest() <=> $b->oldest());
        foreach ($this->children as $child) {
            $child->sortRecursive();
        }
    }

    /** The first real entry in this subtree (for placeholder roots). */
    public function firstEntry(): ?OverviewEntry
    {
        if ($this->entry !== null) {
            return $this->entry;
        }
        foreach ($this->children as $child) {
            if (($e = $child->firstEntry()) !== null) {
                return $e;
            }
        }
        return null;
    }
}
