<?php
/**
 * PageCacheControlMetaBox — per-page cache and optimization controls.
 *
 * Registers a "WPMgr Cache" meta box on every public post type's edit screen.
 * Two checkboxes let editors exclude a specific page from:
 *
 *   - the page cache  (_wpmgr_no_cache)
 *   - the optimization pipeline  (_wpmgr_no_optimize)
 *
 * Both meta values are stored as '' (off) or '1' (on). The leading underscore
 * keeps the keys protected so they do not appear in custom-field UIs.
 *
 * On a real save the post's cached URL is purged via Purge::purgeUrl so the
 * next request picks up the new exclusion setting immediately.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Admin meta box: per-page cache / optimization opt-out controls.
 */
final class PageCacheControlMetaBox
{
    /**
     * Post-meta key that marks a page as excluded from the page cache.
     * Value is '' (cacheable) or '1' (excluded).
     */
    public const META_NO_CACHE = '_wpmgr_no_cache';

    /**
     * Post-meta key that marks a page as excluded from the optimization pipeline.
     * Value is '' (optimized) or '1' (excluded).
     */
    public const META_NO_OPTIMIZE = '_wpmgr_no_optimize';

    /**
     * Nonce action used when verifying the save_post nonce.
     */
    private const NONCE_ACTION = 'wpmgr_page_cache_control_save';

    /**
     * Nonce field name written into the form.
     */
    private const NONCE_FIELD = 'wpmgr_pcc_nonce';

    /**
     * Bind WordPress hooks.
     *
     * @return void
     */
    public function registerHooks(): void
    {
        add_action('add_meta_boxes', [$this, 'registerMetaBox']);
        add_action('save_post', [$this, 'saveMetaBox'], 10, 2);
    }

    /**
     * Register the meta box on every public post type.
     *
     * @return void
     */
    public function registerMetaBox(): void
    {
        foreach (get_post_types(['public' => true]) as $post_type) {
            add_meta_box(
                'wpmgr_page_cache_control',
                'WPMgr Cache',
                [$this, 'renderMetaBox'],
                $post_type,
                'side',
                'default',
                ['__block_editor_compatible_meta_box' => true]
            );
        }
    }

    /**
     * Render the meta box HTML.
     *
     * @param \WP_Post $post Current post object.
     * @return void
     */
    public function renderMetaBox(\WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $no_cache    = get_post_meta($post->ID, self::META_NO_CACHE, true) === '1';
        $no_optimize = get_post_meta($post->ID, self::META_NO_OPTIMIZE, true) === '1';
        ?>
        <p>
            <label>
                <input type="checkbox"
                       name="wpmgr_no_cache"
                       value="1"
                       <?php checked($no_cache); ?>>
                <?php echo esc_html__("Don't cache this page", 'wpmgr-agent'); ?>
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox"
                       name="wpmgr_no_optimize"
                       value="1"
                       <?php checked($no_optimize); ?>>
                <?php echo esc_html__('Disable optimizations on this page', 'wpmgr-agent'); ?>
            </label>
        </p>
        <?php
    }

    /**
     * Persist meta values when a post is saved.
     *
     * Guards (in order): autosave, revision, nonce, capability.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @return void
     */
    public function saveMetaBox(int $post_id, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }
        if (
            !isset($_POST[self::NONCE_FIELD])
            || !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])),
                self::NONCE_ACTION
            )
        ) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $no_cache    = !empty($_POST['wpmgr_no_cache']) ? '1' : '';
        $no_optimize = !empty($_POST['wpmgr_no_optimize']) ? '1' : '';

        update_post_meta($post_id, self::META_NO_CACHE, $no_cache);
        update_post_meta($post_id, self::META_NO_OPTIMIZE, $no_optimize);

        // Purge the page from the disk cache so the exclusion takes effect
        // on the very next request. Best-effort: a purge failure must never
        // block the post save.
        $permalink = get_permalink($post_id);
        if (is_string($permalink) && $permalink !== '') {
            try {
                $cache_root = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/cache/wpmgr' : '';
                if ($cache_root !== '') {
                    (new Purge($cache_root))->purgeUrl($permalink);
                }
            } catch (\Throwable $e) {
                // Intentionally swallowed — a purge failure must not interrupt save.
            }
        }
    }
}
