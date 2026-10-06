<?php

declare(strict_types=1);

namespace GMA\Geocoder;

/**
 * Transient failures only. Attempts are counted at claim time, so a job that
 * keeps crashing its worker still reaches the cap and stops.
 */
final class RetryPolicy
{
    public const MAX_ATTEMPTS = 3;

    private const BASE_DELAY_SECONDS = 60;

    public static function shouldRetry(int $attemptsMade): bool
    {
        return $attemptsMade < self::MAX_ATTEMPTS;
    }

    /**
     * Delay before the next attempt, after $attemptsMade attempts so far
     * (1 min after the first, 2 min after the second).
     */
    public static function delaySeconds(int $attemptsMade): int
    {
        return self::BASE_DELAY_SECONDS * (2 ** ($attemptsMade - 1));
    }
}
