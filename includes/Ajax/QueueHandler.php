<?php

declare(strict_types=1);

namespace GMA\Ajax;

use GMA\Admin\PostMetaBox;
use GMA\Cron\ProcessingJob;
use GMA\Repository\GeoJobRepository;

/**
 * "Queue all" enqueues every published post that has an address; the batch
 * endpoint processes one batch on demand (the cron does the same on a
 * schedule). Re-queueing is idempotent: an unchanged address is a no-op, so
 * finished jobs are never re-geocoded by a bulk click.
 */
final class QueueHandler
{
    public function __construct(
        private readonly GeoJobRepository $repository,
        private readonly ProcessingJob $processing,
    ) {
    }

    public function register(): void
    {
        add_action('wp_ajax_gma_queue_all', [$this, 'queueAll']);
        add_action('wp_ajax_gma_process_batch', [$this, 'processBatch']);
    }

    public function queueAll(): void
    {
        check_ajax_referer('gma_queue_all', 'nonce');

        if (! current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $postIds = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => PostMetaBox::META_KEY,
            'meta_value' => '',
            'meta_compare' => '!=',
        ]);

        $queued = 0;
        $now = time();

        foreach ($postIds as $postId) {
            $address = trim((string) get_post_meta((int) $postId, PostMetaBox::META_KEY, true));

            if ($address === '') {
                continue;
            }

            if ($this->repository->register((int) $postId, $address, $now)['changed']) {
                $queued++;
            }
        }

        wp_send_json_success([
            'queued' => $queued,
            'total' => count($postIds),
            'counts' => $this->repository->getCounts(),
        ]);
    }

    public function processBatch(): void
    {
        check_ajax_referer('gma_process_batch', 'nonce');

        if (! current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        wp_send_json_success([
            'stats' => $this->processing->processBatch(),
            'counts' => $this->repository->getCounts(),
        ]);
    }
}
