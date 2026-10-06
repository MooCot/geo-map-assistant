<?php

declare(strict_types=1);

namespace GMA\Geocoder;

/**
 * Tries the configured geocoders in order, skipping any whose circuit is open,
 * and enforces each provider's own rate limit before every call.
 *
 * Outcome precedence: a permanent error stops immediately (retrying elsewhere
 * wastes quota on a bad address); otherwise a transient failure wins over a
 * "not found" (an unreachable provider might have found it); otherwise a
 * systemic failure; only then a definitive "not found" (null).
 */
final class GeocoderManager
{
    /**
     * @param GeocoderInterface[] $geocoders
     */
    public function __construct(
        private readonly array $geocoders,
        private readonly RequestThrottle $throttle,
        private readonly CircuitBreakerInterface $circuitBreaker,
    ) {
    }

    /**
     * @throws TransientGeocodeException
     * @throws PermanentGeocodeException
     * @throws SystemicGeocodeException
     */
    public function geocode(string $address): ?GeocodeResult
    {
        $transient = null;
        $systemic = null;
        $notFound = false;

        foreach ($this->geocoders as $geocoder) {
            $name = $geocoder->name();

            if (! $this->circuitBreaker->isAvailable($name)) {
                $systemic = new SystemicGeocodeException("{$name}: circuit open, skipped");
                continue;
            }

            try {
                $this->throttle->acquire($name, $geocoder->minIntervalSeconds());
                $result = $geocoder->geocode($address);
            } catch (PermanentGeocodeException $e) {
                $this->circuitBreaker->recordSuccess($name);

                throw $e;
            } catch (TransientGeocodeException $e) {
                $this->circuitBreaker->recordFailure($name);
                $transient = $e;

                continue;
            } catch (SystemicGeocodeException $e) {
                $this->circuitBreaker->recordFailure($name);
                $systemic = $e;

                continue;
            }

            $this->circuitBreaker->recordSuccess($name);

            if ($result !== null) {
                return $result;
            }

            $notFound = true;
        }

        if ($transient !== null) {
            throw $transient;
        }

        if ($systemic !== null) {
            throw $systemic;
        }

        if ($notFound) {
            return null;
        }

        throw new SystemicGeocodeException('no geocoders configured');
    }
}
