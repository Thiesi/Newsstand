<?php

declare(strict_types=1);

namespace Newsstand\Web;

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
use Newsstand\Privacy\Obfuscator;
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
    private readonly View $view;
    private readonly Obfuscator $obfuscator;
    private readonly ResponseCache $cache;
    private readonly ConnectionBudget $budget;

    /** @param (\Closure(): Client)|null $connect for tests; defaults to the configured loopback listener */
    public function __construct(
        private readonly Config $config,
        private readonly GroupCache $groups,
        ?\Closure $connect = null,
        ?Obfuscator $obfuscator = null,
    ) {
        $this->connect = $connect ?? static fn (): Client => Client::connect($config);
        // Phase 1 has no logins: every visitor is anonymous.
        $this->obfuscator = $obfuscator ?? Obfuscator::anonymous();
        $this->view = new View(dirname(__DIR__, 2) . '/templates', $this->obfuscator);
        $var = $config->string('var_dir');
        $this->cache = new ResponseCache($var . '/nntp-cache');
        $this->budget = new ConnectionBudget($var . '/nntp-budget', $config->int('nntp_budget_per_minute'), $config->int('nntp_budget_burst'));
    }

    public function handle(string $method, string $uri): Response
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $this->withHeaders(new Response(405, 'Method not allowed', ['Allow' => 'GET, HEAD', 'Content-Type' => 'text/plain; charset=utf-8']));
        }
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        try {
            $response = match (true) {
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
        return $this->withHeaders($response);
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
        [$data, $staleAge] = $this->cached("article\0$group\0$number", $this->config->int('cache_fresh_article'), function () use ($group, $number): array {
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
        if (isset($data['missing'])) {
            return $this->error(404, 'No such article', 'That article is not (or no longer) on this server.');
        }
        if (isset($data['huge'])) {
            return $this->error(413, 'Article too large', 'This article is too large to show here.');
        }

        $article = Article::fromRaw((string) $data['raw']);
        $subject = $article->headers->decoded('Subject') ?? '(no subject)';
        return $this->page('article', [
            'title' => $subject . ' · ' . $group,
            'description' => "Article in $group",
            'group' => $group,
            'number' => $number,
            'article' => $article,
            'subject' => $subject,
            'tooLarge' => (bool) $data['tooLarge'],
            'bytes' => (int) $data['bytes'],
            'moderated' => ($this->groups->group($group)[GroupCache::STATUS] ?? '') === 'm',
            'staleAge' => $staleAge,
        ]);
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
        $vars += ['siteUrl' => $this->config->string('site_url'), 'mainSite' => $this->config->string('main_site_url')];
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
            // Anonymous pages are identical for everyone. Phase 3 sends "private" for logged-in users.
            'Cache-Control' => $this->obfuscator->reveals() ? 'private, no-cache' : 'public, max-age=60',
            'Vary' => 'Cookie',
        ];
        return $r;
    }
}
