<?php

declare(strict_types=1);

/*
 * Checks that the host still gives Newsstand what it needs. Run it after changing INN, PHP, cron or the
 * file layout, as the web user, with the same NEWSSTAND_CONFIG as the pool:
 *
 *   php bin/check-server.php [--group=NAME] [--inetd-limit=50] [--offline]
 *
 *   --group        a group with articles to test reading (default: the most active group in the cache)
 *   --inetd-limit  the loopback listener's spawn limit per 60 s, to compare the connection budget with
 *   --offline      skip the news server; check only PHP, configuration and files
 *
 * Uses at most one NNTP connection, taken from the same budget as web pages. It never posts and never
 * logs in: it sends POST without a login and expects a refusal. Exit code: 0 all fine, 1 warnings,
 * 2 failures.
 */

use Newsstand\Config;
use Newsstand\Nntp\Client;
use Newsstand\Nntp\ConnectionBudget;
use Newsstand\Ops\ServerCheck;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$opts = getopt('', ['group:', 'inetd-limit:', 'offline']);
try {
    $config = Config::load();
} catch (Throwable $e) {
    echo 'FAIL  ' . $e->getMessage() . "\n";
    exit(2);
}

$check = new ServerCheck($config, max(1, (int) ($opts['inetd-limit'] ?? 50)));
$check->local();

if (!isset($opts['offline'])) {
    $var = $config->string('var_dir');
    $budget = new ConnectionBudget($var . '/nntp-budget', $config->int('nntp_budget_per_minute'), $config->int('nntp_budget_burst'));
    $waited = 0;
    while (!$budget->take() && $waited < 60) {
        $pause = max(1, $budget->secondsUntilToken());
        sleep($pause);
        $waited += $pause;
    }
    if ($waited >= 60) {
        $check->add(ServerCheck::FAIL, 'Did not test the news server: the connection budget stayed empty for a minute (is the site very busy?)');
    } else {
        try {
            $client = Client::connect($config);
            $check->nntp($client, isset($opts['group']) ? (string) $opts['group'] : null);
        } catch (Throwable $e) {
            $check->add(ServerCheck::FAIL, 'Cannot reach the news server: ' . $e->getMessage()
                . '. If the first connection is refused, the loopback listener is down or inetd has closed it for 10 minutes');
        }
    }
}

$labels = [ServerCheck::OK => 'ok  ', ServerCheck::WARN => 'WARN', ServerCheck::FAIL => 'FAIL'];
foreach ($check->results() as [$status, $message]) {
    echo $labels[$status] . '  ' . $message . "\n";
}
$worst = $check->worst();
echo match ($worst) {
    ServerCheck::OK => "All checks passed.\n",
    ServerCheck::WARN => "Passed with warnings.\n",
    default => "Some checks failed.\n",
};
exit(match ($worst) { ServerCheck::OK => 0, ServerCheck::WARN => 1, default => 2 });
