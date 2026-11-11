<?php

declare(strict_types=1);

use Newsstand\Mime\Article;
use Newsstand\Mime\BodyFormatter;
use Newsstand\Mime\Flowed;
use Newsstand\Mime\HeaderParams;
use Newsstand\Text\Charset;
use Newsstand\Text\HeaderDecoder;
use Newsstand\Text\Mailbox;

test('RFC 2047: Q and B words, joined words, split multi-byte characters', function () {
    eq('Jörg Müller', HeaderDecoder::decode('=?ISO-8859-1?Q?J=F6rg?= =?ISO-8859-1?Q?_M=FCller?='));
    eq('Grüße aus Köln', HeaderDecoder::decode('=?UTF-8?B?R3LDvMOfZQ==?= aus =?utf-8?q?K=C3=B6ln?='));
    // "ö" (C3 B6) split across two words
    eq('Köln', HeaderDecoder::decode("=?UTF-8?Q?K=C3?=\r\n =?UTF-8?Q?=B6ln?="));
    eq('Re: Hallo Welt', HeaderDecoder::decode("Re: Hallo\r\n Welt"));
    eq('a b', HeaderDecoder::decode('=?utf-8*de?Q?a?= b'));
});

test('raw 8-bit headers: UTF-8 kept, Latin-1 converted', function () {
    eq('Jörg', HeaderDecoder::decode("J\xC3\xB6rg"));
    eq('Jörg', HeaderDecoder::decode("J\xF6rg"));
    eq('€5', Charset::toUtf8("\x805", 'iso-8859-1')); // decoded as windows-1252, like browsers
});

test('charsets: declared, unknown, wrong, and control characters', function () {
    eq('Grüße', Charset::toUtf8("Gr\xFC\xDFe", 'iso-8859-15'));
    eq('Grüße', Charset::toUtf8("Gr\xC3\xBC\xC3\x9Fe", 'us-ascii'));  // mislabelled UTF-8
    eq('Grüße', Charset::toUtf8("Gr\xFC\xDFe", 'x-nonsense-charset'));
    eq('ab', Charset::toUtf8("a\x00\x1Bb"));
    eq('Привет', Charset::toUtf8("\xF0\xD2\xC9\xD7\xC5\xD4", 'koi8-r'));
});

test('MIME parameters: quoting, RFC 2231 continuations and charsets, RFC 2047 in quotes', function () {
    [$v, $p] = HeaderParams::parse('text/plain; charset="UTF-8"; format=flowed; delsp=yes');
    eq('text/plain', $v);
    eq(['charset' => 'UTF-8', 'format' => 'flowed', 'delsp' => 'yes'], $p);
    [, $p] = HeaderParams::parse("attachment; filename*0*=utf-8''B%C3%BCro; filename*1=\".txt\"");
    eq('Büro.txt', $p['filename']);
    [, $p] = HeaderParams::parse('attachment; filename="=?UTF-8?Q?B=C3=BCro.pdf?="');
    eq('Büro.pdf', $p['filename']);
    [, $p] = HeaderParams::parse('multipart/mixed; boundary="a;b"');
    eq('a;b', $p['boundary']);
});

test('mailboxes: name <addr>, quoted names, addr (name), bare addresses', function () {
    $m = Mailbox::parse('"Doe, Jane" <jane@example.com>');
    eq(['Doe, Jane', 'jane@example.com'], [$m->name, $m->address]);
    $m = Mailbox::parse('jane@example.com (Jane Doe)');
    eq(['Jane Doe', 'jane@example.com'], [$m->name, $m->address]);
    eq('jane@example.com', Mailbox::parse('jane@example.com')->label());
    eq('Jane "JD" Doe', Mailbox::parse('"Jane \"JD\" Doe" <j@example.com>')->name);
});

test('format=flowed: joins soft breaks, keeps quotes and signature separator, DelSp', function () {
    $in = "This is a \nflowed paragraph.\n> quoted \n> line\n \n-- \nsig";
    eq("This is a flowed paragraph.\n> quoted line\n\n-- \nsig", Flowed::decode($in, false));
    eq('Wortteil', Flowed::decode("Wort \nteil", true));
    eq(" leading space kept\nnext", Flowed::decode("  leading space kept\nnext", false));
});

test('body blocks: quote levels and signature', function () {
    $blocks = BodyFormatter::blocks("Hi\n> one\n>> two\n> > two too\nback\n-- \nme");
    eq([0, 1, 2, 0, 0], array_column($blocks, 'level'));
    eq("-- \nme", $blocks[4]['text']);
    ok($blocks[4]['signature']);
    eq(">> two\n> > two too", $blocks[2]['text']);
});

test('quoted-printable bodies with soft line breaks and charset', function () {
    $raw = "Subject: x\nMIME-Version: 1.0\nContent-Type: text/plain; charset=iso-8859-1\nContent-Transfer-Encoding: quoted-printable\n\nGr=FC=DFe, das ist eine sehr lange Zei=\nle.\n";
    eq('Grüße, das ist eine sehr lange Zeile.', Article::fromRaw($raw)->text);
});

test('multipart: alternative prefers text/plain, attachments are listed', function () {
    $a = Article::fromRaw(fixture('articles/05-multipart-mixed.txt'));
    ok(str_contains($a->text, 'Plain text part'), 'plain part shown');
    ok(!str_contains($a->text, '<p>'), 'html part not shown');
    eq(['Büro-Plan.pdf'], array_column($a->attachments, 'name'));
    eq('application/pdf', $a->attachments[0]['type']);
});

test('uuencoded and yEnc blocks become attachments', function () {
    $a = Article::fromRaw(fixture('articles/09-uuencode-yenc.txt'));
    eq(['cat.jpg', 'dog.png'], array_column($a->attachments, 'name'));
    ok(!str_contains($a->text, 'M_]C_'), 'uuencoded data removed');
    ok(str_contains($a->text, 'Here are the pictures'), 'text kept');
});

test('HTML-only articles are shown as text', function () {
    eq("Hello\nworld & co", Article::htmlToText('<html><head><title>x</title></head><body><p>Hello</p>world &amp; co<script>alert(1)</script></body></html>'));
});

test('pathological input does not crash the parser', function () {
    $nested = "Content-Type: multipart/mixed; boundary=b0\n\n";
    for ($i = 1; $i < 30; $i++) {
        $nested .= "--b" . ($i - 1) . "\nContent-Type: multipart/mixed; boundary=b$i\n\n";
    }
    Article::fromRaw($nested);
    Article::fromRaw('');
    Article::fromRaw("no headers at all");
    Article::fromRaw("Content-Type: multipart/mixed\n\nno boundary");
    Article::fromRaw("Content-Type: text/plain; charset=\"\n\n\xFF\xFE");
    ok(true);
});
