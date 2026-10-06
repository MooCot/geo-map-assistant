<?php

declare(strict_types=1);

namespace GMA\Map;

use GMA\Admin\SettingsSanitizer;
use GMA\Repository\GeoJobRepository;

/**
 * [gma_map] shortcode: a Leaflet map with a marker per published post whose
 * address has been geocoded. Leaflet is vendored in assets/, tiles come from
 * the configured tile server, and only published posts are exposed.
 */
final class MapShortcode
{
    public const TAG = 'gma_map';
    public const ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

    private const MAX_MARKERS = 500;
    private const DEFAULT_HEIGHT = '480px';

    public function __construct(private readonly GeoJobRepository $repository)
    {
    }

    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public function render($atts = []): string
    {
        $atts = shortcode_atts([
            'zoom' => (string) SettingsSanitizer::zoom(get_option('gma_map_zoom', SettingsSanitizer::DEFAULT_ZOOM)),
            'height' => self::DEFAULT_HEIGHT,
            'center_lat' => (string) SettingsSanitizer::latitude(get_option('gma_map_center_lat', SettingsSanitizer::DEFAULT_LAT)),
            'center_lng' => (string) SettingsSanitizer::longitude(get_option('gma_map_center_lng', SettingsSanitizer::DEFAULT_LNG)),
        ], is_array($atts) ? $atts : [], self::TAG);

        $markers = $this->markers();

        wp_enqueue_style('leaflet', GMA_PLUGIN_URL . 'assets/leaflet.css', [], '1.9.4');
        wp_enqueue_style('gma-map', GMA_PLUGIN_URL . 'assets/map.css', ['leaflet'], GMA_VERSION);
        wp_enqueue_script('leaflet', GMA_PLUGIN_URL . 'assets/leaflet.js', [], '1.9.4', true);
        wp_enqueue_script('gma-map', GMA_PLUGIN_URL . 'assets/map.js', ['leaflet'], GMA_VERSION, true);

        wp_localize_script('gma-map', 'gmaMap', [
            'tileUrl' => SettingsSanitizer::tileUrl(get_option('gma_tile_url', SettingsSanitizer::DEFAULT_TILE_URL)),
            'attribution' => self::ATTRIBUTION,
            'zoom' => (int) $atts['zoom'],
            'center' => [(float) $atts['center_lat'], (float) $atts['center_lng']],
            'markers' => $markers,
        ]);

        $height = preg_match('/^\d{2,4}(px|vh)$/', (string) $atts['height']) === 1
            ? (string) $atts['height']
            : self::DEFAULT_HEIGHT;

        $count = count($markers);
        $countLabel = sprintf(
            _n('%s location on the map', '%s locations on the map', $count, 'geo-map-assistant'),
            number_format_i18n($count)
        );

        return '<section class="gma-map-card">'
            . '<header class="gma-map-header"><h2>Where we are</h2><span class="gma-map-count">' . esc_html($countLabel) . '</span></header>'
            . '<div id="gma-map" class="gma-map" style="height:' . esc_attr($height) . '"></div>'
            . '</section>';
    }

    /**
     * @return array<int, array{lat: float, lng: float, title: string, url: string}>
     */
    private function markers(): array
    {
        $markers = [];

        foreach ($this->repository->getMapPoints(self::MAX_MARKERS) as $point) {
            $post = get_post((int) $point['post_id']);

            if ($post === null || $post->post_status !== 'publish') {
                continue;
            }

            $markers[] = [
                'lat' => (float) $point['lat'],
                'lng' => (float) $point['lng'],
                'title' => get_the_title($post),
                'url' => get_permalink($post),
            ];
        }

        return $markers;
    }
}
