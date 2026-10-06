<?php

declare(strict_types=1);

namespace GMA\Tests\Unit\Geocoder;

use GMA\Geocoder\NominatimGeocoder;
use GMA\Geocoder\PermanentGeocodeException;
use GMA\Geocoder\SystemicGeocodeException;
use GMA\Geocoder\TransientGeocodeException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class NominatimGeocoderTest extends TestCase
{
    private function geocoderWith(MockHandler $mock, array &$history = []): NominatimGeocoder
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new NominatimGeocoder(new Client(['handler' => $stack]), 'geo-map-assistant/1.0 (test@example.com)');
    }

    public function test_returns_coordinates_for_a_found_address(): void
    {
        $mock = new MockHandler([new Response(200, [], '[{"lat":"52.3676","lon":"4.9041"}]')]);

        $result = $this->geocoderWith($mock)->geocode('Amsterdam');

        $this->assertNotNull($result);
        $this->assertSame(52.3676, $result->lat);
        $this->assertSame(4.9041, $result->lng);
        $this->assertSame('nominatim', $result->provider);
    }

    public function test_empty_array_means_address_not_found(): void
    {
        $mock = new MockHandler([new Response(200, [], '[]')]);

        $this->assertNull($this->geocoderWith($mock)->geocode('Nowhere at all 0'));
    }

    public function test_sends_the_identifying_user_agent(): void
    {
        $mock = new MockHandler([new Response(200, [], '[]')]);
        $history = [];

        $this->geocoderWith($mock, $history)->geocode('Amsterdam');

        $this->assertCount(1, $history);
        $this->assertSame('geo-map-assistant/1.0 (test@example.com)', $history[0]['request']->getHeaderLine('User-Agent'));
    }

    public function test_429_is_transient_and_carries_retry_after(): void
    {
        $mock = new MockHandler([new Response(429, ['Retry-After' => '30'], '')]);

        try {
            $this->geocoderWith($mock)->geocode('Amsterdam');
            $this->fail('expected TransientGeocodeException');
        } catch (TransientGeocodeException $e) {
            $this->assertSame(30, $e->retryAfterSeconds);
        }
    }

    public function test_5xx_is_transient(): void
    {
        $this->expectException(TransientGeocodeException::class);

        $this->geocoderWith(new MockHandler([new Response(503, [], '')]))->geocode('Amsterdam');
    }

    public function test_network_failure_is_transient(): void
    {
        $mock = new MockHandler([new ConnectException('timed out', new Request('GET', 'x'))]);

        $this->expectException(TransientGeocodeException::class);

        $this->geocoderWith($mock)->geocode('Amsterdam');
    }

    public function test_403_is_systemic_not_a_per_address_failure(): void
    {
        $this->expectException(SystemicGeocodeException::class);

        $this->geocoderWith(new MockHandler([new Response(403, [], '')]))->geocode('Amsterdam');
    }

    public function test_400_is_permanent(): void
    {
        $this->expectException(PermanentGeocodeException::class);

        $this->geocoderWith(new MockHandler([new Response(400, [], '')]))->geocode('Amsterdam');
    }

    public function test_malformed_body_is_transient(): void
    {
        $this->expectException(TransientGeocodeException::class);

        $this->geocoderWith(new MockHandler([new Response(200, [], 'not json')]))->geocode('Amsterdam');
    }
}
