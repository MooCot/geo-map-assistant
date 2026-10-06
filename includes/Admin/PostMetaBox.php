<?php

declare(strict_types=1);

namespace GMA\Admin;

use GMA\Repository\GeoJobRepository;

/**
 * Address field on the post editor. Saving the address (re)queues its
 * geocoding job; clearing it removes the job.
 */
final class PostMetaBox
{
    public const META_KEY = 'gma_address';
    public const NONCE_ACTION = 'gma_save_address';
    private const MAX_ADDRESS_LENGTH = 500;

    public function __construct(private readonly GeoJobRepository $repository)
    {
    }

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox']);
        add_action('save_post_post', [$this, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMetaBox(): void
    {
        add_meta_box('gma-geocoding', 'Geocoding', [$this, 'render'], 'post', 'side', 'default');
    }

    public function render(\WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, 'gma_nonce');

        $address = (string) get_post_meta($post->ID, self::META_KEY, true);
        $job = $this->repository->getByPostId($post->ID);
        $status = $job['status'] ?? 'not queued';
        ?>
        <p>
            <label for="gma_address">Address</label>
            <input type="text" id="gma_address" name="gma_address" class="widefat" maxlength="<?php echo self::MAX_ADDRESS_LENGTH; ?>"
                   value="<?php echo esc_attr($address); ?>">
        </p>
        <p><strong>Status:</strong> <span id="gma-geo-status"><?php echo esc_html((string) $status); ?></span></p>
        <p>
            <button type="button" class="button" id="gma-geocode-now" data-post-id="<?php echo (int) $post->ID; ?>">Geocode now</button>
        </p>
        <p class="description">Save the post first, then geocode.</p>
        <?php
    }

    public function save(int $postId, \WP_Post $post): void
    {
        if (! isset($_POST['gma_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gma_nonce'])), self::NONCE_ACTION)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (! current_user_can('edit_post', $postId)) {
            return;
        }

        $address = isset($_POST['gma_address'])
            ? mb_substr(trim(sanitize_text_field(wp_unslash($_POST['gma_address']))), 0, self::MAX_ADDRESS_LENGTH)
            : '';

        if ($address === '') {
            delete_post_meta($postId, self::META_KEY);
            $this->repository->deleteByPost($postId);

            return;
        }

        update_post_meta($postId, self::META_KEY, $address);
        $this->repository->register($postId, $address, time());
    }

    public function enqueueAssets(string $hook): void
    {
        if (! in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        wp_enqueue_script('gma-geocode-box', GMA_PLUGIN_URL . 'assets/geocode-box.js', [], GMA_VERSION, true);
        wp_localize_script('gma-geocode-box', 'gmaGeocodeBox', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gma_geocode_post'),
        ]);
    }
}
