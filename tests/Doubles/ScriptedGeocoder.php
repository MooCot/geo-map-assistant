<?php

declare(strict_types=1);

namespace GMA\Tests\Doubles;

use GMA\Geocoder\GeocodeResult;
use GMA\Geocoder\GeocoderInterface;

/**
 * Test double: each call is answered by the next closure in the script.
 */
final class ScriptedGeocoder implements GeocoderInterface
{
    /** @var list<\Closure(string): ?GeocodeResult> */
    private array $script;

    /** @param list<\Closure(string): ?GeocodeResult> $script */
    public function __construct(array $script, private readonly string $providerName = 'scripted')
    {
        $this->script = $script;
    }

    public function geocode(string $address): ?GeocodeResult
    {
        $next = array_shift($this->script);

        if ($next === null) {
            throw new \LogicException('ScriptedGeocoder ran out of scripted answers.');
        }

        return $next($address);
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function minIntervalSeconds(): float
    {
        return 0.0;
    }
}
