<?php

declare(strict_types=1);

use Newsstand\Config;
use Newsstand\Groups\GroupCache;
use Newsstand\Mime\Headers;
use Newsstand\Nntp\Client;
use Newsstand\Privacy\Obfuscator;
use Newsstand\Web\App;

/*
 * The safeguard for address obfuscation. Every fixture in tests/fixtures/articles is rendered through the
 * real App and templates (article page and thread list), then the HTML is searched for leaks with a
 * detector that is deliberately independent of the Obfuscator's own pattern.
 *
 * When a real-world article leaks, add it (with its addresses replaced by example ones) as a new fixture.
 */

test('obfuscator: shape of disguised addresses', function () {
    $o = Obfuscator::anonymous();
    eq('jona…@example.com', $o->address('jonathan@example.com'));
    eq('jane…@example.com', $o->address('jane.doe@example.com'));
    eq('b…@example.com', $o->address('bob@example.com'));
    eq('…@example.com', $o->address('x@example.com'));
    eq('a…@example.com', $o->address('ab@example.com'));
    eq('<abc1…@jane.example.com>', $o->text('<abc123.fsf@jane.example.com>'));
    eq('"john…"@example.com', $o->text('"john q public"@example.com'));
});

test('obfuscator: idempotent, leaves disguised forms alone, reveals for logged-in users', function () {
    $o = Obfuscator::anonymous();
    $once = $o->text('Mail jane.doe@example.com now');
    eq($once, $o->text($once));
    eq('user (at) example.com, user at example dot com', $o->text('user (at) example.com, user at example dot com'));
    eq('no addresses here: 10 @ 5pm, @handle, a@', $o->text('no addresses here: 10 @ 5pm, @handle, a@'));
    eq('jane.doe@example.com', (new Obfuscator(true))->text('jane.doe@example.com'));
});

test('obfuscator: invalid UTF-8 never falls back to the raw text', function () {
    eq('[hidden]', Obfuscator::anonymous()->text("bad \xFF jane.doe@example.com"));
});

/** Every visible @ must directly follow a disguised local part. Returns the leaked snippets. */
function findLeaks(string $html): array
{
    $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = rawurldecode($text);
    $text = str_replace(['＠', '&commat;'], '@', $text);
    $leaks = [];
    preg_match_all('/@/u', $text, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as [, $pos]) {
        // Byte offsets can split a multi-byte character; scrub so the /u patterns below work.
        $before = mb_scrub(substr($text, max(0, $pos - 40), min(40, $pos)), 'UTF-8');
        $after = mb_scrub(substr($text, $pos + 1, 30), 'UTF-8');
        if (!preg_match('/^[\p{L}\p{N}\[]/u', $after)) {
            continue; // not followed by a domain: not an address
        }
        if (preg_match('/(…"?|\[(\.\.\.|…)\])$/u', $before)) {
            continue; // disguised by us, or already elided by the author's software ("[...]@example.de")
        }
        if (preg_match('/[\s<>(\[,;:\/=]$/u', $before) || $before === '') {
            continue; // "@domain" without a local part
        }
        $leaks[] = trim(substr($before, -20)) . '@' . substr($after, 0, 20);
    }
    return $leaks;
}

test('the leak detector itself catches leaks', function () {
    eq(1, count(findLeaks('<p>jane.doe@example.com</p>')));
    eq(1, count(findLeaks('<a href="mailto:jane&#64;example.com">x</a>')));
    eq(1, count(findLeaks('?mail=jane%40example.com')));
    eq(1, count(findLeaks('jane＠example.jp')));
    eq(1, count(findLeaks('"john q"@example.com')));
    eq(1, count(findLeaks('rc.@example.com says')));
    eq(0, count(findLeaks('<p>jane…@example.com, "jo…"@example.com, @handle, 10 @ 5pm, [...]@example.de</p>')));
});

/** Renders a fixture as article page and as thread-list entry through the real App. */
function renderFixture(string $raw): array
{
    $raw = str_replace("\r\n", "\n", $raw);
    $headers = Headers::parse(substr($raw, 0, (int) strpos($raw, "\n\n")));
    $overLine = implode("\t", [
        '42',
        (string) $headers->get('Subject'),
        (string) $headers->get('From'),
        (string) $headers->get('Date'),
        (string) $headers->get('Message-ID'),
        (string) $headers->get('References'),
        (string) strlen($raw),
        (string) substr_count($raw, "\n"),
    ]);
    $connect = fn () => new Client(new FakeTransport('200 ready (no posting)', [
        'MODE READER' => '200 ready (no posting)',
        'GROUP de.test' => '211 1 42 42 de.test',
        'OVER 42' => block('224 ok', $overLine),
        'OVER 42-42' => block('224 ok', $overLine),
        'ARTICLE 42' => block('220 42 <x@y>', $raw),
        'QUIT' => '205 bye',
    ]));
    // A fresh var_dir per fixture, so the NNTP response cache never serves one fixture for another.
    $app = new App(Config::fromArray(['var_dir' => tempDir()]), new GroupCache(sys_get_temp_dir() . '/newsstand-none'), $connect);
    return [
        'article' => $app->handle('GET', '/g/de.test/42'),
        'group' => $app->handle('GET', '/g/de.test'),
    ];
}

