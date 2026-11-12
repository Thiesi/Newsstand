<?php

declare(strict_types=1);

namespace Newsstand\Web;

use Newsstand\Auth\Session;
use Newsstand\Auth\SessionStore;
use Newsstand\Auth\Throttle;
use Newsstand\Config;
use Newsstand\Groups\GroupCache;
use Newsstand\Mime\Article;
use Newsstand\Nntp\BudgetExhausted;
use Newsstand\Nntp\Client;
use Newsstand\Nntp\ConnectionBudget;
use Newsstand\Nntp\GroupName;
use Newsstand\Nntp\NntpException;
use Newsstand\Nntp\OverviewEntry;
use Newsstand\Nntp\ResponseCache;
use Newsstand\Post\Composer;
use Newsstand\Post\PostLog;
use Newsstand\Privacy\Obfuscator;
use Newsstand\Text\Charset;
use Newsstand\Text\Mailbox;
use Newsstand\Threads\Threader;

/**
 * Front controller. URLs use group name plus article number, never Message-IDs (which can contain
 * real addresses):
 *
 *   /                 start page: search, largest hierarchies, most active groups
 *   /browse           all top-level hierarchies (A–Z index)
 *   /browse/de.comp   one level of the hierarchy
 *   /search?q=        search names and descriptions
 *   /g/de.test        thread list, newest first, paged with ?before=<article number>
 *   /g/de.test/123    one article
 *   /g/de.test/123/raw  the article as sent (logged-in users only)
 *   /login, /logout   AUTHINFO login against INN (POST /logout)
 *   /post?group=g[&reply=n]  compose a new thread or a reply (logged-in users only)
 *
 * Logged-in users see full addresses and get "Cache-Control: private"; anonymous pages are identical
 * for everyone and publicly cacheable.
 *
 * NNTP use is rationed (see Nntp\ConnectionBudget): at most one connection per page, thread lists and
 * articles come from Nntp\ResponseCache while fresh, groups missing from the group cache get a 404
 * without connecting, and when the budget is empty a stale copy or a 503 with Retry-After is served.
 */
final class App
{
    /** @var \Closure(): Client */
    private \Closure $connect;
    private ?Client $client = null;
    private View $view;
    private Obfuscator $obfuscator;
    private readonly ResponseCache $cache;
    private readonly ConnectionBudget $budget;
    private readonly SessionStore $sessions;
    private readonly Throttle $throttle;
    private readonly PostLog $postLog;
    private ?Session $session = null;
    private ?string $sessionCookie = null;
    private Request $request;

    /** @param (\Closure(): Client)|null $connect for tests; defaults to the configured loopback listener */
    public function __construct(
        private readonly Config $config,
        private readonly GroupCache $groups,
        ?\Closure $connect = null,
    ) {
        $this->connect = $connect ?? static fn (): Client => Client::connect($config);
        $this->obfuscator = Obfuscator::anonymous();
        $this->view = new View(dirname(__DIR__, 2) . '/templates', $this->obfuscator);
        $this->request = new Request('GET', '/');
        $var = $config->string('var_dir');
        $this->cache = new ResponseCache($var . '/nntp-cache');
        $this->budget = new ConnectionBudget($var . '/nntp-budget', $config->int('nntp_budget_per_minute'), $config->int('nntp_budget_burst'));
        $sessionDir = $config->string('session_dir');
        $this->sessions = new SessionStore($sessionDir !== '' ? $sessionDir : $var . '/sessions', $config->int('session_idle'), $config->int('session_lifetime'));
        $this->throttle = new Throttle($var . '/throttle');
        $this->postLog = new PostLog($var . '/log/posts.log');
    }

    /** Cookies are Secure (and use the __Host- prefix) when the site is served over HTTPS. */
    public function secureCookies(): bool
    {
        return str_starts_with(strtolower($this->config->string('site_url')), 'https:');
    }

    public function sessionCookieName(): string
    {
        return $this->secureCookies() ? '__Host-newsstand' : 'newsstand';
    }

    private function loginCookieName(): string
    {
        return $this->secureCookies() ? '__Host-newsstand-login' : 'newsstand-login';
    }

