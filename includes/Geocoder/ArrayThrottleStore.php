<?php

declare(strict_types=1);

namespace GMA\Geocoder;

/**
 * In-memory store, for tests and single-request use. The WordPress-backed
 * store (wp_options) is what makes the limit hold across separate requests.
 */
final class ArrayThrottleStore implements ThrottleStoreInterface
{
    /** @var array<string, float> */
    private array $times = [];

    public function lastRequestAt(string $key): ?float
    {
        return $this->times[$key] ?? null;
    }

    public function markRequestAt(string $key, float $at): void
    {
        $this->times[$key] = $at;
    }
}
