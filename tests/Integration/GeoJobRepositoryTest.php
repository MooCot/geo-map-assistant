<?php

declare(strict_types=1);

namespace GMA\Tests\Integration;

use GMA\Repository\GeoJobRepository;

/**
 * Runs against a real MySQL via WP_UnitTestCase: each test is wrapped in a
 * transaction that is rolled back afterwards. Time is passed explicitly so
 * lease and backoff behaviour is deterministic.
 */
class GeoJobRepositoryTest extends \WP_UnitTestCase
{
    private const T0 = 1_700_000_000;
    private const LEASE = 300;
    private const MAX = 3;

    private GeoJobRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        GeoJobRepository::installTable();
        $this->repo = new GeoJobRepository();
    }

    public function test_registering_the_same_address_twice_creates_one_pending_job(): void
    {
        $first = $this->repo->register(101, 'Kyiv, Khreshchatyk 1', self::T0);
        $second = $this->repo->register(101, 'Kyiv, Khreshchatyk 1', self::T0);

        $this->assertTrue($first['changed']);
        $this->assertFalse($second['changed']);
        $this->assertSame($first['id'], $second['id']);

        $job = $this->repo->getById($first['id']);
        $this->assertSame(GeoJobRepository::STATUS_PENDING, $job['status']);
        $this->assertSame('0', (string) $job['attempts']);
    }

    public function test_address_normalisation_ignores_case_and_extra_whitespace(): void
    {
        $this->assertSame(
            GeoJobRepository::hashAddress('Kyiv,  Khreshchatyk 1'),
            GeoJobRepository::hashAddress('  kyiv, khreshchatyk 1 ')
        );
    }

    public function test_changing_the_address_requeues_and_clears_the_old_result(): void
    {
        $id = $this->repo->register(102, 'Old address', self::T0)['id'];
        $claim = $this->repo->claimNext(self::T0, self::LEASE, self::MAX);
        $this->repo->markDone($id, $claim['claim_token'], 50.1, 30.5, 'mock', self::T0);

        $result = $this->repo->register(102, 'New address', self::T0 + 10);

        $this->assertTrue($result['changed']);
        $job = $this->repo->getById($id);
        $this->assertSame(GeoJobRepository::STATUS_PENDING, $job['status']);
        $this->assertNull($job['lat']);
        $this->assertNull($job['claim_token']);
    }

    public function test_a_claimed_job_cannot_be_claimed_again_while_its_lease_is_alive(): void
    {
        $this->repo->register(103, 'Lviv', self::T0);

        $first = $this->repo->claimNext(self::T0, self::LEASE, self::MAX);
        $second = $this->repo->claimNext(self::T0 + 1, self::LEASE, self::MAX);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame('1', (string) $first['attempts']);
    }

    public function test_expired_lease_is_reclaimed_and_counts_a_new_attempt(): void
    {
        $this->repo->register(104, 'Odesa', self::T0);

        $first = $this->repo->claimNext(self::T0, self::LEASE, self::MAX);
        $second = $this->repo->claimNext(self::T0 + self::LEASE + 1, self::LEASE, self::MAX);

        $this->assertNotNull($second);
        $this->assertSame((int) $first['id'], (int) $second['id']);
        $this->assertSame('2', (string) $second['attempts']);
        $this->assertNotSame($first['claim_token'], $second['claim_token']);
    }

    public function test_a_stale_worker_cannot_overwrite_the_result_of_a_reclaimed_job(): void
    {
        $id = $this->repo->register(105, 'Dnipro', self::T0)['id'];

        $stale = $this->repo->claimNext(self::T0, self::LEASE, self::MAX);
        $fresh = $this->repo->claimNext(self::T0 + self::LEASE + 1, self::LEASE, self::MAX);

        $this->assertFalse($this->repo->markDone($id, $stale['claim_token'], 48.4, 35.0, 'nominatim', self::T0 + 400));
        $this->assertTrue($this->repo->markDone($id, $fresh['claim_token'], 48.4, 35.0, 'nominatim', self::T0 + 401));

        $job = $this->repo->getById($id);
        $this->assertSame(GeoJobRepository::STATUS_DONE, $job['status']);
        $this->assertEqualsWithDelta(48.4, (float) $job['lat'], 0.0001);
    }

    public function test_transient_failures_retry_with_backoff_until_the_cap_then_error(): void
    {
        $id = $this->repo->register(106, 'Kharkiv', self::T0)['id'];
        $now = self::T0;

        for ($attempt = 1; $attempt <= self::MAX; $attempt++) {
            $claim = $this->repo->claimNext($now, self::LEASE, self::MAX);
            $this->assertSame((string) $attempt, (string) $claim['attempts']);

            $this->repo->markRetryLater($id, $claim['claim_token'], 'timeout', 60, $now, self::MAX);
            $job = $this->repo->getById($id);

            if ($attempt < self::MAX) {
                $this->assertSame(GeoJobRepository::STATUS_PENDING, $job['status']);
                $this->assertSame(gmdate('Y-m-d H:i:s', $now + 60), $job['next_retry_at']);
            } else {
                $this->assertSame(GeoJobRepository::STATUS_ERROR, $job['status']);
            }

            $now += 61;
        }
    }

    public function test_a_job_is_not_claimable_before_its_retry_time(): void
    {
        $id = $this->repo->register(107, 'Poltava', self::T0)['id'];
        $claim = $this->repo->claimNext(self::T0, self::LEASE, self::MAX);
        $this->repo->markRetryLater($id, $claim['claim_token'], 'timeout', 60, self::T0, self::MAX);

        $this->assertNull($this->repo->claimNext(self::T0 + 30, self::LEASE, self::MAX));
        $this->assertNotNull($this->repo->claimNext(self::T0 + 61, self::LEASE, self::MAX));
    }

    public function test_a_system_wide_failure_gives_the_claimed_attempt_back(): void
    {
        $id = $this->repo->register(108, 'Chernihiv', self::T0)['id'];
        $claim = $this->repo->claimNext(self::T0, self::LEASE, self::MAX);

        $this->repo->releaseSystemic($id, $claim['claim_token'], 'nominatim: HTTP 403', self::T0);

        $job = $this->repo->getById($id);
        $this->assertSame(GeoJobRepository::STATUS_PENDING, $job['status']);
        $this->assertSame('0', (string) $job['attempts']);
    }

    public function test_manual_retry_only_applies_to_terminal_jobs_and_only_once(): void
    {
        $id = $this->repo->register(109, 'Zhytomyr', self::T0)['id'];
        $claim = $this->repo->claimNext(self::T0, self::LEASE, self::MAX);
        $this->repo->markFailed($id, $claim['claim_token'], 'bad input', self::T0);

        $this->assertTrue($this->repo->resetForManualRetry($id, self::T0 + 5));
        $this->assertFalse($this->repo->resetForManualRetry($id, self::T0 + 6), 'a second click must not requeue it again');

        $job = $this->repo->getById($id);
        $this->assertSame(GeoJobRepository::STATUS_PENDING, $job['status']);
        $this->assertSame('0', (string) $job['attempts']);
    }

    public function test_a_poison_job_becomes_terminal_instead_of_sitting_in_processing_forever(): void
    {
        $id = $this->repo->register(110, 'Uzhhorod', self::T0)['id'];
        $now = self::T0;

        for ($attempt = 1; $attempt <= self::MAX; $attempt++) {
            $this->assertNotNull($this->repo->claimNext($now, self::LEASE, self::MAX));
            $now += self::LEASE + 1;
        }

        $this->assertNull($this->repo->claimNext($now, self::LEASE, self::MAX));

        $job = $this->repo->getById($id);
        $this->assertSame(GeoJobRepository::STATUS_ERROR, $job['status']);
    }

    public function test_deleting_a_post_job_removes_the_row(): void
    {
        $id = $this->repo->register(111, 'Rivne', self::T0)['id'];

        $this->repo->deleteByPost(111);

        $this->assertNull($this->repo->getById($id));
    }
}
