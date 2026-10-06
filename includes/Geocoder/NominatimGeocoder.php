<?php

declare(strict_types=1);

namespace GMA\Geocoder;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * OpenStreetMap Nominatim search. Usage policy: at most one request per
 * second and an identifying User-Agent — the rate limit is declared here via
 * minIntervalSeconds() and enforced by GeocoderManager.
 */
final class NominatimGeocoder implements GeocoderInterface
{
    private const DEFAULT_ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $userAgent,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
    ) {
    }

    public function geocode(string $address): ?GeocodeResult
    {
        try {
            $response = $this->http->request('GET', $this->endpoint, [
                'query' => ['q' => $address, 'format' => 'json', 'limit' => 1],
                'headers' => ['User-Agent' => $this->userAgent],
                'timeout' => 10,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new TransientGeocodeException('nominatim: network error — ' . $e->getMessage(), null, $e);
        }

        return $this->interpret($response);
    }

    public function name(): string
    {
        return 'nominatim';
    }

    public function minIntervalSeconds(): float
    {
        return 1.1;
    }

    private function interpret(ResponseInterface $response): ?GeocodeResult
    {
        $status = $response->getStatusCode();

        if ($status === 429) {
            $retryAfter = trim($response->getHeaderLine('Retry-After'));

            throw new TransientGeocodeException(
                'nominatim: rate limited',
                ctype_digit($retryAfter) ? (int) $retryAfter : null,
            );
        }

        if ($status === 401 || $status === 403) {
            throw new SystemicGeocodeException("nominatim: HTTP {$status} (blocked or invalid User-Agent)");
        }

        if ($status >= 500) {
            throw new TransientGeocodeException("nominatim: HTTP {$status}");
        }

        if ($status >= 400) {
            throw new PermanentGeocodeException("nominatim: HTTP {$status}");
        }

        $data = json_decode((string) $response->getBody(), true);

        if (! is_array($data)) {
            throw new TransientGeocodeException('nominatim: malformed response');
        }

        if ($data === []) {
            return null;
        }

        $first = $data[0];

        if (! is_array($first) || ! isset($first['lat'], $first['lon']) || ! is_numeric($first['lat']) || ! is_numeric($first['lon'])) {
            throw new TransientGeocodeException('nominatim: malformed result');
        }

        return new GeocodeResult((float) $first['lat'], (float) $first['lon'], $this->name());
    }
}
