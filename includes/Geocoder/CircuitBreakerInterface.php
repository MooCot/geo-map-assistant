<?php

declare(strict_types=1);

namespace GMA\Geocoder;

interface CircuitBreakerInterface
{
    public function isAvailable(string $providerName): bool;

    public function recordSuccess(string $providerName): void;

    public function recordFailure(string $providerName): void;
}
