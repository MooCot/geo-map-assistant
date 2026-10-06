<?php

declare(strict_types=1);

namespace GMA\Geocoder;

/**
 * Deterministic, network-free geocoder for demo mode. Same address always
 * yields the same coordinates. Never used as a fallback for a real provider:
 * the chain is configured explicitly, so fake coordinates cannot silently
 * replace a real "not found".
 */
final class MockGeocoder implements GeocoderInterface
{
    public function geocode(string $address): ?GeocodeResult
    {
        $hash = crc32(mb_strtolower(trim($address)));

        // Inside Ukraine's bounding box, so the demo map looks like the demo.
        $lat = round(46.0 + (($hash % 6000) / 1000), 6);
        $lng = round(24.0 + ((($hash >> 8) % 14000) / 1000), 6);

        return new GeocodeResult($lat, $lng, $this->name());
    }

    public function name(): string
    {
        return 'mock';
    }

    public function minIntervalSeconds(): float
    {
        return 0.0;
    }
}
