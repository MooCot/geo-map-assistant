<?php
/**
 * @var array<string, int> $counts
 * @var array<int, array<string, mixed>> $jobs
 */
?>
<div class="wrap gma-dashboard">
    <h1>Geo Map Assistant</h1>

    <div class="gma-cards">
        <?php foreach (['pending', 'processing', 'done', 'not_found', 'error', 'total'] as $key) : ?>
            <div class="gma-card">
                <span class="gma-card-label"><?php echo esc_html(str_replace('_', ' ', $key)); ?></span>
                <span class="gma-card-value" data-count="<?php echo esc_attr($key); ?>"><?php echo (int) $counts[$key]; ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="gma-actions">
        <button type="button" class="button button-primary" id="gma-queue-all">Queue all posts with an address</button>
        <button type="button" class="button" id="gma-process-batch">Process batch now</button>
        <span id="gma-dashboard-feedback" class="description"></span>
    </p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th>Post</th>
                <th>Address</th>
                <th>Status</th>
                <th>Attempts</th>
                <th>Coordinates</th>
                <th>Error / next retry</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody id="gma-jobs-body">
            <?php foreach ($jobs as $job) : ?>
                <tr>
                    <td>
                        <?php if ($job['edit_url']) : ?>
                            <a href="<?php echo esc_url($job['edit_url']); ?>"><?php echo esc_html($job['post_title']); ?></a>
                        <?php else : ?>
                            <?php echo esc_html($job['post_title']); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($job['address']); ?></td>
                    <td><?php echo esc_html($job['status']); ?></td>
                    <td><?php echo (int) $job['attempts']; ?></td>
                    <td>
                        <?php echo $job['lat'] !== null ? esc_html($job['lat'] . ', ' . $job['lng']) : '—'; ?>
                    </td>
                    <td>
                        <?php echo esc_html((string) ($job['error_message'] ?? '')); ?>
                        <?php if ($job['next_retry_at']) : ?>
                            <br><small>next: <?php echo esc_html($job['next_retry_at']); ?> UTC</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($job['retryable']) : ?>
                            <button type="button" class="button gma-retry" data-job-id="<?php echo (int) $job['id']; ?>">Retry</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($jobs === []) : ?>
                <tr><td colspan="7">No geocoding jobs yet. Add an address to a post, or use "Queue all".</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
