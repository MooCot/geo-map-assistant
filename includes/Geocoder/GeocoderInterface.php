<?php

declare(strict_types=1);

namespace GMA\Geocoder;

interface GeocoderInterface
{
    /**
     * Returns null when the provider answered and the address was not found.
     *
     * @throws TransientGeocodeException
     * @throws PermanentGeocodeException
     * @throws SystemicGeocodeException
     */
    public function geocode(string $address): ?GeocodeResult;

    public function name(): string;

    /**
     * The provider's own rate limit, enforced by GeocoderManager before each call.
     */
    public function minIntervalSeconds(): float;
}
