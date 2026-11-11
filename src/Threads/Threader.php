<?php

declare(strict_types=1);

namespace Newsstand\Threads;

use Newsstand\Nntp\OverviewEntry;

/**
 * Builds threads from overview entries using References, after jwz's algorithm
 * (https://www.jwz.org/doc/threading.html), minus subject merging. Siblings are ordered by article
 * number (arrival order, more reliable than Date headers); threads by their newest article.
 */
final class Threader
{
    /**
     * @param list<OverviewEntry> $entries
     * @return list<ThreadNode> root nodes, newest thread first
     */
    public static function build(array $entries): array
    {
        /** @var array<string, ThreadNode> $nodes */
        $nodes = [];
        $get = static function (string $id) use (&$nodes): ThreadNode {
            return $nodes[$id] ??= new ThreadNode($id);
        };

        foreach ($entries as $entry) {
            $id = $entry->messageId !== '' ? $entry->messageId : '#' . $entry->number;
            $node = $get($id);
            if ($node->entry !== null) {
                $node = $get($id . '#' . $entry->number); // duplicate Message-ID
            }
            $node->entry = $entry;

            // Link the References chain: each one is the parent of the next, unless already linked.
            $prev = null;
            foreach ($entry->references as $ref) {
                $refNode = $get($ref);
                if ($prev !== null && $refNode->parent === null && $refNode !== $prev && !$refNode->isAncestorOf($prev)) {
                    $prev->adopt($refNode);
                }
                $prev = $refNode;
            }
            // The last reference is this article's parent; the article's own References win.
            if ($prev !== null && $prev !== $node && !$node->isAncestorOf($prev)) {
                $node->parent?->release($node);
                $prev->adopt($node);
            }
        }

        $roots = [];
        foreach ($nodes as $node) {
            if ($node->parent === null) {
                foreach (self::prune($node) as $root) {
                    $roots[] = $root;
                }
            }
        }
        foreach ($roots as $root) {
            $root->sortRecursive();
        }
        usort($roots, static fn (ThreadNode $a, ThreadNode $b): int => $b->newest() <=> $a->newest());
        return $roots;
    }

    /**
     * Removes empty placeholder nodes: an empty node with no children disappears; an empty root with a
     * single child is replaced by it. Empty roots with several children stay, as a "not on this page" head.
     *
     * @return list<ThreadNode>
     */
    private static function prune(ThreadNode $node): array
    {
        $children = [];
        foreach ($node->children as $child) {
            foreach (self::prune($child) as $kept) {
                $children[] = $kept;
            }
        }
        $node->children = [];
        foreach ($children as $child) {
            $child->parent = null;
            $node->adopt($child);
        }

        if ($node->entry !== null) {
            return [$node];
        }
        if ($node->parent === null && count($node->children) > 1) {
            return [$node];
        }
        foreach ($node->children as $child) {
            $child->parent = null;
        }
        return $node->children;
    }
}
