<?php

declare(strict_types=1);

use Newsstand\Auth\SessionStore;
use Newsstand\Auth\Throttle;
use Newsstand\Config;
use Newsstand\Groups\GroupCache;
use Newsstand\Nntp\Client;
use Newsstand\Post\Composer;
use Newsstand\Web\App;
use Newsstand\Web\Request;

/* Phase 3: login, sessions, posting, limits. All against the fake NNTP server; nothing is ever posted. */

// ---- sessions ---------------------------------------------------------------------------------

test('sessions: the password is only readable with the cookie', function () {
    $dir = tempDir();
    $store = new SessionStore($dir);
    [$cookie, $s] = $store->create('jane.doe@example.com', 'hunter2 with spaces');
    eq('hunter2 with spaces', $store->load($cookie)->password());
    eq('jane.doe@example.com', $store->load($cookie)->user);

    $files = glob("$dir/*.json");
    eq(1, count($files));
    ok(!str_contains((string) file_get_contents($files[0]), 'hunter2'), 'password stored in plain text');
    ok(!str_contains(basename($files[0]), explode('.', $cookie)[0]), 'file named after the raw id');

    [$id] = explode('.', $cookie);
    $otherKey = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    eq(null, $store->load("$id.$otherKey"), 'wrong key accepted');
    eq(null, $store->load('garbage'));
    eq(null, $store->load(null));
});

test('sessions: idle timeout, lifetime, destroy, prune', function () {
    $store = new SessionStore(tempDir(), 100, 1000);
    [$cookie] = $store->create('a@example.com', 'pw', '', 5000);
    ok($store->load($cookie, 5090) !== null, 'still active');
    ok($store->load($cookie, 5180) !== null, 'idle timer was refreshed at 5090');
    eq(null, $store->load($cookie, 5400), 'idle session still valid');

    [$cookie] = $store->create('a@example.com', 'pw', '', 5000);
    for ($t = 5050; $t < 6000; $t += 60) {
        $store->load($cookie, $t);
    }
    eq(null, $store->load($cookie, 6050), 'session outlived its lifetime');

    [$cookie] = $store->create('a@example.com', 'pw');
    $store->destroy($cookie);
    eq(null, $store->load($cookie));

    $store->create('b@example.com', 'pw', '', 1000);
    eq(1, $store->prune(2000));
});

// ---- throttle ---------------------------------------------------------------------------------

test('throttle: sliding window and IPv6 /64 keys', function () {
    $t = new Throttle(tempDir());
    for ($i = 0; $i < 5; $i++) {
        $t->add('k', 1000 + $i);
    }
    eq(5, $t->count('k', 60, 1010));
    eq(56, $t->retryAfter('k', 60, 5, 1004 + 0));
    eq(0, $t->retryAfter('k', 60, 6, 1010));
    eq(0, $t->retryAfter('k', 60, 5, 1061));
    eq(0, $t->count('other', 60, 1010));
    eq(Throttle::ipKey('2001:db8:1:2::5'), Throttle::ipKey('2001:db8:1:2:ffff::1'));
    ok(Throttle::ipKey('2001:db8:1:3::5') !== Throttle::ipKey('2001:db8:1:2::5'));
    eq('ip:192.0.2.1', Throttle::ipKey('192.0.2.1'));
});

// ---- composing --------------------------------------------------------------------------------

test('composer: wrapping at 72, quotes left alone, signature separator kept', function () {
    $long = str_repeat('word ', 30);
    foreach (explode("\n", Composer::wrap($long)) as $line) {
        ok(mb_strlen($line) <= 72, "line too long: $line");
    }
    $quote = '> ' . str_repeat('q', 100);
    eq($quote, Composer::wrap($quote));
    eq("text\n-- \nsig", Composer::wrap("text  \r\n-- \r\nsig"));
    $url = 'https://example.com/' . str_repeat('x', 100);
    eq("see\n$url", Composer::wrap("see $url"), 'long words are not broken');
});

test('composer: reply quote and subject', function () {
    eq("Jane wrote:\n> hello\n>\n>> older\n\n", Composer::quote("hello\n\n> older\n-- \nJane's sig", 'Jane'));
    eq('Re: Hi', Composer::replySubject('Hi'));
    eq('RE: Hi', Composer::replySubject('RE: Hi'));
});

