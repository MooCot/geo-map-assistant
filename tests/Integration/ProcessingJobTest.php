<?php

declare(strict_types=1);

namespace GMA\Tests\Integration;

use GMA\Cron\ProcessingJob;
use GMA\Geocoder\ArrayThrottleStore;
use GMA\Geocoder\GeocodeResult;
use GMA\Geocoder\GeocoderManager;
use GMA\Geocoder\NullCircuitBreaker;
use GMA\Geocoder\PermanentGeocodeException;
use GMA\Geocoder\RequestThrottle;
use GMA\Geocoder\SystemicGeocodeException;
use GMA\Geocoder\TransientGeocodeException;
use GMA\Repository\GeoJobRepository;
use GMA\Tests\Doubles\ScriptedGeocoder;

class ProcessingJobTest extends \WP_UnitTestCase
{
    private const T0 = 1_700_000_000;

    private GeoJobRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        GeoJobRepository::installTable();
        $this->repo = new GeoJobRepository();
    }

    /**
     * @param list<\Closure(string): ?GeocodeResult> $script
     */
    private function batchWith(array $script): ProcessingJob
    {
        $throttle = new RequestThrottle(
            new ArrayThrottleStore(),
            static fn (): float => (float) self::T0,
            static function (float $seconds): void {
            },
        );

        $manager = new GeocoderManager([new ScriptedGeocoder($script)], $throttle, new NullCircuitBreaker());

        return new ProcessingJob($this->repo, $manager, static fn (): int => self::T0);
    }

    public function test_a_found_address_is_stored_as_done_with_coordinates(): void
    {
        $id = $this->repo->register(201, 'Kyiv', self::T0)['id'];
        $job = $this->batchWith([
            static fn (string $a): GeocodeResult => new GeocodeResult(50.45, 30.52, 'scripted'),
        ]);

        $stats = $job->processBatch();

        $this->assertSame(1, $stats['done']);
        $row = $this->repo->getById($id);
        $this->assertSame(GeoJobRepository::STATUS_DONE, $row['status']);
        $this->assertEqualsWithDelta(50.45, (float) $row['lat'], 0.0001);
    }

    public function test_an_unknown_address_becomes_not_found_without_retries(): void
    {
        $id = $this->repo->register(202, 'Nowhere 0', self::T0)['id'];
        $job = $this->batchWith([static fn (string $a): ?GeocodeResult => null]);

        $stats = $job->processBatch();

        $this->assertSame(1, $stats['not_found']);
        $this->assertSame(GeoJobRepository::STATUS_NOT_FOUND, $this->repo->getById($id)['status']);
    }

    public function test_a_transient_failure_is_scheduled_for_a_later_retry(): void
    {
        $id = $this->repo->register(203, 'Lviv', self::T0)['id'];
        $job = $this->batchWith([
            static function (string $a): never {
                throw new TransientGeocodeException('timeout');
            },
        ]);

        $stats = $job->processBatch();

        $this->assertSame(1, $stats['retry']);
        $row = $this->repo->getById($id);
        $this->assertSame(GeoJobRepository::STATUS_PENDING, $row['status']);
        $this->assertSame(gmdate('Y-m-d H:i:s', self::T0 + 60), $row['next_retry_at']);
    }

    public function test_a_permanent_failure_is_terminal_at_once(): void
    {
        $id = $this->repo->register(204, '???', self::T0)['id'];
        $job = $this->batchWith([
            static function (string $a): never {
                throw new PermanentGeocodeException('HTTP 400');
            },
        ]);

        $stats = $job->processBatch();

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(GeoJobRepository::STATUS_ERROR, $this->repo->getById($id)['status']);
    }

    public function test_a_systemic_failure_stops_the_batch_and_returns_the_attempt(): void
    {
        $first = $this->repo->register(205, 'Blocked provider', self::T0)['id'];
        $second = $this->repo->register(206, 'Untouched', self::T0 + 1)['id'];
        $job = $this->batchWith([
            static function (string $a): never {
                throw new SystemicGeocodeException('nominatim: HTTP 403');
            },
        ]);

        $stats = $job->processBatch();

        $this->assertSame(1, $stats['processed'], 'the batch must stop instead of hammering the blocked provider');
        $this->assertTrue($stats['stopped']);

        $firstRow = $this->repo->getById($first);
        $this->assertSame(GeoJobRepository::STATUS_PENDING, $firstRow['status']);
        $this->assertSame('0', (string) $firstRow['attempts']);

        $this->assertSame(GeoJobRepository::STATUS_PENDING, $this->repo->getById($second)['status']);
    }
}
