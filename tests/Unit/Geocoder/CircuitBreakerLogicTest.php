<?php

declare(strict_types=1);

namespace GMA\Tests\Unit\Geocoder;

use GMA\Geocoder\CircuitBreakerLogic;
use PHPUnit\Framework\TestCase;

/**
 * The actual circuit-breaker algorithm, tested as pure state transitions —
 * no WordPress, no time.sleep(), just plain arrays and injected timestamps.
 */
class CircuitBreakerLogicTest extends TestCase
{
    public function test_closed_state_is_available(): void
    {
        $result = CircuitBreakerLogic::resolveAvailability(CircuitBreakerLogic::closedState(), 30, 1000);

        $this->assertTrue($result['available']);
    }

    public function test_a_single_failure_does_not_open_the_circuit(): void
    {
        $state = CircuitBreakerLogic::onFailure(CircuitBreakerLogic::closedState(), 5, 1000);

        $this->assertSame('closed', $state['state']);
        $this->assertSame(1, $state['failures']);
    }

    public function test_reaching_the_failure_threshold_opens_the_circuit(): void
    {
        $state = CircuitBreakerLogic::closedState();

        for ($i = 0; $i < 5; $i++) {
            $state = CircuitBreakerLogic::onFailure($state, 5, 1000);
        }

        $this->assertSame('open', $state['state']);
        $this->assertSame(1000, $state['opened_at']);
    }

    public function test_success_resets_an_accumulating_failure_count(): void
    {
        $state = CircuitBreakerLogic::onFailure(CircuitBreakerLogic::closedState(), 5, 1000);
        $state = CircuitBreakerLogic::onFailure($state, 5, 1001);

        $state = CircuitBreakerLogic::onSuccess($state);

        $this->assertSame(['state' => 'closed', 'failures' => 0, 'opened_at' => null], $state);
    }

    public function test_open_circuit_is_unavailable_before_cooldown_elapses(): void
    {
        $open = ['state' => 'open', 'failures' => 0, 'opened_at' => 1000];

        $result = CircuitBreakerLogic::resolveAvailability($open, 30, 1015);

        $this->assertFalse($result['available']);
        $this->assertSame('open', $result['state']['state'], 'must still be open, not silently reset');
    }

    public function test_open_circuit_transitions_to_half_open_once_cooldown_elapses(): void
    {
        $open = ['state' => 'open', 'failures' => 0, 'opened_at' => 1000];

        $result = CircuitBreakerLogic::resolveAvailability($open, 30, 1030);

        $this->assertTrue($result['available'], 'the cooldown-elapsed trial request must be allowed through');
        $this->assertSame('half_open', $result['state']['state']);
    }

    public function test_half_open_trial_success_fully_closes_the_circuit(): void
    {
        $halfOpen = ['state' => 'half_open', 'failures' => 0, 'opened_at' => 1000];

        $state = CircuitBreakerLogic::onSuccess($halfOpen);

        $this->assertSame('closed', $state['state']);
    }

    public function test_half_open_trial_failure_reopens_immediately_without_needing_full_threshold(): void
    {
        $halfOpen = ['state' => 'half_open', 'failures' => 0, 'opened_at' => 1000];

        // A single failure during the trial — not failureThreshold-many — must reopen it.
        $state = CircuitBreakerLogic::onFailure($halfOpen, 5, 1031);

        $this->assertSame('open', $state['state']);
        $this->assertSame(1031, $state['opened_at'], 'the cooldown window must restart from this failure');
    }
}
