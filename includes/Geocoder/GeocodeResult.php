<?php

declare(strict_types=1);

namespace GMA\Geocoder;

final readonly class GeocodeResult
{
    public function __construct(
        public float $lat,
        public float $lng,
        public string $provider,
    ) {
    }
}
