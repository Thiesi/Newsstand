# Newsstand

A web reader for Usenet, written for NETWORK XXIII's own INN news server at
[News.NetWorkXXIII.de](https://News.NetWorkXXIII.de/).

- **Reading (anonymous):** all newsgroups on the server (about 47,000). A browsable hierarchy and a
  search over group names and descriptions. Thread lists built from `References`. Articles with MIME,
  charset and `format=flowed` decoding. Email addresses are partly hidden (`jona…@example.com`).
- **Logged in:** log in with a mailbox address and password, checked by INN with `AUTHINFO`. You then
  see full addresses and a raw article view, and you can start threads and reply.
- **INN stays in charge** of logins, posting rules, spam filtering and moderation. Newsstand is only a
  client.

The plan and the decisions behind it are in [docs/PLAN.md](docs/PLAN.md).

## Requirements

- PHP 8.3 or later with `mbstring`, `iconv` and `openssl`. `intl` is optional. No framework, no
  Composer and no database.
- An NNTP server on the **loopback interface**. The client refuses any other host. Newsstand must never
  talk to an address that is trusted to post without login.
- A web server that sends every request that isn't a file in `public/` to `public/index.php`. For
  Apache: `DocumentRoot …/public` and `FallbackResource /index.php`.

## Setup

1. Copy `config.example.php` to a path outside the checkout and adjust it. Point the `NEWSSTAND_CONFIG`
   environment variable at it, both in the PHP-FPM pool and in the crontab.
   For development, `config.local.php` in the project root is used instead (ignored by git).
2. Make `var_dir` (and `session_dir`, if set) writable by the web user only.
3. Build the group cache, and refresh it every 15 minutes from cron:

   ```sh
   */15 * * * * php /path/to/newsstand/bin/refresh-groups.php
   ```

   It is quiet unless something fails. Use `-v` for timings. It also prunes the article cache, expired
   sessions and old throttle counters.

## Development

```sh
php -S 127.0.0.1:8080 -t public public/index.php   # dev server
php tests/run.php                                  # tests (no network)
php bin/bench-cache.php                            # page timings against the group cache
php bin/scan-leaks.php --per=8 --groups=12         # address-hiding check against real groups
```

Set `site_url` to `http://127.0.0.1:8080` in `config.local.php`, so login cookies work without HTTPS.

The tests use a scripted fake NNTP server and never connect anywhere. `bin/scan-leaks.php` connects to
the configured server: it is rationed to about 10 connections a minute.

## How it works

- **No page loads the full group list.** The cron job splits it into one small JSON file per hierarchy
  level, plus a flat search index. Pages are fast without OPcache or APCu.
- **NNTP is rationed.** Each page opens at most one connection, and thread lists and articles are cached
  in files. A shared token bucket keeps new connections below the server's limit. When the bucket is
  empty, visitors get a saved copy or a 503 with `Retry-After`.
- **One function hides addresses** (`src/Privacy/Obfuscator.php`). Templates must pass article-derived
  text through it. `tests/ObfuscationTest.php` renders real-world-style articles through the real pages
  and searches the HTML with an independent leak detector. When an address slips through, add the
  article (with example addresses) to `tests/fixtures/articles/`.
- **Sessions keep the password only in encrypted form.** The cookie is `<id>.<key>`. The server stores
  the password encrypted with AES-256-GCM under a key that only the cookie has.
- **Abuse limits:**
  - failed logins are throttled per IP and per account before INN is asked;
  - each user may post 5 times per 10 minutes and 30 times per day, to at most 3 groups;
  - every post is logged (time, user, visitor IP, Message-ID, groups), since INN only sees `127.0.0.1`.
- **Web protections:** a strict Content-Security-Policy with no JavaScript, CSRF tokens plus an Origin
  check on every form, and `__Host-` cookies. Logged-in pages are never publicly cacheable.

## Licence

BSD 2-Clause, see [LICENSE](LICENSE).
