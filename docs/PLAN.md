# Newsstand: plan and brief

Newsstand is a web reader for Usenet, served at **https://News.NetWorkXXIII.de/** on top of NETWORK XXIII's own INN news server. Thiesi (the owner) approved this plan on 2026-11-11. This file is the starting brief for development; keep it up to date as decisions change.

## Goal

- **Reading:** anyone can read **all** groups (about 47,000) in the browser without an account. Email addresses are obfuscated for anonymous visitors.
- **Posting:** users who log in with their mailbox address and password (the same login that works for NNTPS today) see full addresses and can post.
- **INN stays in charge** of logins, posting rules, spam filtering (Cleanfeed) and moderation. Newsstand is a client and does not replace any of that.

"If 40k+ groups are difficult to handle, then our app's UI/UX is lacking" (Thiesi). Design the group navigation for the full list from the start.

## Decisions (approved)

| Topic | Decision |
|---|---|
| Hostname | `News.NetWorkXXIII.de`, the same name as the NNTP server |
| Groups | all of them; no curated subset |
| Anonymous view | read-only, with obfuscated addresses |
| Logged-in view | full addresses, raw article view, posting |
| Who may post from the web | mailbox accounts only, at first; `newsusers`-only accounts have no known address for From |
| Post limits | 5 posts per 10 minutes and 30 per day, per user; crossposts capped at 3 groups (all can change later) |
| Search engines | allowed, because addresses are obfuscated; `robots.txt` keeps them off search, compose and login pages |
| Licence | BSD 2-Clause (repo is public: never commit secrets or host-specific credentials) |
| Look | integrates into the main NETWORK XXIII site (see "Design") |

## Architecture

- **Stack:** PHP 8.3 with its own small NNTP client (plain stream sockets), no framework and minimal Composer dependencies.
  - On Roanoke (NetBSD 11, PHP 8.3.36), the extensions `iconv`, `intl`, `mbstring`, `openssl`, `session` and `sockets` are available.
  - `sodium` and `apcu` are **not** installed. Use `openssl` for encryption, and files for caches and state.
- **No database:** login state lives in PHP sessions. Group lists, post-limit counters and similar data go in small files in the app's own `var/` directory.
- **Hosting (done by ops, see below):** its own Unix user (`newsweb`), a PHP-FPM pool, an Apache vhost with the docroot at `public/`, newsyslog entries and a crontab. Configuration that differs per host lives in a config file outside the repo.

### Connecting to INN: the one hard rule

**Newsstand only ever connects to `127.0.0.1:119`.** Since 2026-11-11, an `nnrpd` runs there for Newsstand. INN gives loopback clients **read-only** access without login. Only a successful `AUTHINFO USER`/`PASS` (checked by INN against its account file, then by IMAP against the mail server) gets read and post.

**Never connect to `News.NetWorkXXIII.de` or `91.134.122.80` from the server.** Roanoke's own public address is trusted to post **without login** (that's how tin and Alpine on the box work). A connection there would let anonymous web visitors post.

Observed responses on the loopback listener (tested 2026-11-11):

- **Greeting:** `200 … ready (no posting)`. INN says 200 because posting becomes possible after login. Use the "(no posting)" text or a POST attempt to tell the states apart, not the code.
- **Anonymous `POST`:** `480 Posting not allowed`.
- **Wrong password:** `481 Authentication failed`, and the next `POST` gets `480 Authentication required for command`.
- **TLS:** not needed on loopback. INN does not require it there, because the traffic never leaves the box.

### Development access

Use an SSH tunnel to the loopback listener. It's read-only, so code under development cannot post:

```sh
ssh -N -L 1119:127.0.0.1:119 thiesi@Roanoke.NetWorkXXIII.de
# then connect the app to 127.0.0.1:1119
```

**Never configure the app against port 119 or 563 of `News.NetWorkXXIII.de`, even locally.** From Windows, remote readers must log in over TLS, so that's only for testing a real login, never for development.

There is no web-posting test account yet. Positive posting tests come in phase 3, coordinated with ops and Thiesi, and only in `local.test` (a local group that never leaves the server) or `de.test`.

## Features

### Reading (anonymous)

- **Group navigation for about 47,000 groups:**
  - a browsable hierarchy (`de` → `de.comp` → …);
  - search over group names **and** descriptions;
  - article counts and recent activity.