/** Local parts that must never appear in full, per fixture. */
const SECRETS = [
    '01-plain-ascii.txt' => ['jane.doe', 'john.smith', 'webmaster', 'postmaster', 'support', 'replies-jane', 'list-bounce', 'abc123.fsf', 'older.1'],
    '02-qp-latin1-german.txt' => ['joerg.mueller', 'hans.wuerfel', 'fraeulein.schmidt', 'ganz.besonders', 'k0ln.2026'],
    '03-base64-utf8.txt' => ['åsa.nilsson', 'asa.nilsson', 'jörg@'],
    '04-format-flowed.txt' => ['flow.writer', 'orig.poster', 'quoted.person', 'deeper.person'],
    '05-multipart-mixed.txt' => ['multi.part', 'plain.part', 'html.part'],
    '06-old-style-8bit.txt' => ['oldtimer', 'reply.oldtimer', 'someone', '1996Nov'],
    '07-disguised-and-urls.txt' => ['nospam.disguised', 'shape.subject', 'mailto.person', 'url.person', 'secret@', 'fullwidth.person', 'entity.person', 'john q public', 'root@', 'jürgen.bäcker', 'plus.user', 'UPPER.CASE', 'paren.person', 'bracket.person', 'last.person', 'list@'],
    '08-encoded-address-in-name.txt' => ['name.is.address', 'subject.person', 'org.contact'],
    '09-uuencode-yenc.txt' => ['binary.poster'],
    '10-mislabelled-charset.txt' => ['rene@', 'rené.français'],
    '11-html-only.txt' => ['html.only.person', 'other@'],
    '12-xss-attempt.txt' => ['xss.person'],
    '13-found-on-usenet.txt' => ['rc.@', 'trailing.dot.@', 'first.last$tag', 'percent.hack%relay', 'redirect.user'],
];

foreach (glob(__DIR__ . '/fixtures/articles/*.txt') as $file) {
    $name = basename($file);
    test("no address leaks: $name", function () use ($file, $name) {
        ok(isset(SECRETS[$name]), "add $name to SECRETS");
        foreach (renderFixture((string) file_get_contents($file)) as $page => $response) {
            eq(200, $response->status, "$page page status");
            $leaks = findLeaks($response->body);
            ok($leaks === [], "$page page leaks: " . implode(' | ', $leaks));
            $visible = rawurldecode(html_entity_decode($response->body, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            foreach (SECRETS[$name] as $secret) {
                ok(!str_contains($visible, $secret), "$page page shows '$secret'");
            }
        }
    });
}

test('decoding is correct in the rendered article (spot checks)', function () {
    $body = fn (string $f) => renderFixture(fixture("articles/$f"))['article']->body;
    $p = $body('02-qp-latin1-german.txt');
    ok(str_contains($p, 'Grüße aus Köln'), 'QP subject');
    ok(str_contains($p, 'Jörg Müller'), 'encoded From name');
    ok(str_contains($p, 'Wer kennt die Adresse von Fräulein Schmidt?'), 'QP Latin-1 body');
    ok(str_contains($body('03-base64-utf8.txt'), 'Hällo, meine Adresse ist'), 'base64 UTF-8 body');
    ok(str_contains($body('04-format-flowed.txt'), 'wrapped with a soft break so that it flows.'), 'flowed quote joined');
    ok(str_contains($body('06-old-style-8bit.txt'), 'Umlaute ohne MIME: Grüße'), 'raw Latin-1 subject');
    ok(str_contains($body('10-mislabelled-charset.txt'), 'Café au lait'), 'raw UTF-8 subject');
    ok(str_contains($body('10-mislabelled-charset.txt'), 'René'), 'UTF-8 labelled us-ascii');
});

test('article content is escaped and links are safe', function () {
    $r = renderFixture(fixture('articles/12-xss-attempt.txt'));
    foreach ($r as $page => $response) {
        ok(!preg_match('/<script/i', $response->body), "$page: script tag");
        ok(!preg_match('/<img src=x/i', $response->body), "$page: img tag");
        ok(!preg_match('/href="javascript:/i', $response->body), "$page: javascript link");
        ok(str_contains($response->headers['Content-Security-Policy'], "default-src 'none'"), "$page: CSP");
    }
    ok(str_contains($r['article']->body, 'rel="nofollow ugc noopener"'), 'links get nofollow ugc');
});

test('URLs never contain Message-IDs and anonymous pages are publicly cacheable', function () {
    $r = renderFixture(fixture('articles/01-plain-ascii.txt'));
    preg_match_all('/href="([^"]*)"/', $r['group']->body, $m);
    foreach ($m[1] as $href) {
        ok(!str_contains($href, '@') && !str_contains($href, '%40') && !str_contains($href, '%3C'), "href $href");
    }
    eq('public, max-age=60', $r['article']->headers['Cache-Control']);
});
