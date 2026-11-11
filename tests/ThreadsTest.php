<?php

declare(strict_types=1);

use Newsstand\Nntp\OverviewEntry;
use Newsstand\Threads\Threader;
use Newsstand\Threads\ThreadNode;

function ov(int $n, string $id, string $refs = ''): OverviewEntry
{
    return OverviewEntry::fromLine("$n\tSubject $n\tA <a@example.com>\tWed, 11 Nov 2026 20:00:00 +0000\t$id\t$refs\t100\t5");
}

/** @return list<string> "id(child,child)" */
function shape(array $roots): array
{
    $f = function (ThreadNode $n) use (&$f): string {
        $label = $n->entry?->number ?? '?';
        return $n->children === [] ? (string) $label : $label . '(' . implode(',', array_map($f, $n->children)) . ')';
    };
    return array_map($f, $roots);
}

test('threads follow References and order newest thread first', function () {
    $roots = Threader::build([
        ov(1, '<1@x>'),
        ov(2, '<2@x>', '<1@x>'),
        ov(3, '<3@x>'),
        ov(4, '<4@x>', '<1@x> <2@x>'),
        ov(5, '<5@x>', '<1@x>'),
    ]);
    eq(['1(2(4),5)', '3'], shape($roots));
});

test('missing parents: single child is promoted, several keep a placeholder', function () {
    $roots = Threader::build([
        ov(10, '<10@x>', '<gone@x>'),
        ov(11, '<11@x>', '<lost@x> <alsolost@x>'),
        ov(12, '<12@x>', '<lost@x> <alsolost2@x>'),
    ]);
    // 10 is promoted; 11 and 12 share the missing <lost@x> through different missing parents.
    eq(['?(11,12)', '10'], shape($roots));
});

test('reply arriving before its parent, loops and duplicate Message-IDs are handled', function () {
    eq(['1(2)'], shape(Threader::build([ov(2, '<2@x>', '<1@x>'), ov(1, '<1@x>')])));
    eq(1, count(Threader::build([ov(1, '<1@x>', '<2@x>'), ov(2, '<2@x>', '<1@x>')])));
    eq(2, count(Threader::build([ov(1, '<1@x>'), ov(2, '<1@x>')])));
});