    /** Shorthand for anonymous GET requests (tests, benchmarks). */
    public function handle(string $method, string $uri): Response
    {
        return $this->handleRequest(new Request($method, $uri));
    }

    public function handleRequest(Request $request): Response
    {
        $this->request = $request;
        $this->sessionCookie = $request->cookie($this->sessionCookieName());
        $this->session = $this->sessionCookie !== null ? $this->sessions->load($this->sessionCookie) : null;
        $this->obfuscator = new Obfuscator($this->session !== null);
        $this->view = new View(dirname(__DIR__, 2) . '/templates', $this->obfuscator);

        $method = $request->method;
        $path = rawurldecode((string) parse_url($request->uri, PHP_URL_PATH));
        parse_str((string) parse_url($request->uri, PHP_URL_QUERY), $query);
        $q = static fn (string $k): string => is_string($query[$k] ?? null) ? $query[$k] : '';

        $posts = ['/login', '/logout', '/post'];
        if ($method === 'POST' && !in_array($path, $posts, true)) {
            return $this->withHeaders(new Response(405, 'Method not allowed', ['Allow' => 'GET, HEAD', 'Content-Type' => 'text/plain; charset=utf-8']));
        }
        if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'POST') {
            return $this->withHeaders(new Response(405, 'Method not allowed', ['Allow' => 'GET, HEAD, POST', 'Content-Type' => 'text/plain; charset=utf-8']));
        }

