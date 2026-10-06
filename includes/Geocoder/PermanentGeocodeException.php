<?php

declare(strict_types=1);

namespace GMA\Geocoder;

/**
 * The request itself is invalid (e.g. 400): retrying cannot change the
 * answer, so the job goes terminal immediately.
 */
class PermanentGeocodeException extends GeocodeException
{
}
