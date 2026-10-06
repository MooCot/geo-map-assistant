<?php

declare(strict_types=1);

namespace GMA\Cli;

use GMA\Cron\ProcessingJob;
use GMA\Repository\GeoJobRepository;
use GMA\Seeder\DemoSeeder;

/**
 * `wp gma seed` — creates the demo content, then runs one geocoding batch so
 * the map has points to show straight away.
 */
final class SeedCommand
{
    public function __invoke(array $args, array $assocArgs): void
    {
        $created = (new DemoSeeder(new GeoJobRepository()))->seed();

        do_action(ProcessingJob::HOOK);

        \WP_CLI::success("Seeded {$created} demo post(s); one geocoding batch processed.");
    }
}
