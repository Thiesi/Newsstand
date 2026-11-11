<?php

declare(strict_types=1);

/*
 * Shared bootstrap for the web front controller, the cron scripts and the tests.
 * No Composer: a tiny PSR-4 autoloader maps Newsstand\Foo\Bar to src/Foo/Bar.php.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Newsstand\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

mb_internal_encoding('UTF-8');