test('composer: headers are encoded, folded and complete', function () {
    $refs = [];
    for ($i = 0; $i < 40; $i++) {
        $refs[] = "<ref$i.abcdefghij@example.com>";
    }
    $lines = Composer::build('jörg@example.com', 'Jörg Müller', ['de.test', 'local.test'], 'Grüße aus Köln', ".dot line\nok", '<id1@news.example>', $refs, ['de.test'], 0);
    $text = implode("\n", $lines);
    ok(str_contains($text, "From: =?UTF-8?B?" . base64_encode('Jörg Müller') . "?= <jörg@example.com>"), 'From');
    ok(str_contains($text, 'Subject: =?UTF-8?B?'), 'Subject encoded');
    ok(str_contains($text, "Newsgroups: de.test,local.test\n"), 'Newsgroups');
    ok(str_contains($text, "Followup-To: de.test\n"), 'Followup-To');
    ok(str_contains($text, 'Content-Type: text/plain; charset=UTF-8'), 'Content-Type');
    ok(str_contains($text, "Date: Thu, 01 Jan 1970 00:00:00 +0000"), 'Date');
    ok(str_contains($text, '<ref0.abcdefghij@example.com>') && str_contains($text, '<ref39.abcdefghij@example.com>'), 'first and last reference kept');
    ok(!str_contains($text, '<ref1.abcdefghij@example.com>'), 'middle references trimmed');
    foreach ($lines as $l) {
        ok(strlen($l) <= 998, 'line over 998 octets');
    }
    eq(".dot line", $lines[count($lines) - 2], 'body kept as typed (the client dot-stuffs)');
    eq('From: "Doe, Jane" <j@example.com>', Composer::build('j@example.com', 'Doe, Jane', ['de.test'], 's', 'b', '<x@y>')[0], 'name with a comma is quoted');
});

test('composer: validation', function () {
    $status = fn (string $g): ?string => ['de.test' => 'y', 'de.mod' => 'm', 'de.closed' => 'n'][$g] ?? null;
    $v = fn (array $g, string $s = 'Subject', string $b = 'Body', array $f = []) => Composer::validate($g, $f, $s, $b, 3, $status);
    eq([], $v(['de.test']));
    eq([], $v(['de.test', 'de.mod']));
    ok(isset($v([])['newsgroups']));
    ok(isset($v(['de.test', 'de.mod', 'a.b', 'c.d'])['newsgroups']), 'crosspost cap');
    ok(isset($v(['de.closed'])['newsgroups']), 'status n');
    ok(isset($v(['no.such'])['newsgroups']));
    ok(isset($v(['de.test'], '')['subject']));
    ok(isset($v(['de.test'], "a\nb")['subject']), 'newline in subject');
    ok(isset($v(['de.test'], 'S', '')['body']));
    ok(isset($v(['de.test'], 'S', "Jane wrote:\n> only quotes")['body']));
    ok(isset($v(['de.test'], 'S', "bad \xFF")['body']));
    ok(isset($v(['de.test'], 'S', 'B', ['no.such'])['followup_to']));
    eq([], $v(['de.test'], 'S', 'B', ['poster']));
});

test('client: AUTHINFO and POST with dot-stuffing', function () {
    $t = new FakeTransport('200 ready (no posting)', [
        'AUTHINFO USER jane@example.com' => '381 PASS required',
        'AUTHINFO PASS secret pw' => '281 Ok',
        'POST' => '340 Ok',
    ]);
    $c = new Client($t);
    ok($c->authinfo('jane@example.com', 'secret pw'));
    eq('Article received', $c->post(['Subject: x', '', '.starts with a dot', '.']));
    eq(['Subject: x', '', '.starts with a dot', '.'], $t->posted);

    $bad = new Client(new FakeTransport('200 ready', ['AUTHINFO USER *' => '381 PASS required', 'AUTHINFO PASS *' => '481 Authentication failed']));
    ok(!$bad->authinfo('jane@example.com', 'wrong'));
    ok(!(new Client(new FakeTransport('200 ready')))->authinfo('a', "x\r\nPOST"), 'CRLF in password');

    $rejected = new Client(new FakeTransport('200 ready', ['POST' => '340 Ok', '.' => '441 Too many groups']));
    eq(441, throws(fn () => $rejected->post(['a']), \Newsstand\Nntp\NntpException::class)->status);
});

// ---- the app ----------------------------------------------------------------------------------

final class PostingWorld
{
    public App $app;
    public string $var;
    /** @var list<FakeTransport> */
    public array $transports = [];
    public string $password = 'correct horse';
    public string $postReply = '240 Article received';
    public array $cookies = [];

