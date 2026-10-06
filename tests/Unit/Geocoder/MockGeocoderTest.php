<?php

declare(strict_types=1);

namespace GMA\Tests\Unit\Geocoder;

use GMA\Geocoder\MockGeocoder;
use PHPUnit\Framework\TestCase;

class MockGeocoderTest extends TestCase
{
    public function test_same_address_always_yields_the_same_coordinates(): void
    {
        $mock = new MockGeocoder();

        $a = $mock->geocode('Kyiv, Khreshchatyk 1');
        $b = $mock->geocode('Kyiv, Khreshchatyk 1');

        $this->assertSame($a->lat, $b->lat);
        $this->assertSame($a->lng, $b->lng);
    }

    public function test_coordinates_stay_within_valid_ranges(): void
    {
        $mock = new MockGeocoder();

        foreach (['a', 'Lviv', 'Berlin, Unter den Linden 1', str_repeat('x', 200)] as $address) {
            $result = $mock->geocode($address);

            $this->assertGreaterThanOrEqual(-90.0, $result->lat);
            $this->assertLessThanOrEqual(90.0, $result->lat);
            $this->assertGreaterThanOrEqual(-180.0, $result->lng);
            $this->assertLessThanOrEqual(180.0, $result->lng);
        }
    }

    public function test_mock_has_no_rate_limit(): void
    {
        $this->assertSame(0.0, (new MockGeocoder())->minIntervalSeconds());
    }
}
