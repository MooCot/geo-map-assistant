<?php

declare(strict_types=1);

namespace GMA\Admin;

/**
 * Plugin settings: geocoder, its User-Agent, the tile server, and the map's
 * default centre and zoom. Every value passes through SettingsSanitizer on save.
 */
final class SettingsPage
{
    public const GROUP = 'gma_settings';
    public const SLUG = 'geo-map-assistant-settings';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addMenuPage(): void
    {
        add_submenu_page(
            DashboardPage::SLUG,
            'Geo Map Settings',
            'Settings',
            'manage_options',
            self::SLUG,
            [$this, 'render']
        );
    }

    public function registerSettings(): void
    {
        register_setting(self::GROUP, 'gma_geocoder', ['sanitize_callback' => [SettingsSanitizer::class, 'geocoder']]);
        register_setting(self::GROUP, 'gma_user_agent', ['sanitize_callback' => [SettingsSanitizer::class, 'userAgent']]);
        register_setting(self::GROUP, 'gma_tile_url', ['sanitize_callback' => [SettingsSanitizer::class, 'tileUrl']]);
        register_setting(self::GROUP, 'gma_map_center_lat', ['sanitize_callback' => [SettingsSanitizer::class, 'latitude']]);
        register_setting(self::GROUP, 'gma_map_center_lng', ['sanitize_callback' => [SettingsSanitizer::class, 'longitude']]);
        register_setting(self::GROUP, 'gma_map_zoom', ['sanitize_callback' => [SettingsSanitizer::class, 'zoom']]);
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        require GMA_PLUGIN_DIR . '/includes/Admin/views/settings.php';
    }
}
