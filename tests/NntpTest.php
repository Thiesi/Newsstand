<?php

declare(strict_types=1);

use Newsstand\Nntp\Client;
use Newsstand\Nntp\NntpException;
use Newsstand\Nntp\SocketTransport;

test('the client refuses every host except loopback', function () {
    foreach (['News.NetWorkXXIII.de', '91.134.122.80', 'localhost', '127.0.0.2', '0.0.0.0', ''] as $host) {
        $e = throws(fn () => SocketTransport::connect($host, 119, 1), NntpException::class, "connected to $host");
        ok(str_contains($e->getMessage(), 'loopback'), "wrong refusal for $host");
    }
});

test('the greeting tells "(no posting)" apart from posting', function () {
    $c = new Client(new FakeTransport('200 roanoke InterNetNews NNRP server INN 2.7.2 ready (no posting)'));
    ok(!$c->postingAdvertised());
    $c = new Client(new FakeTransport('200 roanoke InterNetNews NNRP server ready (posting ok)'));
    ok($c->postingAdvertised());
    throws(fn () => new Client(new FakeTransport('502 You have no permission to talk')), NntpException::class);
});

test('GROUP, OVER and ARTICLE parse responses and undo dot-stuffing', function () {
    $over = "10\tHello =?UTF-8?Q?W=C3=B6rld?=\tJörg <j@example.com>\tWed, 11 Nov 2026 20:00:00 +0100\t<a@example.com>\t\t1200\t20\n"
        . "11\tRe: Hello\tB <b@example.org>\tWed, 11 Nov 2026 21:00:00 +0100\t<b@example.org>\t<a@example.com>\t900\t12";
    $t = new FakeTransport('200 ready (no posting)', [
        'GROUP de.test' => '211 2 10 11 de.test',
        'OVER 10-11' => block('224 Overview information follows', $over),
        'ARTICLE 10' => block('220 10 <a@example.com>', "Subject: x\n\n.leading dot\n..two dots\nend"),
    ]);
    $c = new Client($t);
    eq(['count' => 2, 'low' => 10, 'high' => 11, 'name' => 'de.test'], $c->group('de.test'));
    $entries = $c->over(10, 11);
    eq(2, count($entries));
    eq('Hello Wörld', $entries[0]->subject);
    eq(['<a@example.com>'], $entries[1]->references);
    // block() dot-stuffs the body like a server would; the client must hand back the original.
    eq("Subject: x\n\n.leading dot\n..two dots\nend", $c->article(10));
});

test('error codes become exceptions with the status', function () {
    $c = new Client(new FakeTransport('200 ready', ['GROUP no.such' => '411 No such group no.such', 'GROUP de.test' => '211 0 1 0 de.test', 'ARTICLE 5' => '423 No such article']));
    eq(true, throws(fn () => $c->group('no.such'), NntpException::class)->isNoSuchGroup());
    $c->group('de.test');
    eq(true, throws(fn () => $c->article(5), NntpException::class)->isNoSuchArticle());
});

test('commands with line breaks and invalid group names are refused before sending', function () {
    $t = new FakeTransport('200 ready');
    $c = new Client($t);
    throws(fn () => $c->command("GROUP x\r\nPOST"), NntpException::class);
    throws(fn () => $c->group("de.test\nPOST"), NntpException::class);
    throws(fn () => $c->group('de test'), NntpException::class);
    eq([], $t->sent);
});

test('oversized articles stop reading', function () {
    $t = new FakeTransport('200 ready', ['GROUP g' => '211 1 1 1 g', 'ARTICLE 1' => block('220 1 <x@y>', str_repeat("line\n", 100))]);
    $c = new Client($t);
    $c->group('g');
    eq(-1, throws(fn () => $c->article(1, 50), NntpException::class)->status);
});

test('LIST ACTIVE and LIST NEWSGROUPS stream entries', function () {
    $c = new Client(new FakeTransport('200 ready', [
        'LIST ACTIVE' => block('215 list follows', "de.test 0000012345 0000012000 y\nde.admin.news.announce 0000000100 0000000001 m"),
        'LIST NEWSGROUPS' => block('215 list follows', "de.test\tTesten\nde.admin.news.announce\tAnkündigungen. (Moderiert)"),
    ]));
    $active = iterator_to_array($c->listActive(), false);
    eq('m', $active[1]['status']);
    eq(12345, $active[0]['high']);
    eq(['de.test' => 'Testen', 'de.admin.news.announce' => 'Ankündigungen. (Moderiert)'], iterator_to_array($c->listNewsgroups()));
});
