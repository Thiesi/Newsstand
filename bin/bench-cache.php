<?php

declare(strict_types=1);

/*
 * Measures what each page type costs in the group cache: time and peak memory, each in a fresh PHP
 * process (Roanoke has no OPcache, so every request starts cold).
 *
 *   php bin/bench-cache.php            run all measurements against the configured var_dir
 *   php bin/bench-cache.php [query]    also search for [query] (default: "comp")
 */

use Newsstand\Config;
use Newsstand\Groups\GroupCache;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$cache = new GroupCache(Config::load()->string('var_dir') . '/cache');

if (($argv[1] ?? '') === '--one') {
    // Child process: run one measurement and print "ms peak-bytes detail".
    $t = microtime(true);
    $detail = match ($argv[2]) {
        'baseline' => 'empty run',
        'start' => count($cache->children('')) . ' hierarchies, ' . count($cache->mostActive()) . ' active',
        'browse' => count($cache->children($argv[3])) . " children of {$argv[3]}",
        'group' => $cache->group($argv[3]) !== null ? "found {$argv[3]}" : "missing {$argv[3]}",
        'search' => $cache->search($argv[3])['total'] . " matches for \"{$argv[3]}\"",
    };
    printf("%.1f %d %s\n", (microtime(true) - $t) * 1000, memory_get_peak_usage(), $detail);
    exit(0);
}

$meta = $cache->meta();
if ($meta === null) {
    fwrite(STDERR, "No group cache yet. Run bin/refresh-groups.php first.\n");
    exit(1);
}

// The biggest hierarchy level is the worst case for a browse page.
$biggest = '';
$biggestCount = 0;
foreach (array_keys($cache->children('')) as $top) {
    $n = count($cache->children((string) $top));
    if ($n > $biggestCount) {
        [$biggest, $biggestCount] = [(string) $top, $n];
    }
}
$someGroup = $meta['active'][0][0] ?? 'de.test';

$runs = [
    ['start'],
    ['browse', $biggest],
    ['group', $someGroup],
    ['search', $argv[1] ?? 'comp'],
    ['search', 'zzzznomatch'],
];
printf("%d groups, built %s UTC\n", $meta['count'], gmdate('Y-m-d H:i', $meta['built']));
$baseline = (int) (explode(' ', (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --one baseline'))[1] ?? 0);
foreach ($runs as $run) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --one ' . implode(' ', array_map('escapeshellarg', $run));
    [$ms, $peak, $detail] = explode(' ', trim((string) shell_exec($cmd)), 3) + ['?', '0', ''];
    printf("%-8s %7s ms  %6.1f MB peak (%+.1f MB over an empty script)  %s\n", $run[0], $ms, $peak / 1048576, ($peak - $baseline) / 1048576, $detail);
}
