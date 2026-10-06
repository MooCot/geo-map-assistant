<?php

declare(strict_types=1);

namespace GMA\Tests\Integration;

use GMA\Map\MapShortcode;
use GMA\Repository\GeoJobRepository;

class MapShortcodeTest extends \WP_UnitTestCase
{
    private const T0 = 1_700_000_000;

    private GeoJobRepository $repo;
    private MapShortcode $shortcode;

    protected function setUp(): void
    {
        parent::setUp();

        GeoJobRepository::installTable();
        $this->repo = new GeoJobRepository();
        $this->shortcode = new MapShortcode($this->repo);
    }

    private function geocode(int $postId, string $address, float $lat, float $lng): void
    {
        $id = $this->repo->register($postId, $address, self::T0)['id'];
        $claim = $this->repo->claimById($id, self::T0, 300, 3);
        $this->repo->markDone($id, $claim['claim_token'], $lat, $lng, 'mock', self::T0);
    }

    private function localizedMarkersScript(): string
    {
        global $wp_scripts;

        return (string) $wp_scripts->get_data('gma-map', 'data');
    }

    public function test_only_geocoded_published_posts_become_markers(): void
    {
        $published = self::factory()->post->create(['post_status' => 'publish', 'post_title' => 'Kyiv office']);
        $this->geocode($published, 'Kyiv', 50.45, 30.52);

        $pending = self::factory()->post->create(['post_status' => 'publish', 'post_title' => 'Still waiting']);
        $this->repo->register($pending, 'Lviv', self::T0);

        $html = $this->shortcode->render([]);
        $script = $this->localizedMarkersScript();

        $this->assertStringContainsString('<div id="gma-map"', $html);
        $this->assertStringContainsString('Kyiv office', $script);
        $this->assertStringNotContainsString('Still waiting', $script, 'a job without coordinates must not appear on the map');
    }

    public function test_drafts_are_never_exposed_even_when_geocoded(): void
    {
        $draft = self::factory()->post->create(['post_status' => 'draft', 'post_title' => 'Secret draft']);
        $this->geocode($draft, 'Odesa', 46.48, 30.73);

        $this->shortcode->render([]);

        $this->assertStringNotContainsString('Secret draft', $this->localizedMarkersScript());
    }

    public function test_an_invalid_height_falls_back_to_the_default(): void
    {
        $html = $this->shortcode->render(['height' => 'expression(alert(1))']);

        $this->assertStringContainsString('height:480px', $html);
    }

    public function test_a_valid_height_is_kept(): void
    {
        $html = $this->shortcode->render(['height' => '320px']);

        $this->assertStringContainsString('height:320px', $html);
    }
}