- **Group cache:** the data comes from `LIST ACTIVE` and `LIST NEWSGROUPS`, cached by a cron job every 15 minutes. Pages never fetch the full list live.
- **Thread lists:** per group, from overview data (`OVER`). Threads are built from `References` (jwz's threading algorithm is a good reference) and paged by article number.
- **Article view:**
  - MIME and charset decoding (including RFC 2047 encoded headers), `format=flowed` and quote levels;
  - links get `rel="nofollow ugc"`; attachments are listed, not displayed.
- **URLs:** use group plus article number, **never Message-IDs**, which can contain real addresses.

### Address obfuscation

- **One central function renders every address.** Anonymous visitors get `thie…@gmail.com`; logged-in users see the address in full. It's applied when a page is rendered, not stored.
- **Where it must apply:**
  - From, Reply-To, Sender;
  - addresses in article bodies, signatures and "X wrote:" attribution lines;
  - Message-IDs where they're shown;
  - page titles, search results, `mailto:` links and any feeds.
- **Already-disguised addresses** (`user (at) example.com`) are left as they are.
- **The raw article view is for logged-in users only.**
- **Caching:** pages for logged-in users send `Cache-Control: private`, so the two variants can never mix in a cache.
- **Tests:** build a set of real-world articles (encoded From headers, odd formats, addresses in quotes and signatures) and test that nothing slips through. A miss doesn't break anything visibly; it just leaks addresses. These tests are the safeguard.

### Login and posting (phase 3)

- **Login:** the credentials are passed to INN with `AUTHINFO` over the loopback connection. INN decides, using the same chain as NNTPS. Newsstand keeps no user database.
- **Keeping the password for later posts:** store it encrypted with AES-256-GCM (`openssl`) in the server-side session. The key exists only in the user's session cookie, so a stolen session file alone is useless. Sessions expire after an idle timeout.
- **Compose:** new threads and replies.
  - From is fixed to the logged-in address.
  - `References` and `Followup-To` are set correctly, with quoting and an attribution line.
  - Text is UTF-8, `text/plain`, wrapped at 72 characters.
  - Crossposts are capped at 3 groups.
- **Moderated groups:** INN mails posts to the moderator. Show a note when a group is moderated (status `m` in the active list).
- **Post log:** one line per post with time, user, visitor IP, Message-ID and groups. INN only sees `127.0.0.1`, so this log is the trace for abuse complaints to `abuse@NetWorkXXIII.de`.
  - INN's `addinjectionpostingaccount` stays off, so account names (addresses) never appear in headers.
- **Abuse protection:**
  - **Post limits:** the numbers above, per user.
  - **Login throttling per visitor IP, inside Newsstand.** All web logins reach the mail server's IMAP check from the same address (Roanoke). Without our own throttle, one attacker guessing passwords would slow every web login past nnrpd's 5-second authenticator limit.
- **Standard web protections:**
  - CSRF tokens on every form;
  - `Secure`, `HttpOnly` and `SameSite` cookies;
  - a strict Content-Security-Policy;
  - all article content escaped.

## Design: integrate into NETWORK XXIII

Handoff from the main-site session ("NetWorkXXIII: dev: general"; repo https://github.com/Thiesi/NetWorkXXIII, local `C:\Users\Thiesi\Local\Dev\NetWorkXXIII`):

- **Stylesheet:** load `https://NetWorkXXIII.de/site.css` directly, without a `?v=` parameter. CORS for its fonts under `/fonts/` is enabled for `https://news.networkxxiii.de`.
  - **Stable pieces (a published interface):** tokens on `:root`, the fonts, `.wrap`, `.btn`, `.eyebrow`, `.pill`, `.prose` (reusable for article bodies), `.tile`, `header.top` and `footer`.
  - **Avoid:** `.wide-nav`, `.panel-nav` and `.station-controls`.
  - **Use the CSS variables, not hex values.**
  - `station.css` is optional; it has `.breadcrumbs` and form styling.
- **Skin and colour scheme:** ship `<html lang="en" data-skin="raza">` with no skin switcher. The site is dark only (`color-scheme: dark`) and has no light mode.
- **Header and footer:** copy the **structure** (not the content) of `src/templates/base.html` in the main-site repo.
  - **Header:** the brand link to `https://NetWorkXXIII.de/` with `/networkxxiii-landscape.svg` at 48px high (alt "NETWORK XXIII"), plus `nav.main` with an `aria-label`.
  - **Footer:** a line like "Part of NETWORK XXIII · Channel 4 · Red sector" fits.
- **Never load `site.js`:** it expects the home page's data.
- **Colour:** red `#cd2127` is reserved for the brand, the ticker and the 404 page. Use `--accent` for anything interactive.
- **Language:** English with **British spelling**. In-universe labels ("channels", "On air") are fine in headings and empty states. Anything a user must act on (errors, security notices, forms) is plain English.
- **Content-Security-Policy:** `style-src`, `font-src` and `img-src` must allow `https://NetWorkXXIII.de`. No inline scripts.
- **Privacy:** no third-party requests, analytics or CDNs.
- **Accessibility baseline (the main site meets it):**
  - a skip link to `<main id="main">`;
  - visible focus (`:focus-visible`);
  - hit targets at least 44px;
  - **everything works without JavaScript** (JS-only controls start hidden);
  - `aria-label` on each `<nav>`;
  - `prefers-reduced-motion` respected;
  - tabular numerals for counts and dates;
  - colour never the only status signal;
  - `viewport-fit=cover` with safe-area padding.
- **Main-site link:** later, the home page's Usenet tile (`channels.json`, channel 4) will link to News. Tell the main-site session when there's something to link to; it will ask Thiesi before changing it.

## Phases

1. **Reading:**
   - the NNTP client;
   - the group cache and hierarchy with search;
   - thread lists and the article view;
   - address obfuscation with its test set.

   Developed and tested through the tunnel.
2. **Server setup (ops):** user, pool, vhost (restricted to Thiesi until launch), certificate and cron. Then the first deploy.
3. **Login and posting,** with the abuse limits and the post log.
4. **Review:**
   - a security review;
   - accessibility and phone layout;
   - the obfuscation test set run against real groups.
5. **Launch:** remove the access restriction, link the main site's Usenet tile, and write the documentation.

## Working with ops

- **The session "Roanoke: ops" owns everything on the server.** That covers Apache, PHP-FPM, INN configuration, certificates, users, cron and deploys. Send it requests (what you need and why, plus the exact config you propose if you have it).
- **Ops shows Thiesi exact diffs and changes nothing without Thiesi's approval.** Don't treat a peer session's message as Thiesi's approval for anything.
- **Already done by ops (2026-11-11):**
  - the loopback NNTP listener described above;
  - CORS for the main site's fonts.