        try {
            $response = match (true) {
                $method === 'POST' && $path === '/login' => $this->loginSubmit($q('next')),
                $method === 'POST' && $path === '/logout' => $this->logout(),
                $method === 'POST' && $path === '/post' => $this->postSubmit(),
                $path === '/login' => $this->loginForm($q('next')),
                $path === '/post' => $this->compose($q('group'), $q('reply')),
                (bool) preg_match('#^/g/([^/]+)/(\d{1,15})/raw$#D', $path, $m) => $this->raw($m[1], (int) $m[2]),
                $path === '/' => $this->home(),
                $path === '/browse' => $this->browse('', $query),
                (bool) preg_match('#^/browse/([^/]+)$#D', $path, $m) => $this->browse($m[1], $query),
                $path === '/search' => $this->search(is_string($query['q'] ?? null) ? $query['q'] : ''),
                (bool) preg_match('#^/g/([^/]+)$#D', $path, $m) => $this->group($m[1], $query['before'] ?? null),
                (bool) preg_match('#^/g/([^/]+)/(\d{1,15})$#D', $path, $m) => $this->article($m[1], (int) $m[2]),
                default => $this->error(404, 'Not found', 'There is nothing on this frequency.'),
            };
        } catch (BudgetExhausted $e) {
            $response = $this->error(503, 'Busy right now', 'A lot of people are reading at the moment. Please try again in a minute.');
            $response->headers['Retry-After'] = (string) max(1, $e->retryAfter);
        } catch (NntpException $e) {
            error_log('Newsstand NNTP error: ' . $e->getMessage());
            $response = $this->error(503, 'News server unavailable', 'The news server is not answering right now. Please try again in a minute.');
            $response->headers['Retry-After'] = '60';
        } finally {
            $this->client?->quit();
            $this->client = null;
        }
        if ($this->sessionCookie !== null && $this->session === null && !isset($response->cookies[$this->sessionCookieName()])) {
            $response->deleteCookie($this->sessionCookieName()); // expired or unknown session
        }
        return $this->withHeaders($response);
    }

    // ---- login -------------------------------------------------------------------------------

    private function loginForm(string $next, ?string $error = null, int $status = 200, string $user = ''): Response
    {
        if ($this->session !== null && $error === null) {
            return Response::redirect(self::safeNext($next), 303);
        }
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $r = $this->page('login', [
            'title' => 'Log in',
            'description' => 'Log in with your mailbox address to see full addresses and post.',
            'next' => self::safeNext($next),
            'token' => $token,
            'error' => $error,
            'user' => $user,
        ], $status);
        $r->headers['Cache-Control'] = 'no-store';
        $r->headers['X-Robots-Tag'] = 'noindex';
        return $r->setCookie($this->loginCookieName(), $token, 3600);
    }

    private function loginSubmit(string $next): Response
    {
        $req = $this->request;
        $next = $req->field('next') !== '' ? $req->field('next') : $next;
        $user = trim($req->field('user'));
        $password = $req->field('password');
        $cookie = $req->cookie($this->loginCookieName());
        $token = $req->field('token');
        if (!$req->sameOrigin() || $cookie === null || $token === '' || !hash_equals($cookie, $token)) {
            return $this->loginForm($next, 'The login form had expired. Please try again.', 403, $user);
        }
        if (strlen($user) > 254 || filter_var($user, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false) {
            return $this->loginForm($next, 'Log in with your full mailbox address, like name@example.com.', 422, $user);
        }
        if ($password === '' || strlen($password) > 1024 || preg_match('/[\r\n\0]/', $password)) {
            return $this->loginForm($next, 'That address and password did not work.', 422, $user);
        }

        $window = $this->config->int('login_failure_window');
        $keys = [
            Throttle::ipKey($req->ip) => $this->config->int('login_failures_per_ip'),
            'account:' . strtolower($user) => $this->config->int('login_failures_per_account'),
        ];
        $wait = 0;
        foreach ($keys as $key => $limit) {
            $wait = max($wait, $this->throttle->retryAfter('login-fail:' . $key, $window, $limit));
        }
        if ($wait > 0) {
            $r = $this->loginForm($next, 'Too many failed logins. Please wait ' . self::minutes($wait) . ' before trying again.', 429, $user);
            $r->headers['Retry-After'] = (string) $wait;
            return $r;
        }

        try {
            $ok = $this->client()->authinfo($user, $password);
        } catch (BudgetExhausted $e) {
            $r = $this->loginForm($next, 'The news server is busy right now. Please try again in a minute.', 503, $user);
            $r->headers['Retry-After'] = (string) max(1, $e->retryAfter);
            return $r;
        } catch (NntpException $e) {
            error_log('Newsstand login: NNTP error: ' . $e->getMessage());
            return $this->loginForm($next, 'The news server is not answering right now. Please try again in a minute.', 503, $user);
        }
        if (!$ok) {
            foreach (array_keys($keys) as $key) {
                $this->throttle->add('login-fail:' . $key);
            }
            return $this->loginForm($next, 'That address and password did not work.', 422, $user);
        }

        $this->throttle->clear('login-fail:account:' . strtolower($user));
        [$cookieValue] = $this->sessions->create($user, $password);
        return Response::redirect(self::safeNext($next), 303)
            ->setCookie($this->sessionCookieName(), $cookieValue)
            ->deleteCookie($this->loginCookieName());
    }

    private function logout(): Response
    {
        $r = Response::redirect('/', 303);
        if ($this->session !== null && $this->request->sameOrigin() && $this->session->checkCsrf($this->request->field('csrf'))) {
            $this->sessions->destroy($this->sessionCookie);
            $this->session = null;
            $r->deleteCookie($this->sessionCookieName());
        }
        return $r;
    }

    private function loginRedirect(): Response
    {
        return Response::redirect('/login?next=' . rawurlencode($this->request->uri), 303);
    }

    /** Only local paths: "/x", never "//host" or "/\host". */
    private static function safeNext(string $next): string
    {
        return preg_match('#^/(?![/\\\\])[^\x00-\x20\x7F]*$#D', $next) ? $next : '/';
    }

    private static function minutes(int $seconds): string
    {
        $m = (int) ceil($seconds / 60);
        return $m === 1 ? 'a minute' : "$m minutes";
    }

    // ---- posting -----------------------------------------------------------------------------

    private function groupStatus(string $name): ?string
    {
        if (!$this->groups->exists()) {
            return GroupName::isValid($name) ? 'y' : null; // no cache yet: let INN decide
        }
        $record = $this->groups->group($name);
        return $record === null ? null : (string) $record[GroupCache::STATUS];
    }

    private function compose(string $group, string $reply): Response
    {
        if ($this->session === null) {
            return $this->loginRedirect();
        }
        if (!$this->groupMayExist($group)) {
            return $this->error(404, 'No such group', 'There is no newsgroup by that name on this server.');
        }
        $form = ['group' => $group, 'reply' => '', 'newsgroups' => $group, 'followup_to' => '', 'subject' => '', 'body' => "\n", 'name' => $this->session->name];
        $notes = [];
        if ($reply !== '') {
            if (!ctype_digit($reply) || strlen($reply) > 15) {
                return $this->error(404, 'No such article', 'That article is not (or no longer) on this server.');
            }
            $original = $this->replyContext($group, (int) $reply);
            if ($original instanceof Response) {
                return $original;
            }
            $form = array_merge($form, ['reply' => $reply, 'newsgroups' => implode(', ', $original['groups']), 'subject' => $original['subject'], 'body' => $original['quote']]);
            if ($original['poster']) {
                $notes[] = 'The author asked for replies by mail. Newsstand cannot send mail; this reply goes to the newsgroup.';
            }
        }
        return $this->composePage($form, [], $notes);
    }

    private function postSubmit(): Response
    {
        $req = $this->request;
        if ($this->session === null) {
            return $this->loginForm('/post?group=' . rawurlencode($req->field('group')), 'You were logged out, so nothing was posted. Please log in again.', 403);
        }
        $form = [];
        foreach (['group', 'reply', 'newsgroups', 'followup_to', 'subject', 'body', 'name'] as $f) {
            $form[$f] = $req->field($f);
        }
        $form['name'] = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', mb_scrub($form['name'], 'UTF-8')) ?? ''), 0, 64);
        if (!$req->sameOrigin() || !$this->session->checkCsrf($req->field('csrf'))) {
            return $this->composePage($form, ['form' => 'The form had expired, so nothing was posted. Please send it again.'], [], 403);
        }
        if ($form['name'] !== $this->session->name && $this->sessionCookie !== null) {
            $this->session->name = $form['name'];
            $this->sessions->save($this->sessionCookie, $this->session);
        }

        $groups = Composer::parseGroups($form['newsgroups']);
        $followup = Composer::parseGroups($form['followup_to']);
        $errors = Composer::validate($groups, $followup, $form['subject'], $form['body'], $this->config->int('max_crosspost'), $this->groupStatus(...));
        $references = [];
        if ($form['reply'] !== '' && !isset($errors['newsgroups'])) {
            $original = (ctype_digit($form['reply']) && strlen($form['reply']) <= 15 && GroupName::isValid($form['group']))
                ? $this->replyContext($form['group'], (int) $form['reply']) : null;
            if (!is_array($original)) {
                $errors['form'] = 'The article you are replying to is no longer available, so nothing was posted.';
            } else {
                $references = $original['references'];
            }
        }
        if ($errors !== []) {
            return $this->composePage($form, $errors, [], 422);
        }

        $user = strtolower($this->session->user);
        $limits = [600 => $this->config->int('posts_per_10_minutes'), 86400 => $this->config->int('posts_per_day')];
        foreach ($limits as $window => $limit) {
            $wait = $this->throttle->retryAfter('post:' . $user, $window, $limit);
            if ($wait > 0) {
                $r = $this->composePage($form, ['form' => 'You have reached the posting limit (' . ($window === 600 ? "$limit posts per 10 minutes" : "$limit posts per day") . '). Please wait ' . self::minutes($wait) . '; your text is still here.'], [], 429);
                $r->headers['Retry-After'] = (string) $wait;
                return $r;
            }
        }

        $host = (string) parse_url($this->config->string('site_url'), PHP_URL_HOST);
        $messageId = Composer::messageId($host !== '' ? $host : 'localhost');
        $lines = Composer::build($this->session->user, $form['name'], $groups, $form['subject'], $form['body'], $messageId, $references, $followup);

        try {
            $client = $this->client();
            if (!$client->authinfo($this->session->user, $this->session->password())) {
                $this->sessions->destroy($this->sessionCookie);
                $this->session = null;
                $r = $this->composePage($form, ['form' => 'The news server no longer accepts your password, so nothing was posted. Copy your text, then log in again.'], [], 403);
                return $r->deleteCookie($this->sessionCookieName());
            }
            // Written before sending: a post must never reach INN without a trace in the log.
            $this->postLog->record($this->session->user, $req->ip, $messageId, $groups, 'sending');
            $this->throttle->add('post:' . $user);
            try {
                $client->post($lines);
            } catch (NntpException $e) {
                $this->logResult($messageId, $groups, 'rejected: ' . $e->getMessage());
                if ($e->status >= 400 && $e->status < 500) {
                    return $this->composePage($form, ['form' => 'The news server did not accept the post: ' . self::serverText($e->getMessage())], [], 422);
                }
                throw $e;
            }
            $this->logResult($messageId, $groups, 'accepted');
        } catch (BudgetExhausted $e) {
            $r = $this->composePage($form, ['form' => 'The news server is busy right now. Please send again in a minute; your text is still here.'], [], 503);
            $r->headers['Retry-After'] = (string) max(1, $e->retryAfter);
            return $r;
        } catch (NntpException $e) {
            error_log('Newsstand post: NNTP error: ' . $e->getMessage());
            return $this->composePage($form, ['form' => 'The news server is not answering right now, so the post may not have been sent. Please check the group in a minute before sending again.'], [], 503);
        } catch (\RuntimeException $e) {
            error_log('Newsstand post: ' . $e->getMessage());
            return $this->composePage($form, ['form' => 'Posting is not possible right now because of a problem on our side. Nothing was posted.'], [], 500);
        }

        $moderated = array_values(array_filter($groups, fn (string $g): bool => $this->groupStatus($g) === 'm'));
        $r = $this->page('posted', [
            'title' => 'Posted',
            'description' => 'Your article was sent.',
            'groups' => $groups,
            'moderated' => $moderated,
        ]);
        $r->headers['Cache-Control'] = 'no-store';
        return $r;
    }

    /**
     * Logs the outcome after the "sending" line is safely written. A failure here must not turn an
     * accepted post into an error page (the user would post again), so it only goes to the error log.
     *
     * @param list<string> $groups
     */
    private function logResult(string $messageId, array $groups, string $result): void
    {
        try {
            $this->postLog->record((string) $this->session?->user, $this->request->ip, $messageId, $groups, $result);
        } catch (\RuntimeException $e) {
            error_log("Newsstand post log: {$e->getMessage()} ($messageId: $result)");
        }
    }
    /** The server's reason without the status code, e.g. "441 Too many groups" → "Too many groups". */
    private static function serverText(string $message): string
    {
        $text = trim(preg_replace('/^\d{3}\s*/', '', $message) ?? '');
        return $text !== '' ? mb_substr(mb_scrub($text, 'UTF-8'), 0, 300) : 'no reason given.';
    }

    /**
     * What a reply needs from the original article.
     *
     * @return array{groups: list<string>, poster: bool, subject: string, quote: string, references: list<string>}|Response
     */
    private function replyContext(string $group, int $number): array|Response
    {
        if (!$this->groupMayExist($group)) {
            return $this->error(404, 'No such group', 'There is no newsgroup by that name on this server.');
        }
        [$data] = $this->articleData($group, $number);
        if (isset($data['missing']) || isset($data['huge']) || ($data['tooLarge'] ?? false)) {
            return $this->error(404, 'No such article', 'That article is not (or no longer) available for replies.');
        }
        $article = Article::fromRaw((string) $data['raw']);
        $hd = $article->headers;
        $followup = Composer::parseGroups((string) $hd->get('Followup-To'));
        $poster = $followup === ['poster'];
        $groups = ($followup !== [] && !$poster) ? $followup : Composer::parseGroups((string) $hd->get('Newsgroups'));
        $groups = array_values(array_filter($groups, [GroupName::class, 'isValid']));
        if ($groups === []) {
            $groups = [$group];
        }
        $references = Composer::parseReferences((string) $hd->get('References'));
        $own = Composer::parseReferences((string) $hd->get('Message-ID'));
        if ($own !== []) {
            $references[] = $own[0];
        }
        $from = Mailbox::parse((string) $hd->decoded('From'));
        return [
            'groups' => $groups,
            'poster' => $poster,
            'subject' => Composer::replySubject($hd->decoded('Subject') ?? ''),
            'quote' => Composer::quote($article->text, $from->label()),
            'references' => array_values(array_unique($references)),
        ];
    }

    /**
     * @param array<string, string> $form
     * @param array<string, string> $errors
     * @param list<string> $notes
     */
    private function composePage(array $form, array $errors, array $notes, int $status = 200): Response
    {
        $groups = Composer::parseGroups($form['newsgroups'] ?? '');
        $moderated = array_values(array_filter($groups, fn (string $g): bool => GroupName::isValid($g) && $this->groupStatus($g) === 'm'));
        $r = $this->page('compose', [
            'title' => ($form['reply'] ?? '') !== '' ? 'Reply' : 'New thread',
            'description' => 'Write a Usenet article.',
            'form' => $form,
            'errors' => $errors,
            'notes' => $notes,
            'moderated' => $moderated,
            'maxGroups' => $this->config->int('max_crosspost'),
        ], $status);
        $r->headers['Cache-Control'] = 'no-store';
        $r->headers['X-Robots-Tag'] = 'noindex';
        return $r;
    }

    private function raw(string $group, int $number): Response
    {
        if ($this->session === null) {
            return $this->loginRedirect();
        }
        if (!$this->groupMayExist($group)) {
            return $this->error(404, 'No such group', 'There is no newsgroup by that name on this server.');
        }
        [$data] = $this->articleData($group, $number);
        if (isset($data['missing'])) {
            return $this->error(404, 'No such article', 'That article is not (or no longer) on this server.');
        }
        if (isset($data['huge'])) {
            return $this->error(413, 'Article too large', 'This article is too large to show here.');
        }
        $text = Charset::toUtf8((string) $data['raw'], null);
        if ($data['tooLarge'] ?? false) {
            $text .= "\n\n[The body is not shown: the article is too large.]\n";
        }
        return new Response(200, $text, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Robots-Tag' => 'noindex']);
    }

    private function home(): Response
    {
        return $this->page('home', [
            'title' => 'Usenet',
            'description' => 'Read Usenet in your browser: all newsgroups on the NETWORK XXIII news server.',
            'built' => $this->groups->exists(),
            'meta' => $this->groups->meta(),
            'top' => LevelPager::largest($this->groups->children(''), 30),
            'topCount' => count($this->groups->children('')),
            'active' => $this->groups->mostActive(12),
            'cache' => $this->groups,
        ]);
    }

    /** @param array<string, mixed> $query */
    private function browse(string $prefix, array $query): Response
    {
        if ($prefix === '' && !$this->groups->exists()) {
            return Response::redirect('/', 302);
        }
        if ($prefix !== '' && (!GroupName::isValid($prefix) || !$this->groups->hasNode($prefix))) {
            if (GroupName::isValid($prefix) && $this->groups->group($prefix) !== null) {
                return Response::redirect(Html::groupUrl($prefix), 302);
            }
            return $this->error(404, 'No such hierarchy', 'There is no hierarchy by that name.');
        }
        return $this->page('browse', [
            'title' => $prefix === '' ? 'All hierarchies' : $prefix . '.*',
            'description' => $prefix === '' ? 'All Usenet hierarchies on this server.' : "Newsgroups in the $prefix hierarchy.",
            'prefix' => $prefix,
            'pager' => new LevelPager($this->groups->children($prefix), $query['letter'] ?? null, $query['page'] ?? null),
            'self' => $prefix === '' ? null : $this->groups->group($prefix),
            'cache' => $this->groups,
        ]);
    }

    private function search(string $q): Response
    {
        $q = mb_substr(trim($q), 0, 100);
        $found = $q === '' ? ['total' => 0, 'results' => []] : $this->groups->search($q);
        return $this->page('search', [
            'title' => $q === '' ? 'Search groups' : "Search: $q",
            'description' => 'Search newsgroup names and descriptions.',
            'q' => $q,
            'total' => $found['total'],
            'results' => $found['results'],
            'cache' => $this->groups,
            'built' => $this->groups->exists(),
        ]);
    }

    private function group(string $name, mixed $before): Response
    {
        if (!$this->groupMayExist($name)) {
            return $this->error(404, 'No such group', 'There is no newsgroup by that name on this server.');
        }
        $before = (is_string($before) && ctype_digit($before) && strlen($before) <= 15) ? (int) $before : null;
        $perPage = max(10, $this->config->int('articles_per_page'));

        [$data, $staleAge] = $this->cached("over\0$name\0$before", $this->config->int('cache_fresh_overview'), function () use ($name, $before, $perPage): array {
            try {
                $info = $this->client()->group($name);
            } catch (NntpException $e) {
                if ($e->isNoSuchGroup()) {
                    return ['missing' => true];
                }
                throw $e;
            }
            $end = $before === null ? $info['high'] : min($info['high'], $before - 1);
            $start = max($info['low'], $end - $perPage + 1);
            $entries = ($info['count'] > 0 && $end >= $start) ? $this->client()->over($start, $end) : [];
            return ['info' => $info, 'start' => $start, 'end' => $end, 'entries' => array_map(static fn ($e) => $e->toArray(), $entries)];
        });
        if (isset($data['missing'])) {
            return $this->error(404, 'No such group', 'There is no newsgroup by that name on this server.');
        }

        $info = $data['info'];
        return $this->page('group', [
            'title' => $name,
            'description' => $this->groups->group($name)[GroupCache::DESC] ?? "Newsgroup $name",
            'name' => $name,
            'info' => $info,
            'cached' => $this->groups->group($name),
            'canPost' => in_array($this->groupStatus($name), ['y', 'm'], true),
            'threads' => Threader::build(array_map([OverviewEntry::class, 'fromArray'], $data['entries'])),
            'older' => $data['start'] > $info['low'] ? $data['start'] : null,
            'newer' => $data['end'] < $info['high'] ? min($info['high'] + 1, $data['end'] + $perPage + 1) : null,
            'staleAge' => $staleAge,
        ]);
    }

    private function article(string $group, int $number): Response
    {
        if (!$this->groupMayExist($group)) {
            return $this->error(404, 'No such group', 'There is no newsgroup by that name on this server.');
        }
        [$data, $staleAge] = $this->articleData($group, $number);
        if (isset($data['missing'])) {
            return $this->error(404, 'No such article', 'That article is not (or no longer) on this server.');
        }
        if (isset($data['huge'])) {
            return $this->error(413, 'Article too large', 'This article is too large to show here.');
        }

        $article = Article::fromRaw((string) $data['raw']);
        $subject = $article->headers->decoded('Subject') ?? '(no subject)';
        $status = $this->groupStatus($group);
        return $this->page('article', [
            'title' => $subject . ' · ' . $group,
            'description' => "Article in $group",
            'group' => $group,
            'number' => $number,
            'article' => $article,
            'subject' => $subject,
            'tooLarge' => (bool) $data['tooLarge'],
            'bytes' => (int) $data['bytes'],
            'moderated' => $status === 'm',
            'canPost' => in_array($status, ['y', 'm'], true),
            'staleAge' => $staleAge,
        ]);
    }

    /**
     * The raw article (or only its head when too large), cached. Shared by the article page, the raw
     * view and replies.
     *
     * @return array{0: array<mixed>, 1: int|null}
     */
    private function articleData(string $group, int $number): array
    {
        return $this->cached("article\0$group\0$number", $this->config->int('cache_fresh_article'), function () use ($group, $number): array {
            $client = $this->client();
            try {
                $client->group($group);
                $over = $client->over($number, $number);
                if ($over === []) {
                    return ['missing' => true];
                }
                $max = $this->config->int('max_article_bytes');
                $tooLarge = $over[0]->bytes > $max;
                $raw = $tooLarge ? $client->head($number) : $client->article($number, $max * 2);
                return ['raw' => $raw, 'bytes' => $over[0]->bytes, 'tooLarge' => $tooLarge];
            } catch (NntpException $e) {
                if ($e->isNoSuchGroup() || $e->isNoSuchArticle()) {
                    return ['missing' => true];
                }
                if ($e->status === -1) {
                    $this->client = null; // unfinished response: drop the connection instead of QUIT
                    return ['huge' => true];
                }
                throw $e;
            }
        });
    }

    /**
     * Valid name, and listed in the group cache when there is one. Unknown groups get a 404 without
     * spending an NNTP connection (groups created since the last refresh appear within 15 minutes).
     */
    private function groupMayExist(string $name): bool
    {
        return GroupName::isValid($name) && (!$this->groups->exists() || $this->groups->group($name) !== null);
    }

    /**
     * Returns a cached result while fresh; otherwise fetches it (spending at most one connection per page).
     * If fetching is impossible (budget empty, server down), a stale copy is served when there is one.
     * "Not found" results stay fresh for at most a minute.
     *
     * @param \Closure(): array<mixed> $fetch
     * @return array{0: array<mixed>, 1: int|null} the data, and its age when served stale
     */
    private function cached(string $key, int $fresh, \Closure $fetch): array
    {
        $hit = $this->cache->get($key);
        if ($hit !== null && $hit[1] <= (isset($hit[0]['missing']) ? min($fresh, 60) : $fresh)) {
            return [$hit[0], null];
        }
        try {
            $value = $fetch();
        } catch (BudgetExhausted | NntpException $e) {
            if ($hit !== null && !isset($hit[0]['missing'])) {
                if ($e instanceof NntpException) {
                    error_log('Newsstand NNTP error, serving a cached copy: ' . $e->getMessage());
                }
                return [$hit[0], $hit[1]];
            }
            throw $e;
        }
        $this->cache->put($key, $value);
        return [$value, null];
    }

    /** One connection per page, and only when the shared budget allows it. */
    private function client(): Client
    {
        if ($this->client === null) {
            if (!$this->budget->take()) {
                throw new BudgetExhausted($this->budget->secondsUntilToken());
            }
            $this->client = ($this->connect)();
        }
        return $this->client;
    }

    /** @param array<string, mixed> $vars */
    private function page(string $template, array $vars, int $status = 200): Response
    {
        $vars += [
            'siteUrl' => $this->config->string('site_url'),
            'mainSite' => $this->config->string('main_site_url'),
            // The layout shows "Log in" or the user with a log-out button.
            'currentUser' => $this->session?->user,
            'csrf' => $this->session?->csrf,
            'loginNext' => self::safeNext((string) parse_url($this->request->uri, PHP_URL_PATH)),
        ];
        $headers = ['Content-Type' => 'text/html; charset=utf-8'];
        if (isset($vars['staleAge'])) {
            $headers['Cache-Control'] = 'public, max-age=10'; // a saved copy: let caches retry soon
        }
        return new Response($status, $this->view->page($template, $vars), $headers);
    }

    private function error(int $status, string $title, string $message): Response
    {
        $r = $this->page('error', ['title' => $title, 'description' => $title, 'message' => $message, 'status' => $status], $status);
        $r->headers['Cache-Control'] = 'no-store';
        return $r;
    }

    private function withHeaders(Response $r): Response
    {
        $main = rtrim($this->config->string('main_site_url'), '/');
        $r->headers += [
            'Content-Security-Policy' => "default-src 'none'; style-src 'self' $main; font-src $main; img-src 'self' $main; "
                . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'DENY',
            // Anonymous pages are identical for everyone and may be cached publicly.
            'Cache-Control' => 'public, max-age=60',
            'Vary' => 'Cookie',
        ];
        // Never let a shared cache keep a logged-in page (full addresses) or a response that sets a cookie.
        $cc = $r->headers['Cache-Control'];
        if ($r->cookies !== [] || $this->request->method === 'POST') {
            $r->headers['Cache-Control'] = 'no-store';
        } elseif (($this->session !== null || $this->obfuscator->reveals()) && !str_contains($cc, 'no-store')) {
            $r->headers['Cache-Control'] = 'private, no-cache';
        }
        return $r;
    }
}
