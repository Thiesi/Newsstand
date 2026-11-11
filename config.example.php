<?php

/*
 * Copy to a path outside the repository and point NEWSSTAND_CONFIG at it (on the server), or copy to
 * config.local.php in the project root (development; ignored by git).
 *
 * nntp_host must be the loopback interface: the client refuses anything else. Never point Newsstand at
 * News.NetWorkXXIII.de or its public address, which is trusted to post without login.
 * Development: tunnel with  ssh -N -L 1119:127.0.0.1:119 thiesi@Roanoke.NetWorkXXIII.de  and use port 1119.
 */

return [
    'nntp_host' => '127.0.0.1',
    'nntp_port' => 119,
    'nntp_timeout' => 15,
    'var_dir' => __DIR__ . '/var',
    'site_url' => 'https://News.NetWorkXXIII.de',
    'main_site_url' => 'https://NetWorkXXIII.de',
    'articles_per_page' => 100,
    'max_article_bytes' => 1048576,
    // New NNTP connections: at most burst + per_minute can start in any 60 seconds. inetd closes the
    // loopback listener for ten minutes when its spawn limit is exceeded, so keep the sum well below it.
    'nntp_budget_per_minute' => 25,
    'nntp_budget_burst' => 10,
    'cache_fresh_overview' => 60,
    'cache_fresh_article' => 3600,
    'cache_max_age' => 86400,
];
