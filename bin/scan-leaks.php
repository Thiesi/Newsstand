<?php

declare(strict_types=1);

/*
 * Renders recent real articles through the App (anonymous view) and checks every page with the leak
 * detector. The obfuscation test set, run against real groups (docs/PLAN.md, phase 4).
 *
 *   php bin/scan-leaks.php [--per=8] [--pause=6] [--groups=12] [group ...]
 *
 * Without group names it takes the most active groups from the group cache. Gentle by design: at most
 * one NNTP connection per page (the App's budget applies, cached pages cost none) and a pause between
 * pages. The default 6 s keeps it near 10 connections a minute, so web traffic plus this scan stays
 * under inetd's limit. It stops at the first 503.
 *
 * Leaks are printed with the local part masked, so the output itself does not leak addresses. Add every
 * leaking article, with its addresses replaced by example ones, to tests/fixtures/articles.
 */

use Newsstand\Config;
use Newsstand\Groups\GroupCache;
use Newsstand\Nntp\ResponseCache;
use Newsstand\Privacy\LeakDetector;
use Newsstand\Web\App;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$opts = ['per' => 8, 'pause' => 6, 'groups' => 12];
$names = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(per|pause|groups)=(\d+)$/', $arg, $m)) {
        $opts[$m[1]] = (int) $m[2];
    } else {
        $names[] = $arg;
    }
}
$opts['pause'] = max(3, $opts['pause']);

$config = Config::load();
$var = $config->string('var_dir');
$groups = new GroupCache($var . '/cache');
$app = new App($config, $groups);
$cache = new ResponseCache($var . '/nntp-cache');

if ($names === []) {
    $names = array_column($groups->mostActive($opts['groups']), 0);
}
if ($names === []) {
    fwrite(STDERR, "No groups given and no group cache to pick from.\n");
    exit(1);
}

$mask = static fn (string $s): string => preg_replace_callback(
    '/([^\s@<>"(]{1,40})@/u',
    static fn (array $m): string => mb_substr($m[1], 0, 2) . '##@',
    $s,
) ?? '[unprintable]';

$pages = 0;
$leaks = 0;
$statuses = [];
$visit = static function (string $url) use ($app, $opts, $mask, &$pages, &$leaks, &$statuses): void {
    $r = $app->handle('GET', $url);
    $pages++;
    $statuses[$r->status] = ($statuses[$r->status] ?? 0) + 1;
    foreach (LeakDetector::find($r->body) as $leak) {
        $leaks++;
        echo "LEAK $url: ", $mask($leak), "\n";
    }
    if ($r->status === 503) {
        echo "503 on $url, stopping so the listener is not pushed further.\n";
        exit(2);
    }
    sleep($opts['pause']);
};

foreach ($names as $name) {
    $visit('/g/' . $name);
    $hit = $cache->get("over\0$name\0");
    $numbers = $hit !== null ? array_reverse(array_column($hit[0]['entries'] ?? [], 0)) : [];
    foreach (array_slice($numbers, 0, $opts['per']) as $n) {
        $visit("/g/$name/$n");
    }
    echo "done $name\n";
}
ksort($statuses);
echo "$pages pages, $leaks leaks, statuses ", json_encode($statuses), "\n";
exit($leaks > 0 ? 3 : 0);
