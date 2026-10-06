<?php

declare(strict_types=1);

namespace GMA\Geocoder;

/**
 * Temporary failure (timeout, 5xx, 429, malformed response): the job is
 * retried with backoff and consumes one attempt.
 */
class TransientGeocodeException extends GeocodeException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
