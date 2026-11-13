<?php

declare(strict_types=1);

namespace Newsstand\Ops;

use Newsstand\Config;
use Newsstand\Groups\GroupCache;
use Newsstand\Nntp\Client;
use Newsstand\Nntp\NntpException;
use Newsstand\Nntp\SocketTransport;

/**
 * Checks what Newsstand expects from the host, for use after changes on the server (bin/check-server.php).
 *
 * local() needs no network. nntp() uses one connection and never posts: it sends POST without logging in
 * and expects a refusal. If the server answers 340 instead, the connection is dropped before any article
 * text is sent, and an unfinished article is discarded by INN. It never sends AUTHINFO, so it cannot trip
 * login limits or touch the mail server.
 */
final class ServerCheck
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** Group cache older than this (seconds) means cron has probably stopped; cron runs every 15 minutes. */
    private const CACHE_STALE = 2700;

    /** @var list<array{0: string, 1: string}> */
    private array $results = [];

    public function __construct(private readonly Config $config, private readonly int $inetdLimit = 50)
    {
    }

    /** @return list<array{0: string, 1: string}> [status, message] in the order the checks ran */
    public function results(): array
    {
        return $this->results;
    }

    public function worst(): string
    {
        $statuses = array_column($this->results, 0);
        return in_array(self::FAIL, $statuses, true) ? self::FAIL : (in_array(self::WARN, $statuses, true) ? self::WARN : self::OK);
    }

    /** PHP, configuration and files. */
    public function local(?int $now = null): void
    {
        $now ??= time();

        // PHP (this is the CLI; the FPM pool may differ, but on Roanoke both are php83 from the same package).
        if (PHP_VERSION_ID >= 80300) {
            $this->add(self::OK, 'PHP ' . PHP_VERSION);
        } else {
            $this->add(self::FAIL, 'PHP ' . PHP_VERSION . ' is too old: Newsstand needs 8.3 or later');
        }
        $missing = array_values(array_filter(['mbstring', 'iconv', 'openssl'], static fn (string $e) => !extension_loaded($e)));
        $this->add($missing === [] ? self::OK : self::FAIL, $missing === []
            ? 'PHP extensions mbstring, iconv and openssl are loaded'
            : 'PHP extension(s) missing: ' . implode(', ', $missing));
        if (extension_loaded('openssl')) {
            $key = random_bytes(32);
            $iv = random_bytes(12);
            $ct = @openssl_encrypt('check', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'aad');
            $pt = $ct === false ? false : @openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'aad');
            $this->add($pt === 'check' ? self::OK : self::FAIL, $pt === 'check'
                ? 'AES-256-GCM works (session encryption)'
                : 'AES-256-GCM does not work in openssl: logins cannot keep the password');
        }

        // Configuration.
        $env = getenv('NEWSSTAND_CONFIG');
        $this->add($env ? self::OK : self::WARN, $env
            ? "Configuration from NEWSSTAND_CONFIG ($env)"
            : 'NEWSSTAND_CONFIG is not set: using config.local.php (fine for development only)');
        $host = $this->config->string('nntp_host');
        $port = $this->config->int('nntp_port');
        if (in_array($host, SocketTransport::ALLOWED_HOSTS, true)) {
            $this->add(self::OK, "News server is $host:$port (loopback)");
        } else {
            $this->add(self::FAIL, "nntp_host is $host: it must be the loopback interface");
        }
        $perMinute = $this->config->int('nntp_budget_per_minute');
        $burst = $this->config->int('nntp_budget_burst');
        $peak = $perMinute + $burst;
        if ($peak > $this->inetdLimit * 0.8) {
            $this->add(self::WARN, "Connection budget allows $peak new connections in 60 s ($perMinute/min + burst $burst): too close to the listener's limit of {$this->inetdLimit}. Lower it, or pass --inetd-limit if the limit was raised");
        } else {
            $this->add(self::OK, "Connection budget: at most $peak new connections in 60 s (listener limit {$this->inetdLimit})");
        }

        // Files.
        $var = $this->config->string('var_dir');
        if (!is_dir($var)) {
            $this->add(self::FAIL, "var_dir $var does not exist");
        } else {
            $this->checkDir($var, 'var_dir');
            $sessionDir = $this->config->string('session_dir');
            $dirs = ['cache' => 'group cache', 'nntp-cache' => 'article cache', 'throttle' => 'limit counters', 'log' => 'post log'];
            if ($sessionDir === '') {
                $dirs['sessions'] = 'sessions';
            }
            foreach ($dirs as $sub => $what) {
                if (is_dir("$var/$sub")) {
                    $this->checkDir("$var/$sub", $what);
                }
            }
            if ($sessionDir !== '') {
                is_dir($sessionDir) ? $this->checkDir($sessionDir, 'sessions') : $this->add(self::FAIL, "session_dir $sessionDir does not exist");
            }
            $log = "$var/log/posts.log";
            if (is_file($log)) {
                $this->add(is_writable($log) ? self::OK : self::FAIL, is_writable($log)
                    ? "$log (post log) is writable"
                    : "$log (post log) is not writable: posting will fail");
            } elseif (is_writable(is_dir("$var/log") ? "$var/log" : $var)) {
                $this->add(self::OK, "$log (post log) does not exist yet; it is created with the first post");
            }
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->add(self::WARN, 'Running as root: file checks only mean something when run as the web user');
        }

        // Group cache, rebuilt by cron.
        $meta = (new GroupCache($var . '/cache'))->meta();
        if ($meta === null) {
            $this->add(self::FAIL, 'No group cache: run bin/refresh-groups.php (and check the crontab)');
        } else {
            $age = $now - (int) $meta['built'];
            $this->add($age > self::CACHE_STALE ? self::WARN : self::OK, sprintf(
                '%d groups cached, built %d minutes ago%s',
                $meta['count'],
                intdiv(max(0, $age), 60),
                $age > self::CACHE_STALE ? ': cron should rebuild it every 15 minutes; is it running?' : '',
            ));
        }
    }

    /**
     * The news server, over one connection. $group is a group with articles to read; when null, the most
     * active group from the cache is used.
     */
    public function nntp(Client $client, ?string $group = null): void
    {
        // 1. Read-only until login: the greeting and an anonymous POST.
        if ($client->postingAdvertised()) {
            $this->add(self::FAIL, 'The news server offers posting without login (its greeting lacks "(no posting)")');
        } else {
            $this->add(self::OK, 'Greeting says "(no posting)" until login');
        }
        try {
            [$code, $text] = $client->command('POST');
        } catch (NntpException $e) {
            $this->add(self::FAIL, 'Anonymous POST check failed: ' . $e->getMessage());
            $client->abandon();
            return;
        }
        if ($code === 340) {
            $client->abandon(); // send nothing more: no article is created
            $this->add(self::FAIL, 'DANGER: the news server accepts POST without login (340). Anonymous web visitors could post. Nothing was posted; fix INN before Newsstand serves anyone');
            return;
        }
        if ($code === 480 || $code === 440) {
            $this->add(self::OK, "Anonymous POST is refused ($code $text)");
        } else {
            $this->add(self::WARN, "Anonymous POST got an unexpected answer: $code $text");
        }

        // 2. The commands pages use.
        try {
            $group ??= $this->pickGroup();
            if ($group === null) {
                $this->add(self::WARN, 'No group with articles found to test reading; pass --group');
                $client->quit();
                return;
            }
            $active = iterator_to_array($client->listActive($group), false);
            if ($active === []) {
                $this->add(self::FAIL, "LIST ACTIVE does not know $group");
                $client->quit();
                return;
            }
            $this->add(self::OK, "LIST ACTIVE works ($group, status {$active[0]['status']})");
            $descriptions = iterator_to_array($client->listNewsgroups($group));
            $this->add(self::OK, 'LIST NEWSGROUPS works' . (isset($descriptions[$group]) ? '' : " (no description for $group)"));

            $info = $client->group($group);
            if ($info['count'] === 0 || $info['high'] < $info['low']) {
                $this->add(self::WARN, "$group is empty: cannot test OVER and ARTICLE; pass --group");
                $client->quit();
                return;
            }
            $entries = $client->over(max($info['low'], $info['high'] - 9), $info['high']);
            if ($entries === []) {
                $this->add(self::FAIL, "OVER returned nothing for the newest articles in $group: thread lists need overview data");
                $client->quit();
                return;
            }
            $this->add(self::OK, sprintf('OVER works (%d entries from %s)', count($entries), $group));

            $article = null;
            foreach (array_reverse($entries) as $entry) {
                try {
                    $article = $client->article($entry->number, $this->config->int('max_article_bytes'));
                    break;
                } catch (NntpException $e) {
                    if ($e->getCode() !== 423 && $e->getCode() !== 430) {
                        throw $e;
                    }
                }
            }
            if ($article === null) {
                $this->add(self::FAIL, "ARTICLE failed for all of the newest articles in $group");
            } elseif (!str_contains($article, "\n\n") || !preg_match('/^Message-ID:/mi', $article)) {
                $this->add(self::FAIL, 'ARTICLE returned something that does not look like an article');
            } else {
                $this->add(self::OK, 'ARTICLE works (' . strlen($article) . ' bytes)');
            }
            $client->quit();
        } catch (NntpException $e) {
            $this->add(self::FAIL, 'Reading failed: ' . $e->getMessage());
            $client->abandon();
        }
    }

    private function pickGroup(): ?string
    {
        $meta = (new GroupCache($this->config->string('var_dir') . '/cache'))->meta();
        foreach ($meta['active'] ?? [] as [$name]) {
            return $name;
        }
        return null;
    }

    private function checkDir(string $dir, string $what): void
    {
        if (!is_writable($dir)) {
            $this->add(self::FAIL, "$dir ($what) is not writable");
            return;
        }
        if (DIRECTORY_SEPARATOR === '/' && (fileperms($dir) & 0007) !== 0) {
            $this->add(self::WARN, sprintf('%s (%s) is open to other users (mode %o): it holds sessions, caches or visitor IPs', $dir, $what, fileperms($dir) & 0777));
            return;
        }
        $this->add(self::OK, "$dir ($what) is writable");
    }

    public function add(string $status, string $message): void
    {
        $this->results[] = [$status, $message];
    }
}
