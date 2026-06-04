<?php
/**
 * AutoPurge — invalidates (and re-warms) the right set of URLs when content
 * changes.
 *
 * Hooks:
 *   post_updated            — a post was created/edited (3 args).
 *   future_to_publish       — a scheduled post went live.
 *   wp_update_comment_count — a comment count changed (purge + preload the post).
 *   save_post               — a page-builder template/global/FSE part was saved
 *                             (purge EVERYTHING; gated to those post types only).
 *   acf/save_post           — an ACF options page was saved (purge EVERYTHING).
 *   woocommerce_product_set_stock / _variation_set_stock /
 *   woocommerce_rest_insert_product_object — a product's stock/content changed
 *                             (purge the product + shop + product_cat/_tag terms).
 *
 * For a changed post the affected-URL set is:
 *   - the post's permalink (before + after, if they differ on slug change)
 *   - the site home
 *   - the blog posts page (page_for_posts)
 *   - the post-type archive
 *   - the author archive
 *   - every assigned term's archive across every public taxonomy
 *   - every ancestor term archive (so parent category pages refresh too)
 *
 * The set is de-duplicated, each URL is purged, then queued for preload so a
 * visitor never pays the cold-cache penalty after an edit.
 *
 * Standard WordPress page-cache auto-purge technique.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Builds the affected-URL list on content changes and drives purge + preload.
 */
final class AutoPurge
{
    /** Post statuses we treat as publicly-visible (cache-relevant). */
    private const PUBLIC_STATUSES = ['publish'];

    /** Post types that never affect the front-end cache. */
    private const SKIP_TYPES = ['nav_menu_item', 'revision', 'customize_changeset', 'oembed_cache'];

    /**
     * Page-builder TEMPLATE / GLOBAL / theme-structure post types. Saving ANY of
     * these changes markup that is reused across MANY (often all) front-end pages
     * — a header/footer/global block, a reusable template, a theme part — so a
     * single edit must purge EVERYTHING, not just the edited post's own URL set.
     * Restricted to exactly these types so an ordinary post/page save still takes
     * the cheap per-post purge path.
     *
     * @var list<string>
     */
    private const PURGE_ALL_POST_TYPES = [
        // Elementor (templates + landing pages).
        'elementor_library', 'e-landing-page',
        // Bricks.
        'bricks_template',
        // Beaver Builder.
        'fl-builder-template',
        // Divi (template + theme-builder header/footer/body layouts).
        'et_template', 'et_header_layout', 'et_footer_layout', 'et_body_layout',
        // Oxygen.
        'ct_template',
        // Breakdance.
        'breakdance_template', 'breakdance_header', 'breakdance_footer',
        // Brizy.
        'brizy_template',
        // Gutenberg / FSE site editor.
        'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
    ];

    /**
     * WooCommerce taxonomies whose term archives are catalog/faceted pages and
     * must be flushed when a product's stock or content changes.
     *
     * @var list<string>
     */
    private const WC_PRODUCT_TAXONOMIES = ['product_cat', 'product_tag'];

    private Purge $purge;

    private Preload $preload;

    /**
     * @param Purge   $purge   Disk purger.
     * @param Preload $preload Warmer queue.
     */
    public function __construct(Purge $purge, Preload $preload)
    {
        $this->purge   = $purge;
        $this->preload = $preload;
    }

    /**
     * Bind the WP hooks. Safe to call without WP (guards add_action).
     *
     * @return void
     */
    public function registerHooks(): void
    {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('post_updated', [$this, 'onPostUpdated'], 10, 3);
        add_action('future_to_publish', [$this, 'onFuturePublish'], 10, 1);
        add_action('wp_update_comment_count', [$this, 'onCommentCountUpdated'], 10, 1);

        // Page-builder template / global / FSE structure saves → purge everything
        // (these affect markup reused across the whole site). Gated per-post-type
        // inside the handler so an ordinary post save is unaffected.
        add_action('save_post', [$this, 'onSavePostMaybePurgeAll'], 10, 2);

        // ACF options-page save → the field values can render into any/every
        // template, so a save there invalidates the whole site.
        add_action('acf/save_post', [$this, 'onAcfSavePost'], 20, 1);

        // WooCommerce stock / product changes → targeted catalog purge. Each hook
        // is guarded by class_exists('WooCommerce') in its handler so binding them
        // on a non-WC site is inert.
        if (class_exists('WooCommerce')) {
            add_action('woocommerce_product_set_stock', [$this, 'onWooStockChange'], 10, 1);
            add_action('woocommerce_variation_set_stock', [$this, 'onWooStockChange'], 10, 1);
            add_action('woocommerce_rest_insert_product_object', [$this, 'onWooStockChange'], 10, 1);
        }
    }

