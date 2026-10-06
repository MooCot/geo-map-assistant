<?php

declare(strict_types=1);

namespace GMA\Geocoder;

/**
 * Enforces a minimum interval between requests per provider key. Clock and
 * sleep are injected so the logic is testable without real waiting.
 */
final class RequestThrottle
{
    /**
     * @param \Closure(): float $clock
     * @param \Closure(float): void $sleep
     */
    public function __construct(
        private readonly ThrottleStoreInterface $store,
        private readonly \Closure $clock,
        private readonly \Closure $sleep,
    ) {
    }

    public function acquire(string $key, float $minIntervalSeconds): void
    {
        $now = ($this->clock)();
        $last = $this->store->lastRequestAt($key);

        if ($last !== null) {
            $wait = $minIntervalSeconds - ($now - $last);

            if ($wait > 0.0) {
                ($this->sleep)($wait);
                $now += $wait;
            }
        }

        $this->store->markRequestAt($key, $now);
    }
}
