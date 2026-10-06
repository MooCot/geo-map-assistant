<?php

declare(strict_types=1);

namespace GMA\Tests\Unit\Geocoder;

use GMA\Geocoder\ArrayThrottleStore;
use GMA\Geocoder\RequestThrottle;
use PHPUnit\Framework\TestCase;

class RequestThrottleTest extends TestCase
{
    public function test_first_call_is_not_delayed(): void
    {
        $sleeps = [];
        $throttle = new RequestThrottle(new ArrayThrottleStore(), fn () => 1000.0, function (float $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });

        $throttle->acquire('nominatim', 1.1);

        $this->assertSame([], $sleeps);
    }

    public function test_call_within_the_interval_waits_only_the_remainder(): void
    {
        $now = 1000.0;
        $sleeps = [];
        $throttle = new RequestThrottle(
            new ArrayThrottleStore(),
            function () use (&$now): float {
                return $now;
            },
            function (float $s) use (&$sleeps, &$now): void {
                $sleeps[] = $s;
                $now += $s;
            },
        );

        $throttle->acquire('nominatim', 1.1);
        $now += 0.4;
        $throttle->acquire('nominatim', 1.1);

        $this->assertCount(1, $sleeps);
        $this->assertEqualsWithDelta(0.7, $sleeps[0], 0.0001);
    }

    public function test_keys_are_throttled_independently(): void
    {
        $sleeps = [];
        $throttle = new RequestThrottle(new ArrayThrottleStore(), fn () => 1000.0, function (float $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });

        $throttle->acquire('nominatim', 1.1);
        $throttle->acquire('other', 1.1);

        $this->assertSame([], $sleeps);
    }
}
