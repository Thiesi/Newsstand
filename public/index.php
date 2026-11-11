<?php

declare(strict_types=1);

use Newsstand\Config;
use Newsstand\Groups\GroupCache;
use Newsstand\Web\App;

// Development server (php -S 127.0.0.1:8080 -t public public/index.php): serve static files directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __FILE__ && is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

$config = Config::load();
$app = new App($config, new GroupCache($config->string('var_dir') . '/cache'));
$response = $app->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
$response->send();
