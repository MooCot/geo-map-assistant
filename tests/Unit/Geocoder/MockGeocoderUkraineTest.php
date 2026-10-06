<?php

declare(strict_types=1);

namespace GMA\Tests\Unit\Geocoder;

use GMA\Geocoder\MockGeocoder;
use PHPUnit\Framework\TestCase;

class MockGeocoderUkraineTest extends TestCase
{
    public function test_mock_coordinates_stay_inside_the_ukraine_box(): void
    {
        $mock = new MockGeocoder();

        foreach (['Kyiv', 'Lviv', 'Odesa', 'Kharkiv', 'a', str_repeat('street ', 40)] as $address) {
            $result = $mock->geocode($address);

            $this->assertGreaterThanOrEqual(46.0, $result->lat);
            $this->assertLessThanOrEqual(52.0, $result->lat);
            $this->assertGreaterThanOrEqual(24.0, $result->lng);
            $this->assertLessThanOrEqual(38.0, $result->lng);
        }
    }
}
