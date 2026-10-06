<?php

declare(strict_types=1);

namespace GMA\Geocoder;

/**
 * Persists each provider's last-request time in wp_options (autoload=false).
 * PHP requests do not share memory, so the 1.1 s spacing must survive between
 * the cron tick and a manual AJAX drain.
 */
final class WpOptionThrottleStore implements ThrottleStoreInterface
{
    public function lastRequestAt(string $key): ?float
    {
        $value = get_option($this->optionKey($key), null);

        return is_float($value) || is_int($value) ? (float) $value : null;
    }

    public function markRequestAt(string $key, float $at): void
    {
        update_option($this->optionKey($key), $at, false);
    }

    private function optionKey(string $key): string
    {
        return 'gma_throttle_' . $key;
    }
}
