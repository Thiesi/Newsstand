<?php

declare(strict_types=1);

use Newsstand\Groups\GroupCache;
use Newsstand\Nntp\Client;

function cacheFrom(string $active, string $newsgroups, ?string $dir = null, int $now = 1_000_000): GroupCache
{
    $cache = new GroupCache($dir ?? tempDir());
    $cache->refresh(new Client(new FakeTransport('200 ready', [
        'LIST ACTIVE' => block('215 ok', $active),
        'LIST NEWSGROUPS' => block('215 ok', $newsgroups),
    ])), $now);
    return $cache;
}

const ACTIVE = "de.comp.lang.php 200 1 y\nde.comp.lang.php.misc 50 1 y\nde.comp.os.unix 300 1 y\nde.rec.fahrrad 900 1 y\n"
    . "de.admin.news.announce 10 1 m\ncomp.lang.php 5000 1 y\nalt.binaries.pictures 9 1 y\n123.numeric 1 1 y\nbad..name 1 1 y";
const NEWSGROUPS = "de.comp.lang.php\tPHP: Hypertext Preprocessor.\nde.rec.fahrrad\tRadfahren. Kontakt: radler@example.com\n"
    . "comp.lang.php\tThe PHP language.\nde.admin.news.announce\tAnk\xFCndigungen. (Moderiert)";

test('the hierarchy tree counts groups and marks groups that also have children', function () {
    $c = cacheFrom(ACTIVE, NEWSGROUPS);
    eq(['123', 'alt', 'comp', 'de'], array_map('strval', array_keys($c->children(''))));
    $de = $c->children('')['de'];
    eq(5, $de[GroupCache::N_GROUPS]);
    $php = $c->children('de.comp.lang')['php'];
    eq(true, $php[GroupCache::N_IS_GROUP]);
    eq(true, $php[GroupCache::N_HAS_CHILDREN]);
    eq(null, $c->group('bad..name'));
    eq('Ankündigungen. (Moderiert)', $c->group('de.admin.news.announce')[GroupCache::DESC]);
    eq('m', $c->group('de.admin.news.announce')[GroupCache::STATUS]);
    ok($c->group('123.numeric') !== null);
});

test('search matches names and descriptions, all words, names first', function () {
    $c = cacheFrom(ACTIVE, NEWSGROUPS);
    $names = fn (array $r) => array_column($r['results'], 0);
    $r = $c->search('php');
    eq(3, $r['total']);
    eq('comp.lang.php', $r['results'][0][0]);
    eq(5000, $r['results'][0][1][GroupCache::HIGH]);
    eq(['de.rec.fahrrad'], $names($c->search('radfahren')));
    eq(['de.comp.lang.php'], $names($c->search('PHP Hypertext')));
    eq(['de.admin.news.announce'], $names($c->search('ankündigungen')));
    eq(0, $c->search('php fahrrad')['total']);
    eq(0, $c->search('   ')['total']);
});

test('recent activity is the growth since the daily snapshot', function () {
    $dir = tempDir();
    cacheFrom(ACTIVE, NEWSGROUPS, $dir, 1_000_000);
    $grown = str_replace('de.rec.fahrrad 900', 'de.rec.fahrrad 925', ACTIVE);
    $c = cacheFrom($grown, NEWSGROUPS, $dir, 1_000_900);
    eq(25, $c->group('de.rec.fahrrad')[GroupCache::NEW]);
    eq(25, $c->children('')['de'][GroupCache::N_NEW]);
    eq([['de.rec.fahrrad', 25]], $c->mostActive());
    eq(1_000_000, $c->meta()['snapshot']);
    // A fresh instance reads the same data back from the generated files.
    $again = new GroupCache($dir);
    eq(25, $again->group('de.rec.fahrrad')[GroupCache::NEW]);
    // Only the current and the previous build are kept.
    cacheFrom($grown, NEWSGROUPS, $dir, 1_001_800);
    eq(2, count(glob("$dir/build-*", GLOB_ONLYDIR)));
});

test('group names differing only in case get separate level files', function () {
    $c = cacheFrom("Case.test 5 1 y\ncase.test 7 1 y\nCase.test.sub 1 1 y\ncase.test.sub 2 1 y", '');
    eq(5, $c->group('Case.test')[GroupCache::HIGH]);
    eq(7, $c->group('case.test')[GroupCache::HIGH]);
    eq(1, $c->children('Case.test')['sub'][GroupCache::N_RECORD][GroupCache::HIGH]);
    eq(2, $c->children('case.test')['sub'][GroupCache::N_RECORD][GroupCache::HIGH]);
    eq([], $c->children('../../etc'));
});

test('a realistic 47,000-group list builds and searches quickly', function () {
    $lines = [];
    $tops = ['de', 'comp', 'alt', 'rec', 'sci', 'news', 'misc', 'soc', 'talk', 'at', 'ch', 'uk', 'fr', 'it', 'nl'];
    for ($i = 0; $i < 47000; $i++) {
        $lines[] = sprintf('%s.h%d.g%d.n%d %d 1 y', $tops[$i % 15], $i % 40, $i % 300, $i, $i);
    }
    $dir = tempDir();
    $t = microtime(true);
    cacheFrom(implode("\n", $lines), '', $dir);
    $build = microtime(true) - $t;
    ok($build < 30, "build took $build s");

    // What a single page costs, from a fresh instance (no OPcache on Roanoke, so nothing is shared).
    $c = new GroupCache($dir);
    $mem = memory_get_usage();
    $t = microtime(true);
    $top = $c->children('');
    $de = $c->children('de');
    $g = $c->group('de.h0.g0.n0');
    $pageTime = microtime(true) - $t;
    $pageMem = memory_get_usage() - $mem;
    ok(count($top) === 15 && count($de) === 8 && $g !== null, 'lookups work');
    ok($pageTime < 0.25, sprintf('browse lookups took %.1f ms', $pageTime * 1000));
    ok($pageMem < 1_000_000, "browse lookups used $pageMem bytes");

    $mem = memory_get_usage();
    $t = microtime(true);
    $r = $c->search('g17');
    $search = microtime(true) - $t;
    $searchMem = memory_get_peak_usage() - $mem;
    ok($r['total'] > 0 && count($r['results']) === 100);
    ok($search < 1.0, "search took $search s");
});
