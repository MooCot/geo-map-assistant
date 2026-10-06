# Geo Map Assistant

A demo WordPress plugin: post addresses are geocoded in a background queue, and the results are shown on a Leaflet map.

It is a portfolio project. It shows how I structure a WordPress plugin: a custom admin dashboard, WP-Cron background processing, a repository over a custom table with atomic claims and retries, an external API behind an interface with a rate limit and a circuit breaker, and a Dockerised test setup with unit and integration suites.

## Features

- **Address field on every post.** The address is saved to post meta. Saving it queues a geocoding job.
- **Background geocoding.** A WP-Cron batch runs every minute. Each job is claimed atomically, retried with backoff on transient errors, and stops at a terminal state.
- **Admin dashboard.** Status cards, a jobs table that refreshes every five seconds, "Queue all", "Process batch now", and manual retry for failed jobs.
- **Map.** The `[gma_map]` shortcode renders a Leaflet map with a marker per geocoded, published post. The home page of the demo shows the map.
- **Settings.** Geocoder (mock or Nominatim), User-Agent, tile server URL, default map centre and zoom.
- **Demo content.** `wp gma seed` creates six demo posts with addresses and a map page, then geocodes them.

## Quick start

```bash
git clone https://github.com/MooCot/geo-map-assistant.git
cd geo-map-assistant
docker compose up -d --build
```

A one-shot `composer` service installs the dependencies into `vendor/` first, and WordPress waits for it.

The first start takes a minute or so: MySQL initialises, WordPress is installed, the plugin is activated, and the demo content is seeded. When the `wp-cli` container exits with code 0, the setup is complete.

| What | Where |
|---|---|
| Home page (the map) | http://localhost:8081/ |
| Admin | http://localhost:8081/wp-admin/ (`admin` / `admin`) |
| Dashboard | Admin menu → **Geo Map** |
| Settings | Admin menu → **Geo Map** → **Settings** |

The demo runs on the mock geocoder by default. It makes no network calls and returns deterministic coordinates inside Ukraine. Switch to Nominatim in the settings when you want real geocoding.

## Commands

| Task | Command |
|---|---|
| Seed demo content again (idempotent) | `docker compose run --rm wp-cli "wp gma seed --path=/var/www/html --allow-root"` |
| Unit tests | `docker compose exec -w /var/www/html/wp-content/plugins/geo-map-assistant wordpress php vendor/bin/phpunit -c phpunit.unit.xml` |
| Integration tests | `docker compose exec -w /var/www/html/wp-content/plugins/geo-map-assistant wordpress php vendor/bin/phpunit -c phpunit.integration.xml` |
| Fresh start (drops the database) | `docker compose down -v && docker compose up -d --build` |

Unit tests need no WordPress. Integration tests run inside the `wordpress` container against a real MySQL database (`wordpress_test`, created by `docker/init-test-db.sql`).

## Architecture

```
geo-map-assistant.php            composition root: wiring, activation hooks, WP-CLI command
includes/
  Geocoder/                      GeocoderInterface, Nominatim and Mock implementations,
                                 GeocoderManager (fallback, circuit breaker, rate limit),
                                 RequestThrottle, RetryPolicy, error classes,
                                 WP-backed circuit breaker and throttle store
  Repository/GeoJobRepository    custom table: atomic claims, leases, fencing tokens
  Cron/ProcessingJob             batch processing; maps geocoder outcomes onto job states
  Ajax/                          queue actions, "geocode now"
  Admin/                         dashboard, settings, post meta box, sanitizer
  Map/MapShortcode               [gma_map] shortcode
  Seeder/DemoSeeder              idempotent demo content
  Cli/SeedCommand                wp gma seed
assets/                          Leaflet 1.9.4 (vendored), map.js, map.css, dashboard.js, admin.css
tests/Unit, tests/Integration    the two suites
docker/init-test-db.sql          second database for the integration suite
```

### How geocoding stays correct

The tests cover these rules.

- **Errors are classified.** A "not found" answer is terminal and is not retried. A bad request is terminal. Timeouts, 5xx and 429 are retried with backoff (1 minute, then 2) up to three attempts, and a `Retry-After` header is honoured. A blocked provider (403) is systemic: it does not consume the job's attempt, and the batch stops.
- **Claims use a lease and a fencing token.** A worker claims a job for five minutes. Its result is written only if its token still matches, so a slow worker whose lease expired cannot overwrite the work of the worker that took over.
- **Attempts are counted when a job is claimed.** A job that crashes its worker on every run still reaches the limit and cannot loop forever.
- **The rate limit is enforced per provider.** Nominatim allows one request per second, so the throttle waits at least 1.1 s between calls. The throttle state lives in `wp_options`, so the limit holds across separate PHP requests (cron and manual runs).
- **Idempotent registration.** One job per post (`UNIQUE KEY post_id`). Saving an unchanged address does nothing. Changing it resets the job and clears any in-flight claim.

### WP-Cron in Docker

WordPress's built-in pseudo-cron is unreliable in a local container, so `DISABLE_WP_CRON` is set and a `wp-cron` sidecar calls `wp-cron.php` every minute. `WP_AUTO_UPDATE_CORE` is disabled: once cron really runs, WordPress would otherwise update its own core and break the pinned version.

## Test stack

- PHP 8.2, WordPress 6.6 (`wordpress:6.6-php8.2-apache`), MySQL 8.0.
- `wp-phpunit` pinned to the WordPress minor version of the image, PHPUnit `^9.6`, and `doctrine/instantiator` pinned to `2.0.0`. Each pin is there because a newer version broke the suite on PHP 8.2.

## Limitations

- The map uses the default centre and zoom from settings and shows one map per page. A second `[gma_map]` on the same page overwrites the first map's data.
- AJAX endpoints (queue, batch, retry, geocode-now) are not covered by automated tests. They were checked by hand against the live stack.
- The browser side (the dashboard polling and the map popups) is checked by hand, not by automated end-to-end tests.
- The mock geocoder returns made-up coordinates. Use Nominatim to see real locations, and respect its usage policy.

## Licence

MIT. See `LICENSE`.