    /**
     * post_updated handler: purge + preload the affected URL set.
     *
     * @param int    $postId      The post ID.
     * @param object $postAfter   Post object after the update.
     * @param object $postBefore  Post object before the update.
     * @return void
     */
    public function onPostUpdated(int $postId, $postAfter = null, $postBefore = null): void
    {
        $afterStatus  = is_object($postAfter) && isset($postAfter->post_status) ? (string) $postAfter->post_status : '';
        $beforeStatus = is_object($postBefore) && isset($postBefore->post_status) ? (string) $postBefore->post_status : '';

        // Only act when the post is (or was) public.
        if (!in_array($afterStatus, self::PUBLIC_STATUSES, true)
            && !in_array($beforeStatus, self::PUBLIC_STATUSES, true)
        ) {
            return;
        }

        $type = is_object($postAfter) && isset($postAfter->post_type) ? (string) $postAfter->post_type : '';
        if (in_array($type, self::SKIP_TYPES, true)) {
            return;
        }

        $urls = $this->urlsForPost($postId);
        $this->flush($urls);
    }

    /**
     * future_to_publish handler.
     *
     * @param object $post The post going live.
     * @return void
     */
    public function onFuturePublish($post = null): void
    {
        if (!is_object($post) || !isset($post->ID)) {
            return;
        }
        $type = isset($post->post_type) ? (string) $post->post_type : '';
        if (in_array($type, self::SKIP_TYPES, true)) {
            return;
        }
        $this->flush($this->urlsForPost((int) $post->ID));
    }

    /**
     * wp_update_comment_count handler: purge + preload just the post permalink.
     *
     * @param int $postId Post ID whose comment count changed.
     * @return void
     */
    public function onCommentCountUpdated(int $postId): void
    {
        if (!function_exists('get_permalink')) {
            return;
        }
        $permalink = get_permalink($postId);
        if (is_string($permalink) && $permalink !== '') {
            $this->flush([$permalink]);
        }
    }

    /**
     * save_post handler: purge EVERYTHING, but ONLY when the saved post is a
     * page-builder template / global / FSE structure post type (whose markup is
     * reused across many pages). For every other post type this is a no-op — the
     * ordinary per-post purge (onPostUpdated) handles those — so a normal page
     * save never triggers a full-site flush.
     *
     * @param int    $postId The saved post ID.
     * @param object $post    The post object (WP passes WP_Post).
     * @return void
     */
    public function onSavePostMaybePurgeAll(int $postId, $post = null): void
    {
        $type = '';
        if (is_object($post) && isset($post->post_type)) {
            $type = (string) $post->post_type;
        } elseif (function_exists('get_post_type')) {
            $type = (string) get_post_type($postId);
        }
        if ($type === '' || !in_array($type, self::PURGE_ALL_POST_TYPES, true)) {
            return;
        }
        // Skip autosaves/revisions — only a real save of the template matters.
        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($postId)) {
            return;
        }
        if (function_exists('wp_is_post_revision') && wp_is_post_revision($postId)) {
            return;
        }

