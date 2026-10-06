<?php

declare(strict_types=1);

namespace GMA\Geocoder;

/**
 * WordPress-backed circuit breaker: state per provider in wp_options
 * (autoload=false). PHP has no long-running process to keep it in memory
 * between requests, so the state must persist. The decision logic lives in
 * CircuitBreakerLogic.
 */
final class WpCircuitBreaker implements CircuitBreakerInterface
{
    private const FAILURE_THRESHOLD = 5;
    private const COOLDOWN_SECONDS = 30;

    public function isAvailable(string $providerName): bool
    {
        $state = $this->loadState($providerName);
        $result = CircuitBreakerLogic::resolveAvailability($state, self::COOLDOWN_SECONDS, time());

        if ($result['state'] !== $state) {
            $this->saveState($providerName, $result['state']);
        }

        return $result['available'];
    }

    public function recordSuccess(string $providerName): void
    {
        $this->saveState($providerName, CircuitBreakerLogic::onSuccess($this->loadState($providerName)));
    }

    public function recordFailure(string $providerName): void
    {
        $state = $this->loadState($providerName);
        $this->saveState($providerName, CircuitBreakerLogic::onFailure($state, self::FAILURE_THRESHOLD, time()));
    }

    /**
     * @return array{state: 'closed'|'open'|'half_open', failures: int, opened_at: ?int}
     */
    private function loadState(string $providerName): array
    {
        $stored = get_option($this->optionKey($providerName), null);

        return is_array($stored)
            ? array_merge(CircuitBreakerLogic::closedState(), $stored)
            : CircuitBreakerLogic::closedState();
    }

    /**
     * @param array{state: 'closed'|'open'|'half_open', failures: int, opened_at: ?int} $state
     */
    private function saveState(string $providerName, array $state): void
    {
        update_option($this->optionKey($providerName), $state, false);
    }

    private function optionKey(string $providerName): string
    {
        return 'gma_circuit_' . $providerName;
    }
}
