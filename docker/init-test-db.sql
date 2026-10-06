-- Runs automatically via MySQL's /docker-entrypoint-initdb.d/ mechanism on
-- the FIRST ever start of the db service (empty db_data volume only).
--
-- The `wordpress` user (created by the official mysql image from
-- MYSQL_USER/MYSQL_DATABASE in docker-compose.yml) only gets privileges on
-- the `wordpress` database — it has no CREATE DATABASE privilege, so it
-- can't create `wordpress_test` for itself. wp-phpunit's bootstrap (see
-- tests/wp-tests-config.php) needs that second database to exist with the
-- same user able to read/write it, or `composer test:integration` fails
-- with `db_select_fail` on a genuinely fresh clone.
CREATE DATABASE IF NOT EXISTS wordpress_test;
GRANT ALL PRIVILEGES ON wordpress_test.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
