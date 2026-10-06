<?php

declare(strict_types=1);

namespace GMA\Admin;

/**
 * Pure validation for the settings page. Kept free of WordPress calls so the
 * rules are unit-testable; each method returns a safe value, never an error.
 */
final class SettingsSanitizer
{
    public const GEOCODERS = ['mock', 'nominatim'];
    public const DEFAULT_GEOCODER = 'mock';
    public const DEFAULT_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    public const DEFAULT_USER_AGENT = 'geo-map-assistant/1.0 (+https://github.com/MooCot/geo-map-assistant)';
    public const DEFAULT_LAT = 48.5;
    public const DEFAULT_LNG = 31.0;
    public const DEFAULT_ZOOM = 5;

    public static function geocoder(mixed $value): string
    {
        return is_string($value) && in_array($value, self::GEOCODERS, true) ? $value : self::DEFAULT_GEOCODER;
    }

    /**
     * Only https tile servers that contain all three placeholders are accepted.
     */
    public static function tileUrl(mixed $value): string
    {
        $url = is_string($value) ? trim($value) : '';

        $isHttps = str_starts_with($url, 'https://');
        $hasPlaceholders = str_contains($url, '{z}') && str_contains($url, '{x}') && str_contains($url, '{y}');
        $hasNoWhitespace = preg_match('/\s/', $url) !== 1;

        return $isHttps && $hasPlaceholders && $hasNoWhitespace ? $url : self::DEFAULT_TILE_URL;
    }

    public static function latitude(mixed $value): float
    {
        return self::bounded($value, -90.0, 90.0, self::DEFAULT_LAT);
    }

    public static function longitude(mixed $value): float
    {
        return self::bounded($value, -180.0, 180.0, self::DEFAULT_LNG);
    }

    public static function zoom(mixed $value): int
    {
        $zoom = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 18]]);

        return $zoom === false ? self::DEFAULT_ZOOM : $zoom;
    }

    public static function userAgent(mixed $value): string
    {
        $agent = is_string($value) ? trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '') : '';

        return $agent !== '' ? mb_substr($agent, 0, 200) : self::DEFAULT_USER_AGENT;
    }

    private static function bounded(mixed $value, float $min, float $max, float $default): float
    {
        $number = filter_var($value, FILTER_VALIDATE_FLOAT);

        return $number !== false && $number >= $min && $number <= $max ? (float) $number : $default;
    }
}
