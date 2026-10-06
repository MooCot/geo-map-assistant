<?php

declare(strict_types=1);

namespace GMA\Geocoder;

/**
 * No-op circuit breaker — every provider is always available. This is
 * GeocoderManager's default when no breaker is injected, which is what
 * keeps tests/Unit/Geocoder/GeocoderManagerTest.php free of any WordPress
 * dependency: the real WP-backed CircuitBreaker is wired explicitly at
 * the composition root (geo-map-assistant.php) instead.
 */
final class NullCircuitBreaker implements CircuitBreakerInterface
{
    public function isAvailable(string $providerName): bool
    {
        return true;
    }

    public function recordSuccess(string $providerName): void
    {
    }

    public function recordFailure(string $providerName): void
    {
    }
}