    public function __construct(array $config = [])
    {
        $this->var = tempDir();
        $groups = new GroupCache($this->var . '/cache');
        $groups->store([
            'de.test' => [7, 1, 'y', 'Testing', 0],
            'de.mod' => [1, 1, 'm', 'Moderated', 0],
            'de.closed' => [1, 1, 'n', 'Closed', 0],
        ], time());
        $raw = "From: Jane Doe <jane.doe@example.com>\nNewsgroups: de.test\nSubject: Original\nMessage-ID: <orig.1@example.com>\nReferences: <root.0@example.com>\nDate: Wed, 11 Nov 2026 10:00:00 +0000\n\nHello, write to jane.doe@example.com.\n-- \nsig";
        $over = implode("\t", ['7', 'Original', 'Jane Doe <jane.doe@example.com>', 'Wed, 11 Nov 2026 10:00:00 +0000', '<orig.1@example.com>', '<root.0@example.com>', (string) strlen($raw), '6']);
        $connect = function () use ($raw, $over): Client {
            $t = new FakeTransport('200 ready (no posting)', [
                'MODE READER' => '200 ready (no posting)',
                'GROUP de.test' => '211 7 1 7 de.test',
                'OVER 7' => block('224 ok', $over),
                'OVER 1-7' => block('224 ok', $over),
                'ARTICLE 7' => block('220 7 <orig.1@example.com>', $raw),
                'AUTHINFO USER *' => '381 PASS required',
                'AUTHINFO PASS *' => fn (string $l) => $l === 'AUTHINFO PASS ' . $this->password ? '281 Ok' : '481 Authentication failed',
                'POST' => '340 Ok',
                '.' => fn () => $this->postReply,
                'QUIT' => '205 bye',
            ]);
            $this->transports[] = $t;
            return new Client($t);
        };
        $this->app = new App(Config::fromArray(['var_dir' => $this->var, 'site_url' => 'https://news.example'] + $config), $groups, $connect);
    }

    public function get(string $uri): \Newsstand\Web\Response
    {
        return $this->keep($this->app->handleRequest(new Request('GET', $uri, $this->cookies, [], '192.0.2.7', 'news.example')));
    }

    public function post(string $uri, array $fields, ?string $origin = 'https://news.example', string $ip = '192.0.2.7'): \Newsstand\Web\Response
    {
        return $this->keep($this->app->handleRequest(new Request('POST', $uri, $this->cookies, $fields, $ip, 'news.example', $origin)));
    }

    public function login(string $user = 'me@example.com', ?string $password = null): \Newsstand\Web\Response
    {
        $form = $this->get('/login');
        preg_match('/name="token" value="([^"]+)"/', $form->body, $m);
        return $this->post('/login', ['token' => $m[1], 'next' => '/g/de.test', 'user' => $user, 'password' => $password ?? $this->password]);
    }

    public function csrf(): string
    {
        preg_match('/name="csrf" value="([^"]+)"/', $this->get('/')->body, $m);
        return $m[1] ?? '';
    }

    private function keep(\Newsstand\Web\Response $r): \Newsstand\Web\Response
    {
        foreach ($r->cookies as $name => $c) {
            if ($c['maxAge'] === 0) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $c['value'];
            }
        }
        return $r;
    }
}

test('login: success sets a session cookie, reveals addresses, makes pages private', function () {
    $w = new PostingWorld();
    $anon = $w->get('/g/de.test/7');
    ok(str_contains($anon->body, 'jane…@example.com') && !str_contains($anon->body, 'jane.doe@'), 'anonymous view obfuscated');
    eq('public, max-age=60', $anon->headers['Cache-Control']);

    $r = $w->login();
    eq(303, $r->status);
    eq('/g/de.test', $r->headers['Location']);
    eq('no-store', $r->headers['Cache-Control']);
    ok(isset($w->cookies['__Host-newsstand']), 'session cookie');
    ok(!isset($w->cookies['__Host-newsstand-login']), 'login cookie removed');

    $page = $w->get('/g/de.test/7');
    ok(str_contains($page->body, 'jane.doe@example.com'), 'logged-in view shows the address');
    ok(str_contains($page->body, 'me@example.com') && str_contains($page->body, 'Log out'), 'account bar');
    eq('private, no-cache', $page->headers['Cache-Control']);
    ok(str_contains($page->body, '/g/de.test/7/raw'), 'raw link');
    ok(str_contains($w->get('/g/de.test/7/raw')->body, 'jane.doe@example.com'), 'raw view');
});

