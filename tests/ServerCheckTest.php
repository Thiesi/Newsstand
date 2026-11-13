<?php

declare(strict_types=1);

use Newsstand\Config;
use Newsstand\Groups\GroupCache;
use Newsstand\Nntp\Client;
use Newsstand\Ops\ServerCheck;

/** Responses of a healthy loopback listener for group local.test with articles 1–3. */
function healthyServer(array $override = []): array
{
    $over = implode("\n", array_map(
        static fn (int $n) => "$n\tTest $n\tA <a@example.com>\tThu, 12 Nov 2026 10:00:00 +0000\t<$n@example.com>\t\t100\t3",
        [1, 2, 3],
    ));
    return $override + [
        'POST' => '480 Posting not allowed',
        'LIST ACTIVE local.test' => block('215 list follows', 'local.test 3 1 y'),
        'LIST NEWSGROUPS local.test' => block('215 list follows', "local.test\tLocal tests"),
        'GROUP local.test' => '211 3 1 3 local.test',
        'OVER 1-3' => block('224 overview follows', $over),
        'ARTICLE 3' => block('220 3 <3@example.com>', "From: a@example.com\nMessage-ID: <3@example.com>\n\nHello"),
        'QUIT' => '205 bye',
    ];
}

function statuses(ServerCheck $c, string $status): array
{
    return array_values(array_map(static fn ($r) => $r[1], array_filter($c->results(), static fn ($r) => $r[0] === $status)));
}

test('server check: a healthy listener passes without logging in or posting', function () {
    $t = new FakeTransport('200 news.example.com ready (no posting)', healthyServer());
    $check = new ServerCheck(Config::fromArray(['var_dir' => tempDir()]));
    $check->nntp(new Client($t), 'local.test');
    eq([], statuses($check, ServerCheck::FAIL));
    eq([], statuses($check, ServerCheck::WARN));
    eq(['POST', 'LIST ACTIVE local.test', 'LIST NEWSGROUPS local.test', 'GROUP local.test', 'OVER 1-3', 'ARTICLE 3', 'QUIT'], $t->sent);
    eq(null, $t->posted);
});

test('server check: POST accepted without login fails loudly and sends nothing more', function () {
    $t = new FakeTransport('200 news.example.com ready (no posting)', healthyServer(['POST' => '340 Ok, recommended ID <x@example.com>']));
    $check = new ServerCheck(Config::fromArray(['var_dir' => tempDir()]));
    $check->nntp(new Client($t), 'local.test');
    eq(['POST'], $t->sent, 'no QUIT and no article text after a 340');
    eq([], $t->posted);
    ok(str_contains(implode("\n", statuses($check, ServerCheck::FAIL)), 'accepts POST without login'));
    eq(ServerCheck::FAIL, $check->worst());
});

test('server check: a greeting that offers posting fails', function () {
    $t = new FakeTransport('200 news.example.com ready', healthyServer());
    $check = new ServerCheck(Config::fromArray(['var_dir' => tempDir()]));
    $check->nntp(new Client($t), 'local.test');
    ok(str_contains(implode("\n", statuses($check, ServerCheck::FAIL)), 'offers posting without login'));
});

test('server check: missing overview data and unknown groups fail', function () {
    $t = new FakeTransport('200 ready (no posting)', healthyServer(['OVER 1-3' => '423 No articles in that range']));
    $check = new ServerCheck(Config::fromArray(['var_dir' => tempDir()]));
    $check->nntp(new Client($t), 'local.test');
    ok(str_contains(implode("\n", statuses($check, ServerCheck::FAIL)), 'OVER returned nothing'));

    $t = new FakeTransport('200 ready (no posting)', healthyServer(['LIST ACTIVE local.test' => block('215 list follows', '')]));
    $check = new ServerCheck(Config::fromArray(['var_dir' => tempDir()]));
    $check->nntp(new Client($t), 'local.test');
    ok(str_contains(implode("\n", statuses($check, ServerCheck::FAIL)), 'does not know local.test'));
    eq('QUIT', end($t->sent));
});

test('server check: an expired newest article falls back to the one before', function () {
    $t = new FakeTransport('200 ready (no posting)', healthyServer([
        'ARTICLE 3' => '423 No such article',
        'ARTICLE 2' => block('220 2 <2@example.com>', "Message-ID: <2@example.com>\n\nHi"),
    ]));
    $check = new ServerCheck(Config::fromArray(['var_dir' => tempDir()]));
    $check->nntp(new Client($t), 'local.test');
    eq([], statuses($check, ServerCheck::FAIL));
});

test('server check: the default group comes from the cache', function () {
    $var = tempDir();
    $cache = new GroupCache($var . '/cache');
    $cache->store(['local.test' => [1, 1, 'y', 'Local tests', 0]], time() - 900);
    (new GroupCache($var . '/cache'))->store(['local.test' => [3, 1, 'y', 'Local tests', 0]], time());
    $t = new FakeTransport('200 ready (no posting)', healthyServer());
    $check = new ServerCheck(Config::fromArray(['var_dir' => $var]));
    $check->nntp(new Client($t), null);
    ok(in_array('LIST ACTIVE local.test', $t->sent, true));
});

test('server check: local checks report config, budget, files and cache age', function () {
    $var = tempDir();
    mkdir($var . '/log');
    $check = new ServerCheck(Config::fromArray(['var_dir' => $var]));
    $check->local();
    ok(str_contains(implode("\n", statuses($check, ServerCheck::FAIL)), 'No group cache'));

    (new GroupCache($var . '/cache'))->store(['local.test' => [3, 1, 'y', '', 0]], time() - 3600);
    $check = new ServerCheck(Config::fromArray(['var_dir' => $var, 'nntp_host' => '192.0.2.1', 'nntp_budget_per_minute' => 40]));
    $check->local();
    $fails = implode("\n", statuses($check, ServerCheck::FAIL));
    $warns = implode("\n", statuses($check, ServerCheck::WARN));
    ok(str_contains($fails, 'must be the loopback interface'), $fails);
    ok(str_contains($warns, 'too close to the listener'), $warns);
    ok(str_contains($warns, 'is it running?'), $warns);

    // A raised listener limit makes the same budget fine.
    $check = new ServerCheck(Config::fromArray(['var_dir' => $var, 'nntp_budget_per_minute' => 40]), 300);
    $check->local();
    ok(!str_contains(implode("\n", statuses($check, ServerCheck::WARN)), 'too close'));
});
