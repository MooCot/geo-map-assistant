<?php

declare(strict_types=1);

namespace GMA\Seeder;

use GMA\Admin\PostMetaBox;
use GMA\Repository\GeoJobRepository;

/**
 * Creates generic demo posts with addresses, queued for geocoding, plus a
 * page that embeds the map. Idempotent: every seeded post is marked, and a
 * second run finds the marks and creates nothing.
 */
final class DemoSeeder
{
    public const SEED_META = 'gma_demo_seed';
    public const MAP_PAGE_TITLE = 'Demo map';

    private const ADDRESSES = [
        'Kyiv, Khreshchatyk 1',
        'Lviv, Rynok Square 1',
        'Odesa, Deribasivska 10',
        'Kharkiv, Svobody Square 1',
        'Dnipro, Yavornytskoho Avenue 1',
        'Vinnytsia, Soborna Street 1',
    ];

    public function __construct(private readonly GeoJobRepository $repository)
    {
    }

    /**
     * @return int number of posts created on this run
     */
    public function seed(): int
    {
        $created = 0;
        $now = time();

        foreach (self::ADDRESSES as $index => $address) {
            $title = 'Demo location ' . ($index + 1);

            if ($this->findIdByTitle($title, 'post') !== null) {
                continue;
            }

            $postId = wp_insert_post([
                'post_title' => $title,
                'post_content' => 'A demo post with a geocoded address.',
                'post_status' => 'publish',
                'post_type' => 'post',
            ], true);

            if (is_wp_error($postId)) {
                continue;
            }

            update_post_meta($postId, PostMetaBox::META_KEY, $address);
            update_post_meta($postId, self::SEED_META, '1');
            $this->repository->register($postId, $address, $now);
            $created++;
        }

        $this->ensureMapPage();

        return $created;
    }

    /**
     * get_page_by_title() is deprecated since WordPress 6.2, so look the title up directly.
     */
    private function findIdByTitle(string $title, string $postType): ?int
    {
        global $wpdb;

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = %s AND post_status <> 'trash' LIMIT 1",
            $title,
            $postType
        ));

        return $id === null ? null : (int) $id;
    }

    /**
     * The map page becomes the static front page, so the demo opens on the map.
     */
    private function ensureMapPage(): void
    {
        $pageId = $this->findIdByTitle(self::MAP_PAGE_TITLE, 'page');

        if ($pageId === null) {
            $pageId = (int) wp_insert_post([
                'post_title' => self::MAP_PAGE_TITLE,
                'post_content' => '[gma_map]',
                'post_status' => 'publish',
                'post_type' => 'page',
            ]);
        }

        update_option('show_on_front', 'page');
        update_option('page_on_front', $pageId);
    }
}