test('login: wrong password, expired form, cross-site and non-address logins are refused', function () {
    $w = new PostingWorld();
    eq(422, $w->login('me@example.com', 'wrong')->status);
    ok(!isset($w->cookies['__Host-newsstand']));
    eq(422, $w->login('not-an-address', 'x')->status);
    eq(403, $w->post('/login', ['token' => 'forged', 'user' => 'me@example.com', 'password' => $w->password])->status, 'token without cookie');

    $form = $w->get('/login');
    preg_match('/name="token" value="([^"]+)"/', $form->body, $m);
    eq(403, $w->post('/login', ['token' => $m[1], 'user' => 'me@example.com', 'password' => $w->password], 'https://evil.example')->status, 'cross-site');
    eq('/', (function () use ($w) {
        $form = $w->get('/login');
        preg_match('/name="token" value="([^"]+)"/', $form->body, $m);
        return $w->post('/login', ['token' => $m[1], 'next' => '//evil.example/', 'user' => 'me@example.com', 'password' => $w->password])->headers['Location'];
    })(), 'open redirect');
});

test('login: failures are throttled per IP and per account, before INN is asked', function () {
    $w = new PostingWorld();
    for ($i = 0; $i < 5; $i++) {
        eq(422, $w->login('me@example.com', "wrong$i")->status);
    }
    $asked = count($w->transports);
    $r = $w->login();
    eq(429, $r->status, 'sixth attempt from the same IP');
    ok(isset($r->headers['Retry-After']));
    eq($asked, count($w->transports), 'no connection while throttled');
});

test('posting: login required, reply prefilled, article built, logged and counted', function () {
    $w = new PostingWorld();
    eq(303, $w->get('/post?group=de.test&reply=7')->status, 'anonymous compose redirects');
    $w->login();

    $form = $w->get('/post?group=de.test&reply=7');
    eq(200, $form->status);
    ok(str_contains($form->body, 'Re: Original'), 'subject');
    ok(str_contains($form->body, 'Jane Doe wrote:'), 'attribution');
    ok(str_contains($form->body, '&gt; Hello, write to jane.doe@example.com.'), 'quote');
    ok(!str_contains($form->body, 'sig'), 'signature dropped');

    $fields = ['csrf' => $w->csrf(), 'group' => 'de.test', 'reply' => '7', 'newsgroups' => 'de.test', 'followup_to' => '', 'subject' => 'Re: Original', 'body' => "Jane Doe wrote:\n> Hello\n\nHi Jane!", 'name' => 'Me Myself'];
    $r = $w->post('/post', $fields);
    eq(200, $r->status, strip_tags($r->body));
    ok(str_contains($r->body, 'Your article was sent'));
    $t = end($w->transports);
    ok(in_array('AUTHINFO PASS correct horse', $t->sent, true), 'logged in for the post');
    $article = implode("\n", $t->posted);
    ok(str_contains($article, 'From: Me Myself <me@example.com>'), 'From is the login address');
    ok(str_contains($article, 'References: <root.0@example.com> <orig.1@example.com>'), 'References');
    ok(str_contains($article, "\n\nJane Doe wrote:\n> Hello\n\nHi Jane!"), 'body');
    ok(!preg_match('/NNTP-Posting|X-Forwarded|192\.0\.2\.7/', $article), 'no visitor IP in the article');

    $log = (string) file_get_contents($w->var . '/log/posts.log');
    ok(preg_match("/\tme@example\.com\t192\.0\.2\.7\t<[^>]+@news\.example>\tde\.test\tsending\n/", $log) === 1, "log: $log");
    ok(str_contains($log, "\taccepted\n"), 'result logged');
    eq('Me Myself', (function () use ($w) {
        preg_match('/name="name"[^>]*value="([^"]*)"/', $w->get('/post?group=de.test')->body, $m);
        return $m[1];
    })(), 'display name remembered');
});

