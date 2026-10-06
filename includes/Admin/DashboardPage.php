<?php

declare(strict_types=1);

namespace GMA\Admin;

use GMA\Repository\GeoJobRepository;

/**
 * Admin dashboard: status cards, the latest jobs table (polled every few
 * seconds), and the buttons that drive the queue. Queue actions live in
 * QueueHandler; this page only renders and handles manual retry.
 */
final class DashboardPage
{
    public const SLUG = 'geo-map-assistant';
    private const RECENT_LIMIT = 50;
    private const POLL_MS = 5000;

    public function __construct(private readonly GeoJobRepository $repository)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_gma_dashboard_data', [$this, 'ajaxDashboardData']);
        add_action('wp_ajax_gma_retry_job', [$this, 'ajaxRetryJob']);
    }

    public function addMenuPage(): void
    {
        add_menu_page(
            'Geo Map Assistant',
            'Geo Map',
            'edit_posts',
            self::SLUG,
            [$this, 'render'],
            'dashicons-location-alt',
            27
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::SLUG) {
            return;
        }

        wp_enqueue_style('gma-admin', GMA_PLUGIN_URL . 'assets/admin.css', [], GMA_VERSION);
        wp_enqueue_script('gma-dashboard', GMA_PLUGIN_URL . 'assets/dashboard.js', [], GMA_VERSION, true);

        wp_localize_script('gma-dashboard', 'gmaDashboard', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'dataNonce' => wp_create_nonce('gma_dashboard_data'),
            'retryNonce' => wp_create_nonce('gma_retry_job'),
            'queueNonce' => wp_create_nonce('gma_queue_all'),
            'batchNonce' => wp_create_nonce('gma_process_batch'),
            'pollMs' => self::POLL_MS,
        ]);
    }

    public function render(): void
    {
        if (! current_user_can('edit_posts')) {
            return;
        }

        $counts = $this->repository->getCounts();
        $jobs = $this->enrich($this->repository->getRecent(self::RECENT_LIMIT));

        require GMA_PLUGIN_DIR . '/includes/Admin/views/dashboard.php';
    }

    public function ajaxDashboardData(): void
    {
        check_ajax_referer('gma_dashboard_data', 'nonce');

        if (! current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        wp_send_json_success([
            'counts' => $this->repository->getCounts(),
            'jobs' => $this->enrich($this->repository->getRecent(self::RECENT_LIMIT)),
        ]);
    }

    public function ajaxRetryJob(): void
    {
        check_ajax_referer('gma_retry_job', 'nonce');

        if (! current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $jobId = isset($_POST['job_id']) ? (int) $_POST['job_id'] : 0;

        if ($jobId <= 0) {
            wp_send_json_error(['message' => 'Missing job_id.'], 400);
        }

        if (! $this->repository->resetForManualRetry($jobId, time())) {
            wp_send_json_error(['message' => 'Job is not in a terminal state (already retried or picked up).'], 409);
        }

        wp_send_json_success(['job_id' => $jobId]);
    }

    /**
     * @param array<int, array<string, mixed>> $jobs
     * @return array<int, array<string, mixed>>
     */
    private function enrich(array $jobs): array
    {
        foreach ($jobs as &$job) {
            $post = get_post((int) $job['post_id']);
            $job['post_title'] = $post ? get_the_title($post) : "#{$job['post_id']} (deleted)";
            $job['edit_url'] = $post ? get_edit_post_link($post->ID, 'raw') : '';
            $job['retryable'] = in_array($job['status'], [
                GeoJobRepository::STATUS_ERROR,
                GeoJobRepository::STATUS_NOT_FOUND,
            ], true);
        }

        return $jobs;
    }
}
