<?php

declare(strict_types=1);

/*
 * Rebuilds the group cache from LIST ACTIVE and LIST NEWSGROUPS, and prunes old NNTP cache entries,
 * expired sessions and old throttle counters.
 * Run from cron every 15 minutes:   php bin/refresh-groups.php
 * Quiet on success unless -v is given, so cron only mails on failure.
 *
 * The connection counts against the same budget as web pages; if the budget is empty, it waits a little.
 */

use Newsstand\Auth\SessionStore;
use Newsstand\Auth\Throttle;
use Newsstand\Config;
use Newsstand\Groups\GroupCache;
use Newsstand\Nntp\Client;
use Newsstand\Nntp\ConnectionBudget;
use Newsstand\Nntp\ResponseCache;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$verbose = in_array('-v', $argv, true);
try {
    $config = Config::load();
    $var = $config->string('var_dir');

    $pruned = (new ResponseCache($var . '/nntp-cache'))->prune($config->int('cache_max_age'));
    $sessionDir = $config->string('session_dir');
    (new SessionStore($sessionDir !== '' ? $sessionDir : $var . '/sessions', $config->int('session_idle'), $config->int('session_lifetime')))->prune();
    (new Throttle($var . '/throttle'))->prune();

    $budget = new ConnectionBudget($var . '/nntp-budget', $config->int('nntp_budget_per_minute'), $config->int('nntp_budget_burst'));
    $waited = 0;
    while (!$budget->take()) {
        if ($waited >= 120) {
            throw new RuntimeException('connection budget stayed empty for two minutes');
        }
        $pause = max(1, $budget->secondsUntilToken());
        sleep($pause);
        $waited += $pause;
    }

    $started = microtime(true);
    $client = Client::connect($config);
    $count = (new GroupCache($var . '/cache'))->refresh($client);
    $client->quit();
    if ($verbose) {
        printf("%d groups cached in %.1f s, peak memory %.1f MB; %d old NNTP cache entries pruned\n", $count, microtime(true) - $started, memory_get_peak_usage(true) / 1048576, $pruned);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'refresh-groups: ' . $e->getMessage() . "\n");
    exit(1);
}