test('posting: limits, crosspost cap, rejected posts, CSRF', function () {
    $w = new PostingWorld();
    $w->login();
    $csrf = $w->csrf();
    $fields = ['csrf' => $csrf, 'group' => 'de.test', 'reply' => '', 'newsgroups' => 'de.test', 'followup_to' => '', 'subject' => 'Test', 'body' => 'Body', 'name' => ''];

    eq(403, $w->post('/post', ['csrf' => 'nope'] + $fields)->status, 'bad CSRF');
    eq(403, $w->post('/post', $fields, 'https://evil.example')->status, 'cross-site');
    eq(422, $w->post('/post', ['newsgroups' => 'de.test, de.mod, de.closed, x.y'] + $fields)->status, 'crosspost cap');
    $before = count($w->transports);
    eq(422, $w->post('/post', ['newsgroups' => 'de.closed'] + $fields)->status, 'no-posting group');
    eq($before, count($w->transports), 'validation errors spend no connection');

    $w->postReply = '441 Posting rejected by filter';
    $r = $w->post('/post', $fields);
    eq(422, $r->status);
    ok(str_contains($r->body, 'Posting rejected by filter') && str_contains($r->body, '>Body</textarea>'), 'reason shown, text kept');
    ok(str_contains((string) file_get_contents($w->var . '/log/posts.log'), "rejected: 441 Posting rejected by filter"));

    $w->postReply = '240 ok';
    for ($i = 0; $i < 4; $i++) {
        eq(200, $w->post('/post', $fields)->status, "post $i");
    }
    $r = $w->post('/post', $fields);
    eq(429, $r->status, 'sixth post in 10 minutes (the rejected one counts)');
    ok(str_contains($r->body, '>Body</textarea>'), 'text kept');
});

test('posting: a password INN no longer accepts logs the user out but keeps the text', function () {
    $w = new PostingWorld();
    $w->login();
    $csrf = $w->csrf();
    $w->password = 'changed';
    $r = $w->post('/post', ['csrf' => $csrf, 'group' => 'de.test', 'reply' => '', 'newsgroups' => 'de.test', 'followup_to' => '', 'subject' => 'S', 'body' => 'Keep me', 'name' => '']);
    eq(403, $r->status);
    ok(str_contains($r->body, 'Keep me'));
    ok(!isset($w->cookies['__Host-newsstand']), 'logged out');
    ok(!file_exists($w->var . '/log/posts.log'), 'nothing logged: nothing was sent');
});

test('logout needs the CSRF token; raw view needs a login', function () {
    $w = new PostingWorld();
    eq(303, $w->get('/g/de.test/7/raw')->status);
    $w->login();
    $w->post('/logout', ['csrf' => 'wrong']);
    ok(isset($w->cookies['__Host-newsstand']), 'forged logout ignored');
    $w->post('/logout', ['csrf' => $w->csrf()]);
    ok(!isset($w->cookies['__Host-newsstand']), 'logged out');
    eq(303, $w->get('/g/de.test/7/raw')->status);
    ok(str_contains($w->get('/g/de.test/7')->body, 'jane…@example.com'), 'obfuscated again');
});

test('review fixes: encoded-word spoofing, IPv4-mapped keys, atomic take and release', function () {
    $lines = Composer::build('me@example.com', '=?UTF-8?B?QWRtaW4gPGJvc3NAZXhhbXBsZS5vcmc+?=', ['de.test'], '=?x?Q?y?=', 'b', '<x@y>');
    $headers = \Newsstand\Mime\Headers::parse(implode("
", array_slice($lines, 0, (int) array_search('', $lines, true))));
    $decoded = $headers->decoded('From');
    eq('=?x?Q?y?=', $headers->decoded('Subject'), 'subject decodes to the literal text');
    eq('=?UTF-8?B?QWRtaW4gPGJvc3NAZXhhbXBsZS5vcmc+?= <me@example.com>', $decoded, 'decodes to the literal text');

    eq('ip:192.0.2.1', Throttle::ipKey('::ffff:192.0.2.1'));
    ok(Throttle::ipKey('::ffff:192.0.2.1') !== Throttle::ipKey('::ffff:198.51.100.1'));

    $t = new Throttle(tempDir());
    eq(0, $t->take('k', [60 => 2], 1000));
    eq(0, $t->take('k', [60 => 2], 1001));
    eq(58, $t->take('k', [60 => 2], 1002), 'third take refused');
    eq(2, $t->count('k', 60, 1002), 'a refused take records nothing');
    $t->release('k', 1001);
    eq(0, $t->take('k', [60 => 2], 1002), 'room again after release');
});

test('review fixes: a successful login does not use up the failure budget', function () {
    $w = new PostingWorld();
    for ($i = 0; $i < 8; $i++) {
        $w->cookies = [];
        eq(303, $w->login()->status, "login $i");
    }
});
