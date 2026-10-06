<?php

declare(strict_types=1);

namespace GMA\Geocoder;

/**
 * Pure circuit-breaker state machine — closed/open/half-open transitions,
 * no I/O, no WordPress. Mirrors the pattern in content-platform's LLM
 * router (LLM_CB_FAILURE_THRESHOLD / LLM_CB_COOLDOWN_MS), scaled down: a
 * provider that fails FAILURE_THRESHOLD times in a row stops being tried
 * at all for COOLDOWN_SECONDS, instead of every request paying the cost
 * of retrying a provider that's currently down.
 *
 * State is a plain array so this stays trivially unit-testable — the
 * WordPress-backed persistence lives in CircuitBreaker, which is the only
 * part of this feature that isn't covered by pure unit tests.
 *
 * @phpstan-type State array{state: 'closed'|'open'|'half_open', failures: int, opened_at: ?int}
 */
final class CircuitBreakerLogic
{
    /**
     * @return State
     */
    public static function closedState(): array
    {
        return ['state' => 'closed', 'failures' => 0, 'opened_at' => null];
    }

    /**
     * @param State $state
     * @return State
     */
    public static function onSuccess(array $state): array
    {
        return self::closedState();
    }

    /**
     * A half-open trial that fails goes straight back to open — it doesn't
     * need to accumulate a fresh threshold's worth of failures, because a
     * half-open state only ever represents a single trial request.
     *
     * @param State $state
     * @return State
     */
    public static function onFailure(array $state, int $failureThreshold, int $now): array
    {
        if ($state['state'] === 'half_open') {
            return ['state' => 'open', 'failures' => 0, 'opened_at' => $now];
        }

        $failures = $state['failures'] + 1;

        if ($failures >= $failureThreshold) {
            return ['state' => 'open', 'failures' => 0, 'opened_at' => $now];
        }

        return ['state' => 'closed', 'failures' => $failures, 'opened_at' => null];
    }

    /**
     * Whether a provider in this state may be tried right now. An `open`
     * circuit whose cooldown has elapsed transitions to `half_open` here
     * (allowing exactly one trial request) — the caller is responsible for
     * persisting the returned state either way.
     *
     * @param State $state
     * @return array{available: bool, state: State}
     */
    public static function resolveAvailability(array $state, int $cooldownSeconds, int $now): array
    {
        if ($state['state'] !== 'open') {
            return ['available' => true, 'state' => $state];
        }

        if ($state['opened_at'] !== null && ($now - $state['opened_at']) >= $cooldownSeconds) {
            return [
                'available' => true,
                'state' => ['state' => 'half_open', 'failures' => 0, 'opened_at' => $state['opened_at']],
            ];
        }

        return ['available' => false, 'state' => $state];
    }
}
