<?php

declare(strict_types=1);

namespace GMA\Ajax;

use GMA\Admin\PostMetaBox;
use GMA\Cron\ProcessingJob;
use GMA\Repository\GeoJobRepository;
use GMA\Geocoder\RetryPolicy;

/**
 * "Geocode now" for a single post (meta box button). Runs the same claim and
 * the same outcome mapping as the background batch, just for one job.
 */
final class GeocodeHandler
{
    public function __construct(
        private readonly GeoJobRepository $repository,
        private readonly ProcessingJob $processing,
    ) {
    }

    public function register(): void
    {
        add_action('wp_ajax_gma_geocode_post', [$this, 'handle']);
    }

    public function handle(): void
    {
        check_ajax_referer('gma_geocode_post', 'nonce');

        $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

        if ($postId <= 0 || ! current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $address = trim((string) get_post_meta($postId, PostMetaBox::META_KEY, true));

        if ($address === '') {
            wp_send_json_error(['message' => 'This post has no address yet.'], 400);
        }

        $now = time();
        $registered = $this->repository->register($postId, $address, $now);
        $job = $this->repository->getById($registered['id']);

        if (in_array($job['status'], [GeoJobRepository::STATUS_NOT_FOUND, GeoJobRepository::STATUS_ERROR], true)) {
            $this->repository->resetForManualRetry((int) $job['id'], $now);
            $job = $this->repository->getById((int) $job['id']);
        }

        if ($job['status'] === GeoJobRepository::STATUS_PENDING) {
            $claimed = $this->repository->claimById(
                (int) $job['id'],
                $now,
                ProcessingJob::LEASE_SECONDS,
                RetryPolicy::MAX_ATTEMPTS
            );

            if ($claimed !== null) {
                $this->processing->process($claimed, $now);
                $job = $this->repository->getById((int) $job['id']);
            }
        }

        if ($job['status'] === GeoJobRepository::STATUS_PROCESSING) {
            wp_send_json_error(['message' => 'This post is already being geocoded.'], 409);
        }

        wp_send_json_success([
            'status' => $job['status'],
            'lat' => $job['lat'] !== null ? (float) $job['lat'] : null,
            'lng' => $job['lng'] !== null ? (float) $job['lng'] : null,
            'error' => $job['error_message'],
            'next_retry_at' => $job['next_retry_at'],
        ]);
    }
}
