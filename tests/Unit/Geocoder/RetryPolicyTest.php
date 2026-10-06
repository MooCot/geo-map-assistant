<?php

declare(strict_types=1);

namespace GMA\Tests\Unit\Geocoder;

use GMA\Geocoder\RetryPolicy;
use PHPUnit\Framework\TestCase;

class RetryPolicyTest extends TestCase
{
    public function test_backoff_doubles_from_one_minute(): void
    {
        $this->assertSame(60, RetryPolicy::delaySeconds(1));
        $this->assertSame(120, RetryPolicy::delaySeconds(2));
    }

    public function test_retries_stop_at_max_attempts(): void
    {
        $this->assertTrue(RetryPolicy::shouldRetry(1));
        $this->assertTrue(RetryPolicy::shouldRetry(2));
        $this->assertFalse(RetryPolicy::shouldRetry(3));
    }
}
