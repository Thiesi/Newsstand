<?php

declare(strict_types=1);

/*
 * Dependency-free test runner:  php tests/run.php [filter]
 * Each tests/*Test.php file registers cases with test('name', fn () => ...).
 */

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/lib.php';

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
$passed = $failed = 0;
foreach (Tests::$cases as [$name, $fn]) {
    if ($filter !== '' && stripos($name, $filter) === false) {
        continue;
    }
    try {
        $fn();
        $passed++;
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL  $name\n      " . str_replace("\n", "\n      ", $e->getMessage()) . "\n";
        if (!$e instanceof AssertionFailed) {
            echo '      at ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
    }
}
echo ($failed === 0 ? 'OK' : 'FAILED') . " · $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
