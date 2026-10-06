<?php

declare(strict_types=1);

namespace GMA\Geocoder;

/**
 * The provider is unusable for every job (blocked, bad User-Agent, circuit
 * open). Must NOT consume a job's attempt: the failure says nothing about
 * the address.
 */
class SystemicGeocodeException extends GeocodeException
{
}
