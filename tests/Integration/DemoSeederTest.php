<?php

declare(strict_types=1);

namespace GMA\Tests\Integration;

use GMA\Repository\GeoJobRepository;
use GMA\Seeder\DemoSeeder;

class DemoSeederTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        GeoJobRepository::installTable();
    }

    public function test_first_run_creates_posts_with_queued_addresses_and_a_map_page(): void
    {
        $created = (new DemoSeeder(new GeoJobRepository()))->seed();

        $this->assertSame(6, $created);

        $seeded = get_posts(['post_type' => 'post', 'meta_key' => DemoSeeder::SEED_META, 'numberposts' => -1, 'fields' => 'ids']);
        $this->assertCount(6, $seeded);

        $counts = (new GeoJobRepository())->getCounts();
        $this->assertSame(6, $counts['pending']);

        $pages = array_values(array_filter(
            get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1]),
            static fn (\WP_Post $page): bool => $page->post_title === DemoSeeder::MAP_PAGE_TITLE
        ));

        $this->assertCount(1, $pages);
        $this->assertStringContainsString('[gma_map]', $pages[0]->post_content);
        $this->assertSame('page', get_option('show_on_front'));
        $this->assertSame($pages[0]->ID, (int) get_option('page_on_front'));
    }

    public function test_a_second_run_creates_nothing(): void
    {
        $seeder = new DemoSeeder(new GeoJobRepository());
        $seeder->seed();

        $this->assertSame(0, $seeder->seed());

        $counts = (new GeoJobRepository())->getCounts();
        $this->assertSame(6, $counts['total']);
    }
}
