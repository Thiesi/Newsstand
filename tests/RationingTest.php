<?php

declare(strict_types=1);

use Newsstand\Config;
use Newsstand\Groups\GroupCache;
use Newsstand\Nntp\Client;
use Newsstand\Nntp\ConnectionBudget;
use Newsstand\Nntp\NntpException;
use Newsstand\Nntp\ResponseCache;
use Newsstand\Web\App;

/*
 * NNTP rationing: every visitor shares one per-host limit at INN's loopback listener (inetd closes it for
 * ten minutes when exceeded), so Newsstand must spend connections carefully.
 */

test('budget: burst, then refill at the per-minute rate; never more than burst + rate in 60 s', function () {
    $b = new ConnectionBudget(tempDir() . '/budget', 30, 5);
    $t = 1000.0;
    $taken = 0;
    for ($i = 0; $i < 20; $i++) {
        $taken += $b->take($t) ? 1 : 0;
    }
    eq(5, $taken, 'burst');
    eq(2, $b->secondsUntilToken($t));
    ok(!$b->take($t + 1.0), 'half a token after one second');
    ok($b->take($t + 2.0), 'one token after two seconds');
    // Hammer it every 100 ms for a minute: at most burst + 30 succeed.
    $b = new ConnectionBudget(tempDir() . '/budget', 30, 5);
    $ok = 0;
    for ($i = 0; $i < 600; $i++) {
        $ok += $b->take(5000.0 + $i / 10) ? 1 : 0;
    }
    ok($ok <= 35 && $ok >= 33, "$ok connections in 60 s");
});

test('response cache: round trip, age, key check, no objects, prune', function () {
    $dir = tempDir();
    $c = new ResponseCache($dir);
    $c->put('k', ['a' => [1, 'two']], 100);
    eq([['a' => [1, 'two']], 50], $c->get('k', 150));
    eq(null, $c->get('other', 150));
    // A planted file with an object must not instantiate it.
    $file = glob("$dir/*/*")[0];
    file_put_contents($file, serialize(['t' => 100, 'k' => 'k', 'v' => [new ArrayObject([1])]]));
    $v = $c->get('k', 150);
    ok($v === null || !($v[0][0] instanceof ArrayObject), 'object instantiated');
    touch($file, 100);
    eq(1, $c->prune(10, 200));
    eq(null, $c->get('k', 200));
});

/** An App wired to a counting fake server. */
function rationedApp(string $var, int &$connections, array $config = [], ?Closure $failWhen = null, ?GroupCache $groups = null): App
{
    $over = "7\tHello\tA <a@example.com>\tWed, 11 Nov 2026 20:00:00 +0000\t<7@example.com>\t\t100\t3";
    $connect = function () use (&$connections, $over, $failWhen): Client {
        $connections++;
        if ($failWhen !== null && $failWhen()) {
            throw new NntpException('Connection to the news server closed');
        }
        return new Client(new FakeTransport('200 ready (no posting)', [
            'MODE READER' => '200 ready (no posting)',
            'GROUP de.test' => '211 1 7 7 de.test',
            'GROUP gone.group' => '411 No such group',
            'OVER 7' => block('224 ok', $over),
            'OVER 7-7' => block('224 ok', $over),
            'ARTICLE 7' => block('220 7 <7@example.com>', "Subject: Hello\nFrom: A <a@example.com>\n\nBody."),
            'QUIT' => '205 bye',
        ]));
    };
    return new App(Config::fromArray(['var_dir' => $var] + $config), $groups ?? new GroupCache($var . '/none'), $connect);
}

test('pages are cached: repeat views need no new connection', function () {
    $n = 0;
    $app = rationedApp(tempDir(), $n);
    eq(200, $app->handle('GET', '/g/de.test')->status);
    eq(200, $app->handle('GET', '/g/de.test')->status);
    eq(200, $app->handle('GET', '/g/de.test/7')->status);
    eq(200, $app->handle('GET', '/g/de.test/7')->status);
    eq(2, $n, 'one connection per uncached page, none for repeats');
    eq(404, $app->handle('GET', '/g/gone.group')->status);
    eq(404, $app->handle('GET', '/g/gone.group')->status);
    eq(3, $n, 'not-found results are cached too');
});

test('groups missing from the group cache get a 404 without connecting', function () {
    $var = tempDir();
    $groups = new GroupCache($var . '/cache');
    $groups->store(['de.test' => [7, 7, 'y', '', 0]], 1000);
    $n = 0;
    $app = rationedApp($var, $n, [], null, $groups);
    eq(404, $app->handle('GET', '/g/no.such.group')->status);
    eq(404, $app->handle('GET', '/g/no.such.group/1')->status);
    eq(0, $n);
    eq(200, $app->handle('GET', '/g/de.test')->status);
    eq(1, $n);
});

test('empty budget: stale copy if there is one, otherwise 503 with Retry-After', function () {
    $var = tempDir();
    $n = 0;
    $app = rationedApp($var, $n, ['nntp_budget_burst' => 1, 'nntp_budget_per_minute' => 1, 'cache_fresh_overview' => -1, 'cache_fresh_article' => -1]);
    eq(200, $app->handle('GET', '/g/de.test')->status);           // spends the only token
    $stale = $app->handle('GET', '/g/de.test');                      // expired cache, no token: stale copy
    eq(200, $stale->status);
    ok(str_contains($stale->body, 'saved copy'), 'stale notice shown');
    eq('public, max-age=10', $stale->headers['Cache-Control']);
    $busy = $app->handle('GET', '/g/de.test/7');                     // nothing cached, no token
    eq(503, $busy->status);
    ok((int) ($busy->headers['Retry-After'] ?? 0) > 0, 'Retry-After set');
    eq(1, $n, 'no connection attempted without a token');
});

test('server down: stale copy if there is one, otherwise 503', function () {
    $down = false;
    $n = 0;
    $app = rationedApp(tempDir(), $n, ['cache_fresh_overview' => -1], function () use (&$down) { return $down; });
    eq(200, $app->handle('GET', '/g/de.test')->status);
    $down = true;
    eq(200, $app->handle('GET', '/g/de.test')->status);
    eq(503, $app->handle('GET', '/g/de.test/7')->status);
});
