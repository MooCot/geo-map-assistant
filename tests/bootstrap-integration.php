<?php

declare(strict_types=1);

$testsDir = getenv('WP_PHPUNIT__DIR') ?: dirname(__DIR__) . '/vendor/wp-phpunit/wp-phpunit';

if (! file_exists("{$testsDir}/includes/functions.php")) {
    fwrite(STDERR, "Could not find the WP core test suite at {$testsDir}. Did you run `composer install`?\n");
    exit(1);
}

define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php');

require_once "{$testsDir}/includes/functions.php";

tests_add_filter('muplugins_loaded', function (): void {
    require dirname(__DIR__) . '/geo-map-assistant.php';
});

require "{$testsDir}/includes/bootstrap.php";
