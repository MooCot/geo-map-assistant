<?php

/**
 * Plugin Name: Geo Map Assistant
 * Plugin URI: https://github.com/MooCot/geo-map-assistant
 * Description: Demo WordPress plugin — background geocoding of post addresses (Nominatim) and a Leaflet map of the results.
 * Version: 1.0.0
 * Requires PHP: 8.2
 * Requires at least: 6.0
 * Author: Vyacheslav Yur
 * License: MIT
 * Text Domain: geo-map-assistant
 */

declare(strict_types=1);

namespace GMA;

if (! defined('ABSPATH')) {
    exit;
}

define('GMA_VERSION', '1.0.0');
define('GMA_PLUGIN_FILE', __FILE__);
define('GMA_PLUGIN_DIR', __DIR__);
define('GMA_PLUGIN_URL', plugin_dir_url(__FILE__));

$autoload = GMA_PLUGIN_DIR . '/vendor/autoload.php';

if (! file_exists($autoload)) {
    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p>Geo Map Assistant: run <code>composer install</code> in the plugin directory first.</p></div>';
    });

    return;
}

require_once $autoload;

use GMA\Admin\DashboardPage;
use GMA\Admin\PostMetaBox;
use GMA\Admin\SettingsPage;
use GMA\Admin\SettingsSanitizer;
use GMA\Cli\SeedCommand;
use GMA\Map\MapShortcode;
use GMA\Ajax\GeocodeHandler;
use GMA\Ajax\QueueHandler;
use GMA\Cron\ProcessingJob;
use GMA\Geocoder\GeocoderManager;
use GMA\Geocoder\MockGeocoder;
use GMA\Geocoder\NominatimGeocoder;
use GMA\Geocoder\RequestThrottle;
use GMA\Geocoder\WpCircuitBreaker;
use GMA\Geocoder\WpOptionThrottleStore;
use GMA\Repository\GeoJobRepository;
use GuzzleHttp\Client;

// Registered at file level, not in plugins_loaded: wp_schedule_event() runs
// during activation, when the plugin is not loaded yet, and an unknown
// interval makes it silently schedule nothing.
add_filter('cron_schedules', [ProcessingJob::class, 'addMinuteSchedule']);

register_activation_hook(GMA_PLUGIN_FILE, [GeoJobRepository::class, 'installTable']);
register_activation_hook(GMA_PLUGIN_FILE, [ProcessingJob::class, 'schedule']);
register_deactivation_hook(GMA_PLUGIN_FILE, [ProcessingJob::class, 'unschedule']);

add_action('plugins_loaded', function (): void {
    $repository = new GeoJobRepository();

    $throttle = new RequestThrottle(
        new WpOptionThrottleStore(),
        static fn (): float => microtime(true),
        static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        },
    );

    $geocoders = get_option('gma_geocoder', 'mock') === 'nominatim'
        ? [new NominatimGeocoder(new Client(), SettingsSanitizer::userAgent(get_option('gma_user_agent', null)))]
        : [new MockGeocoder()];

    $manager = new GeocoderManager($geocoders, $throttle, new WpCircuitBreaker());
    $processing = new ProcessingJob($repository, $manager);

    $processing->register();
    (new PostMetaBox($repository))->register();
    (new QueueHandler($repository, $processing))->register();
    (new GeocodeHandler($repository, $processing))->register();
    (new DashboardPage($repository))->register();
    (new SettingsPage())->register();
    (new MapShortcode($repository))->register();
});

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('gma seed', SeedCommand::class);
}