        $this->purge->purgeEverything();
    }

    /**
     * acf/save_post handler: purge everything when an ACF OPTIONS page is saved.
     * ACF options values can be rendered into any template (headers, footers,
     * global blocks), so an options change invalidates the whole site. A normal
     * post's ACF fields are already covered by the per-post purge, so we ignore
     * numeric (post-ID) targets and only act on the `options` / `option` pseudo-
     * post-id that ACF passes for an options page save.
     *
     * @param int|string $acfPostId The ACF save target (post ID, or 'options').
     * @return void
     */
    public function onAcfSavePost($acfPostId = 0): void
    {
        if (!function_exists('acf_get_setting') && !class_exists('ACF')) {
            return;
        }
        $target = is_string($acfPostId) ? strtolower($acfPostId) : (string) $acfPostId;
        // ACF options pages save under 'options' or 'option'; anything numeric is
        // a real post (handled by onPostUpdated) and must NOT purge-all.
        if ($target !== 'options' && $target !== 'option') {
            return;
        }
        $this->purge->purgeEverything();
    }

    /**
     * WooCommerce stock / product change handler. Purges + re-warms the affected
     * catalog surface: the product permalink, the shop page, and every assigned
     * product_cat / product_tag term archive (faceted catalog pages). Accepts the
     * various shapes WooCommerce passes (a WC_Product for the stock hooks, a
     * WP_Post object for the REST hook, or a bare id).
     *
     * @param mixed $product WC_Product, WP_Post, or product id.
     * @return void
     */
    public function onWooStockChange($product = null): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $productId = $this->resolveProductId($product);
        if ($productId <= 0) {
            return;
        }

        $urls = [];

        if (function_exists('get_permalink')) {
            $permalink = get_permalink($productId);
            if (is_string($permalink) && $permalink !== '') {
                $urls[] = $permalink;
            }
        }

        // Shop page.
        if (function_exists('wc_get_page_id') && function_exists('get_permalink')) {
            $shopId = (int) wc_get_page_id('shop');
            if ($shopId > 0) {
                $shop = get_permalink($shopId);
                if (is_string($shop) && $shop !== '') {
                    $urls[] = $shop;
                }
            }
        }

        // product_cat / product_tag term archives for this product.
        if (function_exists('wp_get_post_terms') && function_exists('get_term_link')) {
            foreach (self::WC_PRODUCT_TAXONOMIES as $taxonomy) {
                $terms = wp_get_post_terms($productId, $taxonomy);
                if (!is_array($terms)) {
                    continue;
                }
                foreach ($terms as $term) {
                    $termId = 0;
                    if (is_object($term) && property_exists($term, 'term_id') && is_scalar($term->term_id)) {
                        $termId = (int) $term->term_id;
                    } elseif (is_int($term) || is_string($term)) {
                        $termId = (int) $term;
                    }
                    if ($termId <= 0) {
                        continue;
                    }
                    $link = get_term_link($termId, $taxonomy);
                    if (is_string($link) && $link !== '') {
                        $urls[] = $link;
                    }
                }
            }
        }

        $this->flush($urls);
    }

    /**
     * Coerce the many shapes WooCommerce hands its product hooks into a product id.
     *
     * @param mixed $product WC_Product, WP_Post, or scalar id.
     * @return int Product id, or 0 when undeterminable.
     */
    private function resolveProductId($product): int
    {
        if (is_object($product) && method_exists($product, 'get_id')) {
            return (int) $product->get_id();
        }
        if (is_object($product) && isset($product->ID)) {
            return (int) $product->ID;
        }
        if (is_scalar($product)) {
            return (int) $product;
        }
        return 0;
    }

    /**
     * Build the full affected-URL list for a post. Pure with respect to its
     * inputs — relies only on WP getter functions, each guarded so the method is
     * safe (returns a partial list) outside a full WP context.
     *
     * @param int $postId Post ID.
     * @return list<string> De-duplicated, non-empty URLs.
     */
    public function urlsForPost(int $postId): array
    {
        $urls = [];

        if (function_exists('get_permalink')) {
            $permalink = get_permalink($postId);
            if (is_string($permalink) && $permalink !== '') {
                $urls[] = $permalink;
            }
        }

        if (function_exists('home_url')) {
            $home = home_url('/');
            if (is_string($home)) {
                $urls[] = $home;
            }
        }

        // Blog posts page.
        if (function_exists('get_option') && function_exists('get_permalink')) {
            $postsPage = (int) get_option('page_for_posts');
            if ($postsPage > 0) {
                $u = get_permalink($postsPage);
                if (is_string($u) && $u !== '') {
                    $urls[] = $u;
                }
            }
        }

        $postType = function_exists('get_post_type') ? (string) get_post_type($postId) : 'post';

        // Post-type archive.
        if ($postType !== '' && function_exists('get_post_type_archive_link')) {
            $archive = get_post_type_archive_link($postType);
            if (is_string($archive) && $archive !== '') {
                $urls[] = $archive;
            }
        }

        // Author archive.
        if (function_exists('get_post_field') && function_exists('get_author_posts_url')) {
            $authorId = (int) get_post_field('post_author', $postId);
            if ($authorId > 0) {
                $authorUrl = get_author_posts_url($authorId);
                if (is_string($authorUrl) && $authorUrl !== '') {
                    $urls[] = $authorUrl;
                }
            }
        }

        // Every term archive (and ancestors) across every public taxonomy.
        $urls = array_merge($urls, $this->termUrls($postId, $postType));

        // De-duplicate, drop empties.
        $urls = array_values(array_unique(array_filter($urls, static fn ($u): bool => is_string($u) && $u !== '')));

        /**
         * Allow operators / other plugins to extend the auto-purge URL set.
         *
         * @param list<string> $urls   The computed URL list.
         * @param int          $postId The post being purged.
         */
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('wpmgr_cache_auto_purge_urls', $urls, $postId);
            if (is_array($filtered)) {
                $urls = array_values(array_filter(
                    array_map('strval', $filtered),
                    static fn (string $u): bool => $u !== ''
                ));
            }
        }

        return $urls;
    }

    /**
     * Collect term-archive URLs (including ancestors) for a post.
     *
     * @param int    $postId   Post ID.
     * @param string $postType Post type.
     * @return list<string>
     */
    private function termUrls(int $postId, string $postType): array
    {
        $urls = [];
        if (!function_exists('get_object_taxonomies') || !function_exists('wp_get_post_terms')) {
            return $urls;
        }

        $taxonomies = get_object_taxonomies($postType !== '' ? $postType : 'post');
        if (!is_array($taxonomies)) {
            return $urls;
        }

        foreach ($taxonomies as $taxonomy) {
            $taxonomy = (string) $taxonomy;
            // Only public taxonomies generate front-end archive URLs.
            if (function_exists('is_taxonomy_viewable')) {
                if (!is_taxonomy_viewable($taxonomy)) {
                    continue;
                }
            }

            $terms = wp_get_post_terms($postId, $taxonomy);
            if (!is_array($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $termId = 0;
                if (is_object($term) && property_exists($term, 'term_id') && is_scalar($term->term_id)) {
                    $termId = (int) $term->term_id;
                } elseif (is_int($term) || is_string($term)) {
                    $termId = (int) $term;
                }
                if ($termId <= 0) {
                    continue;
                }

                if (function_exists('get_term_link')) {
                    $link = get_term_link($termId, $taxonomy);
                    if (is_string($link) && $link !== '') {
                        $urls[] = $link;
                    }
                }

                // Ancestor term archives (parent category pages, etc.).
                if (function_exists('get_ancestors') && function_exists('get_term_link')) {
                    $ancestors = get_ancestors($termId, $taxonomy);
                    if (is_array($ancestors)) {
                        foreach ($ancestors as $ancestorId) {
                            $ancestorLink = get_term_link((int) $ancestorId, $taxonomy);
                            if (is_string($ancestorLink) && $ancestorLink !== '') {
                                $urls[] = $ancestorLink;
                            }
                        }
                    }
                }
            }
        }

        return $urls;
    }

    /**
     * Purge then queue a preload for each URL in the set.
     *
     * @param list<string> $urls URLs to flush.
     * @return void
     */
    private function flush(array $urls): void
    {
        $urls = array_values(array_unique(array_filter($urls, static fn ($u): bool => is_string($u) && $u !== '')));
        if ($urls === []) {
            return;
        }

        foreach ($urls as $url) {
            $this->purge->purgeUrl($url);
        }
        // Targeted purge-and-preload: enqueue at the higher-urgency priority so a
        // just-edited page is re-warmed ahead of any full-site enumeration backlog.
        $this->preload->queue($urls, Preload::PRIORITY_TARGETED);
    }
}
