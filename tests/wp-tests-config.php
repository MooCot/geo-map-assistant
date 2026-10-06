<?php

/**
 * wp-phpunit's bootstrap.php shells out to install.php as a SEPARATE PHP
 * process (via system()) — constants defined in the parent test process do
 * NOT cross that boundary. This file is the one thing both processes
 * actually share (by path, via WP_TESTS_CONFIG_FILE_PATH), so DB/test
 * constants have to live here, not inline in bootstrap-integration.php.
 *
 * ABSPATH points at the real WordPress core install already present in
 * this container (see docker-compose.yml — the `wordpress` image ships a
 * full WP install at /var/www/html).
 */

define('ABSPATH', '/var/www/html/');

define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'wordpress_test');
define('DB_USER', getenv('WP_TESTS_DB_USER') ?: 'wordpress');
define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') ?: 'wordpress');
define('DB_HOST', getenv('WP_TESTS_DB_HOST') ?: 'db');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'AI Content Assistant Test Suite');
define('WP_PHP_BINARY', 'php');
define('WPLANG', '');
