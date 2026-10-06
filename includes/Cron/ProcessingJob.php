<?php

declare(strict_types=1);

namespace GMA\Cron;

use GMA\Geocoder\GeocoderManager;
use GMA\Geocoder\PermanentGeocodeException;
use GMA\Geocoder\RetryPolicy;
use GMA\Geocoder\SystemicGeocodeException;
use GMA\Geocoder\TransientGeocodeException;
use GMA\Repository\GeoJobRepository;

/**
 * Background batch: claims due jobs one by one and maps each geocoder outcome
 * onto the repository. A systemic failure (provider blocked, circuit open)
 * stops the batch, because every further job would fail the same way.
 */
final class ProcessingJob
{
    public const HOOK = 'gma_process_queue';
    public const SCHEDULE = 'gma_every_minute';
    public const BATCH_SIZE = 10;

    public const LEASE_SECONDS = 300;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly GeoJobRepository $repository,
        private readonly GeocoderManager $geocoders,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'runScheduledBatch']);
    }

    public static function schedule(): void
    {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), self::SCHEDULE, self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function addMinuteSchedule(array $schedules): array
    {
        $schedules[self::SCHEDULE] = ['interval' => 60, 'display' => 'Every minute (Geo Map Assistant)'];

        return $schedules;
    }

    /**
     * do_action() passes the hook name to its callbacks as an argument, so the
     * hook gets this no-argument wrapper instead of processBatch() directly.
     */
    public function runScheduledBatch(): void
    {
        $this->processBatch();
    }

    /**
     * @return array{processed: int, done: int, not_found: int, retry: int, failed: int, systemic: int, stopped: bool}
     */
    public function processBatch(int $limit = self::BATCH_SIZE): array
    {
        $stats = ['processed' => 0, 'done' => 0, 'not_found' => 0, 'retry' => 0, 'failed' => 0, 'systemic' => 0, 'stopped' => false];

        for ($i = 0; $i < $limit; $i++) {
            $now = ($this->clock)();
            $job = $this->repository->claimNext($now, self::LEASE_SECONDS, RetryPolicy::MAX_ATTEMPTS);

            if ($job === null) {
                break;
            }

            $outcome = $this->process($job, $now);
            $stats['processed']++;
            $stats[$outcome]++;

            if ($outcome === 'systemic') {
                $stats['stopped'] = true;
                break;
            }
        }

        return $stats;
    }

    /**
     * Geocodes one already-claimed job and records the outcome. Public so the
     * single-post action can reuse exactly the same mapping.
     *
     * @param array<string, mixed> $job
     * @return 'done'|'not_found'|'retry'|'failed'|'systemic'
     */
    public function process(array $job, int $now): string
    {
        $id = (int) $job['id'];
        $token = (string) $job['claim_token'];

        try {
            $result = $this->geocoders->geocode((string) $job['address']);
        } catch (PermanentGeocodeException $e) {
            $this->repository->markFailed($id, $token, $e->getMessage(), $now);

            return 'failed';
        } catch (TransientGeocodeException $e) {
            $delay = max(RetryPolicy::delaySeconds((int) $job['attempts']), $e->retryAfterSeconds ?? 0);
            $this->repository->markRetryLater($id, $token, $e->getMessage(), $delay, $now, RetryPolicy::MAX_ATTEMPTS);

            return 'retry';
        } catch (SystemicGeocodeException $e) {
            $this->repository->releaseSystemic($id, $token, $e->getMessage(), $now);

            return 'systemic';
        }

        if ($result === null) {
            $this->repository->markNotFound($id, $token, $now);

            return 'not_found';
        }

        $this->repository->markDone($id, $token, $result->lat, $result->lng, $result->provider, $now);

        return 'done';
    }
}
