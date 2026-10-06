<?php

declare(strict_types=1);

namespace GMA\Tests\Unit\Geocoder;

use GMA\Geocoder\ArrayThrottleStore;
use GMA\Geocoder\CircuitBreakerInterface;
use GMA\Geocoder\GeocodeResult;
use GMA\Geocoder\GeocoderInterface;
use GMA\Geocoder\GeocoderManager;
use GMA\Geocoder\NullCircuitBreaker;
use GMA\Geocoder\PermanentGeocodeException;
use GMA\Geocoder\RequestThrottle;
use GMA\Geocoder\SystemicGeocodeException;
use GMA\Geocoder\TransientGeocodeException;
use PHPUnit\Framework\TestCase;

class GeocoderManagerTest extends TestCase
{
    /** @var float[] */
    private array $sleeps = [];

    private float $now = 1000.0;

    private function throttle(): RequestThrottle
    {
        return new RequestThrottle(
            new ArrayThrottleStore(),
            fn (): float => $this->now,
            function (float $seconds): void {
                $this->sleeps[] = $seconds;
                $this->now += $seconds;
            },
        );
    }

    /**
     * @param callable(string): ?GeocodeResult $behaviour
     */
    private function fakeGeocoder(string $name, callable $behaviour, float $interval = 0.0): GeocoderInterface
    {
        return new class ($name, $behaviour, $interval) implements GeocoderInterface {
            public int $calls = 0;

            public function __construct(
                private readonly string $name,
                private readonly \Closure $behaviour,
                private readonly float $interval,
            ) {
            }

            public function geocode(string $address): ?GeocodeResult
            {
                $this->calls++;

                return ($this->behaviour)($address);
            }

            public function name(): string
            {
                return $this->name;
            }

            public function minIntervalSeconds(): float
            {
                return $this->interval;
            }
        };
    }

    private function found(string $provider): \Closure
    {
        return fn (string $address): GeocodeResult => new GeocodeResult(1.0, 2.0, $provider);
    }

    public function test_returns_the_first_provider_that_finds_the_address(): void
    {
        $first = $this->fakeGeocoder('a', $this->found('a'));
        $second = $this->fakeGeocoder('b', $this->found('b'));

        $result = (new GeocoderManager([$first, $second], $this->throttle(), new NullCircuitBreaker()))->geocode('x');

        $this->assertSame('a', $result->provider);
        $this->assertSame(0, $second->calls, 'second provider must not be called once the first answered');
    }

    public function test_transient_failure_falls_back_to_the_next_provider(): void
    {
        $failing = $this->fakeGeocoder('a', fn () => throw new TransientGeocodeException('timeout'));
        $backup = $this->fakeGeocoder('b', $this->found('b'));

        $result = (new GeocoderManager([$failing, $backup], $this->throttle(), new NullCircuitBreaker()))->geocode('x');

        $this->assertSame('b', $result->provider);
    }

    public function test_permanent_failure_stops_immediately_without_fallback(): void
    {
        $bad = $this->fakeGeocoder('a', fn () => throw new PermanentGeocodeException('400'));
        $backup = $this->fakeGeocoder('b', $this->found('b'));

        $this->expectException(PermanentGeocodeException::class);

        try {
            (new GeocoderManager([$bad, $backup], $this->throttle(), new NullCircuitBreaker()))->geocode('x');
        } finally {
            $this->assertSame(0, $backup->calls, 'a bad address must not burn quota on another provider');
        }
    }

    public function test_not_found_from_every_provider_returns_null(): void
    {
        $a = $this->fakeGeocoder('a', fn () => null);
        $b = $this->fakeGeocoder('b', fn () => null);

        $this->assertNull((new GeocoderManager([$a, $b], $this->throttle(), new NullCircuitBreaker()))->geocode('x'));
    }

    public function test_transient_failure_wins_over_not_found_from_another_provider(): void
    {
        $unreachable = $this->fakeGeocoder('a', fn () => throw new TransientGeocodeException('down'));
        $notFound = $this->fakeGeocoder('b', fn () => null);

        $this->expectException(TransientGeocodeException::class);

        (new GeocoderManager([$unreachable, $notFound], $this->throttle(), new NullCircuitBreaker()))->geocode('x');
    }

    public function test_open_circuit_skips_the_provider_without_calling_it(): void
    {
        $tripped = $this->fakeGeocoder('a', $this->found('a'));
        $backup = $this->fakeGeocoder('b', $this->found('b'));

        $breaker = new class () implements CircuitBreakerInterface {
            public function isAvailable(string $providerName): bool
            {
                return $providerName !== 'a';
            }

            public function recordSuccess(string $providerName): void
            {
            }

            public function recordFailure(string $providerName): void
            {
            }
        };

        $result = (new GeocoderManager([$tripped, $backup], $this->throttle(), $breaker))->geocode('x');

        $this->assertSame('b', $result->provider);
        $this->assertSame(0, $tripped->calls);
    }

    public function test_all_providers_circuit_open_is_systemic_so_no_attempt_is_consumed(): void
    {
        $only = $this->fakeGeocoder('a', $this->found('a'));

        $breaker = new class () implements CircuitBreakerInterface {
            public function isAvailable(string $providerName): bool
            {
                return false;
            }

            public function recordSuccess(string $providerName): void
            {
            }

            public function recordFailure(string $providerName): void
            {
            }
        };

        $this->expectException(SystemicGeocodeException::class);

        (new GeocoderManager([$only], $this->throttle(), $breaker))->geocode('x');
    }

    public function test_consecutive_calls_to_a_rate_limited_provider_are_spaced_by_its_interval(): void
    {
        $nominatim = $this->fakeGeocoder('nominatim', $this->found('nominatim'), 1.1);
        $manager = new GeocoderManager([$nominatim], $this->throttle(), new NullCircuitBreaker());

        $manager->geocode('one');
        $manager->geocode('two');

        $this->assertCount(1, $this->sleeps, 'the second call within the interval must wait the remainder');
        $this->assertEqualsWithDelta(1.1, $this->sleeps[0] + 0.0, 0.0001);
    }

    public function test_provider_without_rate_limit_is_never_delayed(): void
    {
        $mock = $this->fakeGeocoder('mock', $this->found('mock'), 0.0);
        $manager = new GeocoderManager([$mock], $this->throttle(), new NullCircuitBreaker());

        $manager->geocode('one');
        $manager->geocode('two');

        $this->assertSame([], $this->sleeps);
    }
}
