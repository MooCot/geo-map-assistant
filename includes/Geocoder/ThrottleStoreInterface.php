<?php

declare(strict_types=1);

namespace GMA\Geocoder;

interface ThrottleStoreInterface
{
    public function lastRequestAt(string $key): ?float;

    public function markRequestAt(string $key, float $at): void;
}
