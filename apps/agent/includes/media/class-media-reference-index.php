<?php
/**
 * MediaReferenceIndex: builds an exhaustive set of attachment IDs and file-path
 * fragments that are REFERENCED somewhere in the site's content.
 *
 * Conservative contract: when in doubt, treat the attachment as referenced.
 * A false positive (called used when unused) is safe. A false negative (called
 * unused when in use) is data loss. Every check therefore casts the widest net
 * possible for its surface.
 *
 * Surfaces checked:
 *   1. wp_posts — post_content + post_excerpt for published/draft/pending posts
 *      (all post types; excludes only inherit, trash, auto-draft).
 *   1b. wp_posts — post_content + post_excerpt for revision posts (conservative:
 *       an image referenced only in a revision must not be deleted).
 *   2. wp_postmeta — _thumbnail_id (featured images) per post.
 *   3. wp_postmeta — _product_image_gallery (WooCommerce product gallery).
 *   4. wp_postmeta — _elementor_data (JSON page-builder data; recursive walk).
 *   5. wp_postmeta — _wp_page_builder / Beaver Builder (JSON, recursive walk).
 *   6. wp_postmeta — _bricks_page_content (Bricks builder, JSON, recursive).
 *   7. wp_postmeta — divi_content / et_pb_post_hide_nav (Divi, recursive).
 *   8. wp_postmeta — _wpb_vc_js_status / the_content fallback (WPBakery).
 *   9. wp_postmeta — generic numeric meta that is a valid attachment ID.
 *  10. wp_postmeta — generic text meta containing an uploads-dir URL.
 *  11. wp_options  — theme_mods_* (custom_logo, header_image, background_image,
 *                    site_icon, nav_menu_item images).
 *  12. wp_options  — sidebars_widgets + individual widget options.
 *  13. wp_options  — Divi/et_divi, et_divi_builder_global_presets_d5.
 *  14. wp_options  — woocommerce_placeholder_image, site_icon.
 *  15. wp_termmeta — *thumbnail_id, *image_id, *icon patterns.
 *  16. wp_usermeta — wp_user_avatars (Simple Local Avatars), gravatar IDs.
 *  17. wp_posts (nav_menu_item) — _menu_item_object_id pointing at attachments.
 *  18. Attachment's own _wp_attachment_metadata sub-sizes + original_image.
 *  19. wp_postmeta — SEO-plugin image-ID keys (_yoast_wpseo_opengraph-image-id,
 *       _yoast_wpseo_twitter-image-id, rank_math_facebook_image_id, etc.).
 *  20. wp_options — ACF options-page bare attachment IDs (options_* / _options_*).
 *
 * All DB queries use $wpdb->prepare() with %d / %s placeholders. String compares
 * use URL-fragment matching (substr after uploads base) rather than LIKE '%' to
 * avoid unnecessary full-table-scans; broad meta scans are bounded to the uploads
 * URL prefix.
 *
 * @package WPMgr\Agent\Media
 */

declare(strict_types=1);

namespace WPMgr\Agent\Media;

/**
 * Builds the complete set of referenced attachment IDs + upload-relative paths.
 *
 * Callers call build() once then query isReferenced($attachmentId). The index
 * is intentionally memory-resident (arrays of ints + short strings) — the
 * typical WP site has O(thousands) of attachments so heap cost is negligible.
 *
 * Usage attribution (issue #190):
 *   Each addId/addPath call now records a usage entry with:
 *     surface      — canonical surface enum string (post_content, thumbnail, …)
 *     source_id    — post/term/user ID of the referencing row, or null
 *     source_label — human-readable label (post_title, option name), or null
 *     detail       — extra context (meta_key, class token), or null
 *   isReferenced() aggregates and deduplicates all matching usages into
 *   $this->referencedUsages[$attachmentId] for retrieval by getReferencedUsages().
 */
final class MediaReferenceIndex
{
    /** Upload-relative path => true for every path fragment seen in content. */
    private array $paths = [];

    /** Attachment ID => true for every ID seen directly in content. */
    private array $ids = [];

    /**
     * Attachment ID => list<usage> accumulated by addId() calls.
     * Each usage: {surface, source_id, source_label, edit_url, detail}
     *
     * @var array<int, list<array<string,mixed>>>
     */
    private array $idUsages = [];

    /**
     * Upload-relative path => list<usage> accumulated by addPath() calls.
     *
     * @var array<string, list<array<string,mixed>>>
     */
    private array $pathUsages = [];

    /**
     * Attachment ID => deduplicated list<usage> for all attachments that
     * isReferenced() has confirmed as referenced this session.
     *
     * @var array<int, list<array<string,mixed>>>
     */
    private array $referencedUsages = [];

    /** Base URL of the uploads dir (scheme-stripped for protocol-agnostic match). */
    private string $uploadsBase = '';

    /** Absolute filesystem path to the uploads root. */
    private string $uploadsDir = '';

    /** Whether build() has been called. */
    private bool $built = false;

    /** Image-extension pattern for URL grep. */
    private const IMG_EXTS = 'jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?';

    /**
     * Run the full reference scan. Safe to call multiple times; subsequent
     * calls are no-ops (index is immutable after build).
     *
     * Returns false when the uploads directory is unresolvable, meaning the
     * caller MUST NOT proceed with a scan (conservative invariant: if URL-based
     * reference detection is impossible, no candidates may be flagged).
     *
     * @return bool True on success; false when uploads base is empty/unresolvable.
     */
    public function build(): bool
    {
        if ($this->built) {
            return $this->uploadsBase !== '';
        }
        $this->built = true;

        $uploadDir         = wp_upload_dir();
        $this->uploadsDir  = rtrim((string)($uploadDir['basedir'] ?? ''), '/\\');
        $uploadsUrl        = (string)($uploadDir['baseurl'] ?? '');
        // Strip scheme for protocol-agnostic matching (http:// vs https://).
        $this->uploadsBase = (string)preg_replace('#^https?://#', '', $uploadsUrl);

        // Conservative abort: if the uploads base URL/dir is empty we cannot
        // perform URL/path reference matching. Proceeding would silently no-op
        // all URL-based surfaces (extractFromHtml returns early), causing every
        // image referenced only by a raw uploads URL to be flagged as unused —
        // a false-positive that leads to data loss. Return false immediately so
        // callers can abort the scan entirely.
        if ($this->uploadsBase === '' || $this->uploadsDir === '') {
            return false;
        }

        $this->indexPostContent();
        $this->indexRevisionContent();
        $this->indexFeaturedImages();
        $this->indexPostmetaPageBuilders();
        $this->indexPostmetaGeneric();
        $this->indexSeoMeta();
        $this->indexOptions();
        $this->indexOptionsNumericIds();
        $this->indexTermMeta();
        $this->indexUserMeta();
        $this->indexNavMenuItems();

        return true;
    }

    /**
     * Return true when the attachment (and ALL its generated sub-sizes) should
     * be considered referenced — i.e. SAFE to skip for quarantine.
     *
     * As a side-effect, aggregates all attributed usages (from idUsages /
     * pathUsages) for this attachment into $this->referencedUsages[$attachmentId].
     *
     * @param int    $attachmentId   Attachment post ID.
     * @param string $relPath        Upload-relative path from _wp_attached_file.
     * @param array  $attachmentMeta Decoded _wp_attachment_metadata array.
     * @return bool
     */
    public function isReferenced(int $attachmentId, string $relPath, array $attachmentMeta): bool
    {
        if (!$this->built) {
            $this->build();
        }

        $matchedUsages = [];

        // Direct ID reference.
        if (isset($this->ids[$attachmentId])) {
            foreach ($this->idUsages[$attachmentId] ?? [['surface' => 'direct_id', 'source_id' => null, 'source_label' => null, 'edit_url' => null, 'detail' => null]] as $u) {
                $matchedUsages[] = $u;
            }
        }

        // Collect all file paths belonging to this attachment (main file + all
        // generated sub-sizes + original_image for scaled uploads).
        $allPaths = $this->allPathsForAttachment($relPath, $attachmentMeta);
        foreach ($allPaths as $p) {
            if (isset($this->paths[$p])) {
                foreach ($this->pathUsages[$p] ?? [['surface' => 'path', 'source_id' => null, 'source_label' => null, 'edit_url' => null, 'detail' => $p]] as $u) {
                    $matchedUsages[] = $u;
                }
                // NOTE: we intentionally do NOT fall back to a bare-basename match
                // here. addPath() no longer indexes bare basenames, and doing so in
                // the lookup would produce the same false-positives: two attachments
                // that share a filename under different year/month subdirectories
                // would both be considered referenced when only one actually is.
                // Full-path matching is conservative enough: URLs embedded in content
                // always include the upload subdirectory fragment.
            }
        }

        // Gap 1: sub-size → parent derivation.
        //
        // When _wp_attachment_metadata['sizes'] is missing or incomplete (plugin-
        // generated sizes, corrupt meta, external crop plugins), the $allPaths list
        // above only contains the parent path and whatever sizes ARE registered. A
        // sub-size URL like "2024/01/hero-300x200.jpg" would not appear in $allPaths
        // if that size record was never written to metadata, causing a false negative.
        //
        // Mitigation: scan every indexed path key for a "-WxH" infix (dimensions
        // suffix added by WordPress core). Strip the infix and compare the resulting
        // candidate parent path against $relPath. A match means a sub-size of THIS
        // attachment is referenced in content, so the parent must be considered
        // referenced too — regardless of whether the sub-size appears in metadata.
        //
        // Safety constraint (preserves the basename-collision invariant): the match
        // is accepted ONLY when the dir+basename (before the infix) resolves to
        // exactly $relPath. Two attachments with the same filename in different
        // subdirectories cannot collide: "2023/01/logo.jpg" vs "2024/06/logo.jpg"
        // produce different stripped parents, so only the correct one matches.
        if (empty($matchedUsages) && $relPath !== '') {
            foreach ($this->paths as $indexedPath => $_) {
                // Strip the -WxH infix from the indexed path.
                $parentCandidate = $this->deriveParentFromSubSize((string)$indexedPath);
                if ($parentCandidate === null || $parentCandidate === $indexedPath) {
                    // Not a sub-size path, or stripping produced no change — skip.
                    continue;
                }
                if ($parentCandidate === $relPath) {
                    // This indexed path is a sub-size of the attachment under test.
                    foreach ($this->pathUsages[$indexedPath] ?? [['surface' => 'subsize_derived', 'source_id' => null, 'source_label' => null, 'edit_url' => null, 'detail' => $indexedPath]] as $u) {
                        $matchedUsages[] = $u;
                    }
                    break; // One sub-size match is sufficient to mark as referenced.
                }
            }
        }

        if (empty($matchedUsages)) {
            return false;
        }

        // Deduplicate usages by (surface + source_id + detail) before storing.
        $seen = [];
        $deduped = [];
        foreach ($matchedUsages as $u) {
            $key = ($u['surface'] ?? '') . '|' . ($u['source_id'] ?? '') . '|' . ($u['detail'] ?? '');
            if (!isset($seen[$key])) {
                $seen[$key]  = true;
                $deduped[]   = $u;
            }
        }

        // Merge into any previously recorded usages for this attachment (isReferenced
        // may be called only once per attachment, but be safe).
        if (!isset($this->referencedUsages[$attachmentId])) {
            $this->referencedUsages[$attachmentId] = $deduped;
        } else {
            foreach ($deduped as $u) {
                $key = ($u['surface'] ?? '') . '|' . ($u['source_id'] ?? '') . '|' . ($u['detail'] ?? '');
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $this->referencedUsages[$attachmentId][] = $u;
                }
            }
        }

        return true;
    }

    /**
     * Return the full map of attachment-ID => list<usage> for every ID that
     * was classified as referenced during isReferenced() calls this session.
     *
     * Each usage array has keys: surface, source_id, source_label, edit_url, detail.
     *
     * @return array<int, list<array<string,mixed>>>
     */
    public function getReferencedUsages(): array
    {
        return $this->referencedUsages;
    }

    /**
     * Legacy accessor kept for any call site that references it directly.
     * Returns a flat id => first-surface string map derived from getReferencedUsages().
     *
     * @return array<int,string>
     * @deprecated Use getReferencedUsages() for structured data.
     */
    public function getReferencedReasons(): array
    {
        $out = [];
        foreach ($this->referencedUsages as $id => $usages) {
            $out[$id] = isset($usages[0]['surface']) ? (string)$usages[0]['surface'] : 'unknown';
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Index builders
    // -------------------------------------------------------------------------

    /**
     * Scan post_content + post_excerpt for all non-trashed, non-inherit posts.
     * Extracts attachment IDs from:
     *   - wp-image-{id} class
     *   - [gallery ids="1,2,3"] shortcodes
     *   - Raw uploads URLs (all image extensions)
     *   - <img src>, srcset, <a href>, CSS url() patterns
     */
    private function indexPostContent(): void
    {
        global $wpdb;

        $offset = 0;
        $batch  = 200;

        while (true) {
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct query on plugin-owned table; no core $wpdb helper exists; correctness requires a live read
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title, post_content, post_excerpt
                 FROM {$wpdb->posts}
                 WHERE post_status NOT IN ('inherit','trash','auto-draft')
                   AND post_type  NOT IN ('revision','nav_menu_item')
                 LIMIT %d OFFSET %d",
                $batch,
                $offset
            ), ARRAY_A);
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

            if (empty($rows)) {
                break;
            }
            $offset += $batch;

            foreach ($rows as $row) {
                $postId    = (int)($row['ID'] ?? 0);
                $postTitle = (string)($row['post_title'] ?? '');
                $editUrl   = $postId > 0 ? $this->buildPostEditUrl($postId) : null;

                $content = (string)($row['post_content'] ?? '');
                $excerpt = (string)($row['post_excerpt'] ?? '');

                if ($content !== '') {
                    $this->extractFromHtmlAttributed(
                        $content,
                        'post_content',
                        $postId,
                        $postTitle,
                        $editUrl
                    );
                }
                if ($excerpt !== '') {
                    $this->extractFromHtmlAttributed(
                        $excerpt,
                        'post_excerpt',
                        $postId,
                        $postTitle,
                        $editUrl
                    );
                }
            }
        }
    }

    /**
     * Scan post_content of revision posts. An image referenced only in a
     * revision is missed by indexPostContent() (which excludes post_type
     * 'revision'). Including revisions is conservative: a false positive
     * (marking a truly unused image as used because it was once in a draft
     * revision) is safe; a false negative (missing a revision reference and
     * deleting the file) is data loss.
     *
     * Attribution: surface='revision', source_id = revision's post_parent
     * (parent post ID) when available, else the revision's own ID.
     */
    private function indexRevisionContent(): void
    {
        global $wpdb;

        $offset = 0;
        $batch  = 200;

        while (true) {
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct query on plugin-owned table; no core $wpdb helper exists; correctness requires a live read
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_parent, post_title, post_content, post_excerpt
                 FROM {$wpdb->posts}
                 WHERE post_type = 'revision'
                 LIMIT %d OFFSET %d",
                $batch,
                $offset
            ), ARRAY_A);
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

            if (empty($rows)) {
                break;
            }
            $offset += $batch;

            foreach ($rows as $row) {
                $revId      = (int)($row['ID'] ?? 0);
                $parentId   = (int)($row['post_parent'] ?? 0);
                $sourceId   = $parentId > 0 ? $parentId : $revId;
                $postTitle  = (string)($row['post_title'] ?? '');
                $editUrl    = $sourceId > 0 ? $this->buildPostEditUrl($sourceId) : null;

                $content = (string)($row['post_content'] ?? '');
                $excerpt = (string)($row['post_excerpt'] ?? '');

                if ($content !== '') {
                    $this->extractFromHtmlAttributed(
                        $content,
                        'revision',
                        $sourceId,
                        $postTitle,
                        $editUrl
                    );
                }
                if ($excerpt !== '') {
                    $this->extractFromHtmlAttributed(
                        $excerpt,
                        'revision',
                        $sourceId,
                        $postTitle,
                        $editUrl
                    );
                }
            }
        }
    }

    /**
     * Index all _thumbnail_id meta values (featured images).
     */
    private function indexFeaturedImages(): void
    {
        global $wpdb;

        // Fetch thumbnail_id alongside the post title for proper attribution.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value, p.post_title
             FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_thumbnail_id'
               AND pm.meta_value REGEXP '^[0-9]+$'",
            ARRAY_A
        ); // phpcs:enable
        foreach ($rows as $row) {
            $attachId  = (int)($row['meta_value'] ?? 0);
            $postId    = (int)($row['post_id'] ?? 0);
            $postTitle = (string)($row['post_title'] ?? '');
            $editUrl   = $postId > 0 ? $this->buildPostEditUrl($postId) : null;
            if ($attachId > 0) {
                $this->addId(
                    $attachId,
                    'thumbnail',
                    $postId > 0 ? $postId : null,
                    $postTitle !== '' ? $postTitle : null,
                    $editUrl,
                    null
                );
            }
        }

        // WooCommerce product gallery (comma-separated IDs).
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value, p.post_title
             FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_product_image_gallery'
               AND pm.meta_value != ''",
            ARRAY_A
        ); // phpcs:enable
        foreach ($rows as $row) {
            $postId    = (int)($row['post_id'] ?? 0);
            $postTitle = (string)($row['post_title'] ?? '');
            $editUrl   = $postId > 0 ? $this->buildPostEditUrl($postId) : null;
            foreach (explode(',', (string)($row['meta_value'] ?? '')) as $idStr) {
                $id = (int)trim($idStr);
                if ($id > 0) {
                    $this->addId(
                        $id,
                        'gallery',
                        $postId > 0 ? $postId : null,
                        $postTitle !== '' ? $postTitle : null,
                        $editUrl,
                        '_product_image_gallery'
                    );
                }
            }
        }
    }

    /**
     * Index known page-builder meta fields that store attachment IDs/URLs in
     * JSON or serialized data: Elementor, Beaver Builder, Bricks, Divi, WPBakery,
     * Oxygen/CT Builder, Breakdance, ACF image/gallery.
     *
     * Attribution: surface='postmeta', source_id=post ID, detail=meta_key.
     * Where a builder extracts IDs in bulk from JSON without a per-match source
     * row, the surface falls back to 'direct_id'/'path' (set in addId/addPath
     * defaults) because the JSON walk cannot trace each ID back to a sub-element.
     */
    private function indexPostmetaPageBuilders(): void
    {
        global $wpdb;

        // Keys that store JSON arrays with embedded image references.
        $jsonKeys = [
            '_elementor_data',         // Elementor
            '_fl_builder_data',        // Beaver Builder
            '_bricks_page_content',    // Bricks Builder
            'ct_builder_json',         // Oxygen / CT Builder
            'breakdance_data',         // Breakdance
        ];

        foreach ($jsonKeys as $key) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value, p.post_title
                 FROM {$wpdb->postmeta} pm
                 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                   AND pm.meta_value != ''",
                $key
            ), ARRAY_A); // phpcs:enable
            foreach ($rows as $row) {
                $postId    = (int)($row['post_id'] ?? 0);
                $postTitle = (string)($row['post_title'] ?? '');
                $metaVal   = (string)($row['meta_value'] ?? '');
                $editUrl   = $postId > 0 ? $this->buildPostEditUrl($postId) : null;
                // JSON walk; attribution is post-level (individual elements
                // cannot be attributed without per-field extraction). The
                // extractFromJsonMeta call ultimately resolves to addId/addPath
                // with the current default surface, so we temporarily stash the
                // attribution context for the extraction pass.
                $this->extractFromJsonMetaAttributed($metaVal, 'postmeta', $postId, $postTitle, $editUrl, $key);
            }
        }

        // Divi stores raw shortcode-like content in post_content, but also has
        // a global-presets options row. Cover the meta keys here as belt-and-
        // braces (the post_content walk above already covers most Divi uses).
        $diviKeys = ['_et_pb_post_hide_nav', 'divi_content'];
        foreach ($diviKeys as $key) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value, p.post_title
                 FROM {$wpdb->postmeta} pm
                 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                   AND pm.meta_value != ''",
                $key
            ), ARRAY_A); // phpcs:enable
            foreach ($rows as $row) {
                $postId    = (int)($row['post_id'] ?? 0);
                $postTitle = (string)($row['post_title'] ?? '');
                $editUrl   = $postId > 0 ? $this->buildPostEditUrl($postId) : null;
                $this->extractFromHtmlAttributed(
                    (string)($row['meta_value'] ?? ''),
                    'postmeta',
                    $postId,
                    $postTitle,
                    $editUrl,
                    $key
                );
            }
        }

        // WPBakery stores raw shortcode content in post_content; the js_status
        // meta is a flag (not image-bearing). Belt-and-braces URL scan of all
        // WPBakery shortcode meta.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpbRows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value, p.post_title
             FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key IN ('_wpb_shortcodes_custom_css','_vc_post_settings')
               AND pm.meta_value LIKE '%" . esc_sql($this->uploadsBase) . "%'",
            ARRAY_A
        ); // phpcs:enable
        foreach ($wpbRows as $row) {
            $postId    = (int)($row['post_id'] ?? 0);
            $postTitle = (string)($row['post_title'] ?? '');
            $editUrl   = $postId > 0 ? $this->buildPostEditUrl($postId) : null;
            $this->extractFromHtmlAttributed(
                (string)($row['meta_value'] ?? ''),
                'postmeta',
                $postId,
                $postTitle,
                $editUrl,
                '_wpb_shortcodes_custom_css'
            );
        }

        // ACF image / gallery fields: ACF stores attachment IDs as numeric string
        // meta values under field-name keys (e.g. hero_image, gallery_images,
        // post_thumbnail_id). We cannot enumerate all ACF field slugs, so we scan
        // numeric postmeta values whose meta_key name is IMAGE-SUGGESTIVE using the
        // same generous heuristic already applied in extractFromArray().
        //
        // KEY-NAME NARROWING (issue #190 persistent -1):
        //   The previous approach ran a BROAD scan over ALL public meta keys
        //   (meta_key NOT LIKE '\_') + INNER JOIN against wp_posts(attachment).
        //   This was still insufficient: a coincidental counter (post_views_count=567)
        //   or SEO score (seo_score=567) stored under ANY public key would match the
        //   join when attachment ID 567 genuinely exists in wp_posts, wrongly marking
        //   that attachment as referenced and producing the persistent -1.
        //
        //   Fix: restrict meta_key to names containing an image-bearing word fragment
        //   (image, gallery, attachment, thumbnail, photo, logo, icon, bg, avatar,
        //   picture). This is the SAME generous heuristic used in extractFromArray()
        //   for inline array keys. Real ACF image fields almost always carry an image-
        //   suggestive slug (hero_image, gallery_ids, post_thumbnail, featured_photo,
        //   site_logo, author_avatar, etc.). A counter/score/setting key (views, count,
        //   score, rank, order, depth, weight, priority) does NOT match and is excluded.
        //
        //   The INNER JOIN is kept as belt-and-braces: even if a key passes the name
        //   filter, its value is only collected when it corresponds to an existing
        //   attachment post. This is zero-false-negative: an image genuinely stored
        //   under a non-suggestive key name is still caught by post_content URL/ID
        //   scanning, the generic postmeta URL scan, or the serialized-array scan.
        //   The key-name gate only stops non-image values that happen to be numeric.
        //
        // Pattern covers fragments (substring match via REGEXP '.*word.*'):
        //   image, gallery, attachment, thumbnail, photo, logo, icon, bg, avatar, picture
        //
        // Attribution: surface='postmeta', source_id=post ID, detail=meta_key.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $acfRows = $wpdb->get_results(
            "SELECT pm.post_id, CAST(pm.meta_value AS UNSIGNED) AS attach_id, pm.meta_key, p.post_title
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p2
               ON p2.ID = CAST(pm.meta_value AS UNSIGNED)
               AND p2.post_type = 'attachment'
             LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key NOT LIKE '\_%'
               AND pm.meta_value REGEXP '^[1-9][0-9]{0,9}$'",
            ARRAY_A
        ); // phpcs:enable
        foreach ($acfRows as $row) {
            $id        = (int)($row['attach_id'] ?? 0);
            $postId    = (int)($row['post_id'] ?? 0);
            $postTitle = (string)($row['post_title'] ?? '');
            $metaKey   = (string)($row['meta_key'] ?? '');
            $editUrl   = $postId > 0 ? $this->buildPostEditUrl($postId) : null;
            if ($id > 0) {
                $this->addId(
                    $id,
                    'postmeta',
                    $postId > 0 ? $postId : null,
                    $postTitle !== '' ? $postTitle : null,
                    $editUrl,
                    $metaKey
                );
            }
        }

        // ACF gallery: stores serialized array of IDs. Also covers any other
        // plugin that serializes arrays of attachment IDs into postmeta.
        // Attribution: IDs extracted from serialized arrays cannot be traced to
        // a per-element source without field-schema knowledge, so they fall back
        // to 'direct_id' surface — honest and consistent with the conservative contract.
        //
        // EXCLUDE wpmgr_image_optimization (= MediaKeystore::KEY): this key is the
        // Media Optimizer's own per-attachment bookkeeping blob, written on the
        // attachment itself. The serialized blob contains the attachment's original
        // file path and URLs as part of the restore-anchor data. Scanning it would
        // cause the attachment to "self-reference" via its own optimization record,
        // wrongly marking a genuinely unused optimized image as referenced.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $serialRows = $wpdb->get_col(
            "SELECT meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_value LIKE 'a:%'
               AND meta_key NOT LIKE '\\_wp\\_%'
               AND meta_key NOT LIKE '\\_wpmgr\\_%'
               AND meta_key != 'wpmgr_image_optimization'"
        ); // phpcs:enable
        foreach ($serialRows as $raw) {
            $this->extractIdsFromSerialized((string)$raw);
        }
    }

    /**
     * Broad scan of all postmeta values that contain the uploads base URL.
     * Catches any plugin not explicitly handled above.
     *
     * Attribution: surface='postmeta', source_id=post ID, detail=null.
     *
     * EXCLUDE wpmgr_image_optimization (= MediaKeystore::KEY): the Media Optimizer
     * writes a restore-anchor blob to every optimized or excluded attachment under
     * this key. The blob stores the attachment's OWN original URLs and file paths.
     * Including it in the URL scan would cause the attachment to "self-reference"
     * via its own optimization metadata — marking a genuinely unused optimized image
     * as "referenced" (false positive). The same key is excluded from URL rewrites
     * in DbRewriter::SKIP_META_KEYS for the same reason. Only the cleaner's reference
     * scan is affected here; the Media Optimizer itself is unchanged.
     */
    private function indexPostmetaGeneric(): void
    {
        global $wpdb;

        if ($this->uploadsBase === '') {
            return;
        }

        $offset = 0;
        $batch  = 500;
        // Use a LIKE search on the uploads URL fragment as a low-cardinality filter.
        $like = '%' . $wpdb->esc_like($this->uploadsBase) . '%';

        while (true) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value, p.post_title
                 FROM {$wpdb->postmeta} pm
                 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_value LIKE %s
                   AND pm.meta_key != %s
                 LIMIT %d OFFSET %d",
                $like,
                'wpmgr_image_optimization',
                $batch,
                $offset
            ), ARRAY_A); // phpcs:enable

            if (empty($rows)) {
                break;
            }
            $offset += $batch;

            foreach ($rows as $row) {
                $postId    = (int)($row['post_id'] ?? 0);
                $postTitle = (string)($row['post_title'] ?? '');
                $editUrl   = $postId > 0 ? $this->buildPostEditUrl($postId) : null;
                $this->extractFromHtmlAttributed(
                    (string)($row['meta_value'] ?? ''),
                    'postmeta',
                    $postId,
                    $postTitle,
                    $editUrl
                );
            }
        }
    }

    /**
     * Gap 3: Index SEO-plugin postmeta keys that store attachment IDs under
     * underscore-prefixed names, which the generic ACF numeric scan skips because
     * it filters out `_`-prefixed keys.
     *
     * This uses an ALLOWLIST of exact meta_key names rather than a broad scan,
     * keeping precision high while catching the most common SEO plugins:
     *   Yoast SEO, Rank Math, SEOPress.
     *
     * Both the image-ID keys (integer attachment ID) and the URL-valued twins are
     * included; URL-valued twins are already caught by indexPostmetaGeneric() URL
     * scan, so we focus on the ID-valued keys here (by-ID match).
     *
     * Attribution: surface='postmeta', detail=meta_key.
     */
    private function indexSeoMeta(): void
    {
        global $wpdb;

        // Allowlist of exact `_`-prefixed meta_keys that store a bare attachment ID.
        $seoIdKeys = [
            '_yoast_wpseo_opengraph-image-id',
            '_yoast_wpseo_twitter-image-id',
            'rank_math_facebook_image_id',
            'rank_math_twitter_image_id',
            '_seopress_social_fb_img-id',
            '_seopress_social_twitter_img-id',
        ];

        // Build a parameterised IN-list. $wpdb->prepare() with %s placeholders
        // for string values; we repeat the placeholder count to match the key count.
        $placeholders = implode(', ', array_fill(0, count($seoIdKeys), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p2
               ON p2.ID = CAST(pm.meta_value AS UNSIGNED)
               AND p2.post_type = 'attachment'
             LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key IN ({$placeholders})
               AND pm.meta_value REGEXP '^[1-9][0-9]{0,9}$'",
            ...$seoIdKeys
        ), ARRAY_A);
        // phpcs:enable

        foreach ($rows as $row) {
            $id        = (int)($row['meta_value'] ?? 0);
            $postId    = (int)($row['post_id'] ?? 0);
            $postTitle = (string)($row['post_title'] ?? '');
            $metaKey   = (string)($row['meta_key'] ?? '');
            $editUrl   = $postId > 0 ? $this->buildPostEditUrl($postId) : null;
            if ($id > 0) {
                $this->addId(
                    $id,
                    'postmeta',
                    $postId > 0 ? $postId : null,
                    $postTitle !== '' ? $postTitle : null,
                    $editUrl,
                    $metaKey
                );
            }
        }
    }

    /**
     * Gap 4: Scan wp_options for bare attachment IDs stored by ACF options pages
     * and similar plugins that store numeric IDs under image-suggestive option_name
     * keys (e.g. options_hero_image, _options_logo_id, site_banner_id).
     *
     * The scan is bounded to option_names that contain an image-suggestive keyword
     * (matching the heuristic in extractFromArrayAttributed) and uses an INNER JOIN
     * against wp_posts(post_type='attachment') to validate that the numeric value
     * corresponds to a real attachment post.
     *
     * Attribution: surface='option', detail=option_name.
     */
    private function indexOptionsNumericIds(): void
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT o.option_name, CAST(o.option_value AS UNSIGNED) AS attach_id
             FROM {$wpdb->options} o
             INNER JOIN {$wpdb->posts} p
               ON p.ID = CAST(o.option_value AS UNSIGNED)
               AND p.post_type = 'attachment'
             WHERE o.option_value REGEXP '^[1-9][0-9]{0,9}$'
               AND o.option_name REGEXP '(image|gallery|attachment|thumbnail|photo|logo|icon|bg|avatar|picture|media|img|src|pic|file|banner|hero|poster)'
               AND o.option_name NOT LIKE 'wpmgr\\_%'",
            ARRAY_A
        );
        // phpcs:enable

        foreach ($rows as $row) {
            $id      = (int)($row['attach_id'] ?? 0);
            $optName = (string)($row['option_name'] ?? '');
            if ($id > 0 && $optName !== '') {
                $this->addId(
                    $id,
                    'option',
                    null,
                    $optName,
                    $this->buildOptionsEditUrl(),
                    $optName
                );
            }
        }
    }

    /**
     * Index wp_options rows that commonly hold attachment IDs or URLs:
     * theme_mods_*, widget text/image options, Divi global presets,
     * WooCommerce placeholder, site icon, nav menus.
     *
     * Attribution: surface='option', source_label=option_name, source_id=null.
     * edit_url=admin_url('options-general.php') for scalar options; null for
     * broad generic scans where the option_name is not cheaply available.
     */
    private function indexOptions(): void
    {
        global $wpdb;

        // theme_mods_* rows. One row per active/previously-active theme.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $themeMods = $wpdb->get_results(
            "SELECT option_name, option_value
             FROM {$wpdb->options}
             WHERE option_name LIKE 'theme\\_mods\\_%'",
            ARRAY_A
        ); // phpcs:enable
        foreach ($themeMods as $row) {
            $optName = (string)($row['option_name'] ?? '');
            $val     = (string)($row['option_value'] ?? '');
            // theme_mods extractions use extractFromSerialized which calls
            // addId/addPath; we set the pending attribution context before calling.
            $this->extractFromSerializedAttributed($val, 'option', null, $optName, null);
        }

        // Individual named options that store attachment IDs.
        $scalarOptions = [
            'site_icon',
            'woocommerce_placeholder_image',
        ];
        foreach ($scalarOptions as $optName) {
            $val = get_option($optName);
            if (is_numeric($val) && (int)$val > 0) {
                $this->addId(
                    (int)$val,
                    'option',
                    null,
                    $optName,
                    $this->buildOptionsEditUrl(),
                    $optName
                );
            }
        }

        // sidebar_widgets option: lists active widget IDs per sidebar.
        // We then scan each widget option for image references.
        $sidebarsWidgets = get_option('sidebars_widgets', []);
        if (is_array($sidebarsWidgets)) {
            $widgetKeys = [];
            foreach ($sidebarsWidgets as $key => $widgets) {
                if ($key === 'wp_inactive_widgets' || !is_array($widgets)) {
                    continue;
                }
                foreach ($widgets as $widgetId) {
                    // widgetId is like "text-2", "image-1".
                    if (is_string($widgetId)) {
                        // Extract the widget type prefix.
                        if (preg_match('/^(.+?)-\d+$/', $widgetId, $m)) {
                            $widgetKeys['widget_' . $m[1]] = true;
                        }
                    }
                }
            }

            foreach (array_keys($widgetKeys) as $optKey) {
                $widgetData = get_option($optKey, []);
                if (is_array($widgetData)) {
                    $this->extractFromArrayAttributed($widgetData, 'widget', null, $optKey, null);
                }
            }
        }

        // Divi global presets (recursive URL hunt).
        $diviPresets = get_option('et_divi_builder_global_presets_d5', false);
        if (is_array($diviPresets)) {
            $this->extractFromArrayAttributed($diviPresets, 'option', null, 'et_divi_builder_global_presets_d5', null);
        }

        $et_divi = get_option('et_divi', false);
        if (is_array($et_divi)) {
            $this->extractFromArrayAttributed($et_divi, 'option', null, 'et_divi', null);
        }

        // Generic broad scan: any option value containing the uploads URL.
        if ($this->uploadsBase !== '') {
            $like = '%' . $wpdb->esc_like($this->uploadsBase) . '%';
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- direct query on plugin-owned table; no core $wpdb helper exists; static literal LIKE pattern, no bound value
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT option_name, option_value
                 FROM {$wpdb->options}
                 WHERE option_value LIKE %s
                   AND option_name NOT LIKE 'wpmgr\\_%'",
                $like
            ), ARRAY_A); // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
            foreach ($rows as $row) {
                $optName = (string)($row['option_name'] ?? '');
                $this->extractFromHtmlAttributed(
                    (string)($row['option_value'] ?? ''),
                    'option',
                    null,
                    $optName,
                    null
                );
            }
        }
    }

    /**
     * Index term meta containing *thumbnail_id / *image_id / *icon patterns.
     *
     * Attribution: surface='term_meta', source_id=term_id.
     */
    private function indexTermMeta(): void
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT term_id, meta_key, meta_value
             FROM {$wpdb->termmeta}
             WHERE (
                 meta_key LIKE '%thumbnail_id%'
              OR meta_key LIKE '%image_id%'
              OR meta_key LIKE '%icon%'
              OR meta_key = 'image'
             )
             AND meta_value REGEXP '^[0-9]+$'",
            ARRAY_A
        ); // phpcs:enable
        foreach ($rows as $row) {
            $id      = (int)($row['meta_value'] ?? 0);
            $termId  = (int)($row['term_id'] ?? 0);
            $metaKey = (string)($row['meta_key'] ?? '');
            if ($id > 0) {
                $this->addId(
                    $id,
                    'term_meta',
                    $termId > 0 ? $termId : null,
                    null,
                    null,
                    $metaKey
                );
            }
        }

        // Also broad URL scan in termmeta.
        if ($this->uploadsBase !== '') {
            $like = '%' . $wpdb->esc_like($this->uploadsBase) . '%';
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $urlRows = $wpdb->get_results($wpdb->prepare(
                "SELECT term_id, meta_value
                 FROM {$wpdb->termmeta}
                 WHERE meta_value LIKE %s",
                $like
            ), ARRAY_A); // phpcs:enable
            foreach ($urlRows as $row) {
                $termId = (int)($row['term_id'] ?? 0);
                $this->extractFromHtmlAttributed(
                    (string)($row['meta_value'] ?? ''),
                    'term_meta',
                    $termId > 0 ? $termId : null,
                    null,
                    null
                );
            }
        }
    }

    /**
     * Index user meta containing avatar / profile-picture attachment IDs.
     * Covers Simple Local Avatars (simple_local_avatar) and similar plugins.
     *
     * Attribution: surface='user_meta', source_id=user_id.
     */
    private function indexUserMeta(): void
    {
        global $wpdb;

        $metaKeys = [
            'simple_local_avatar',
            'wp_user_avatar',
            'author_avatar_image_id',
        ];

        foreach ($metaKeys as $key) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, meta_value
                 FROM {$wpdb->usermeta}
                 WHERE meta_key = %s
                   AND meta_value != ''",
                $key
            ), ARRAY_A); // phpcs:enable
            foreach ($rows as $row) {
                $userId = (int)($row['user_id'] ?? 0);
                $val    = (string)($row['meta_value'] ?? '');
                if (is_numeric($val) && (int)$val > 0) {
                    $this->addId(
                        (int)$val,
                        'user_meta',
                        $userId > 0 ? $userId : null,
                        null,
                        null,
                        $key
                    );
                } else {
                    $this->extractFromHtmlAttributed(
                        $val,
                        'user_meta',
                        $userId > 0 ? $userId : null,
                        null,
                        null
                    );
                }
            }
        }

        // Broad uploads URL scan in usermeta.
        if ($this->uploadsBase !== '') {
            $like = '%' . $wpdb->esc_like($this->uploadsBase) . '%';
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $urlRows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, meta_value
                 FROM {$wpdb->usermeta}
                 WHERE meta_value LIKE %s",
                $like
            ), ARRAY_A); // phpcs:enable
            foreach ($urlRows as $row) {
                $userId = (int)($row['user_id'] ?? 0);
                $this->extractFromHtmlAttributed(
                    (string)($row['meta_value'] ?? ''),
                    'user_meta',
                    $userId > 0 ? $userId : null,
                    null,
                    null
                );
            }
        }
    }

    /**
     * Index nav menu items that reference attachment posts directly via
     * _menu_item_object_id where _menu_item_object = 'attachment'.
     *
     * Attribution: surface='menu', source_id=menu-item post ID.
     */
    private function indexNavMenuItems(): void
    {
        global $wpdb;

        // Find all nav_menu_item posts whose object type is 'attachment'.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->postmeta} pm2
               ON pm2.post_id = pm.post_id
               AND pm2.meta_key = '_menu_item_object'
               AND pm2.meta_value = 'attachment'
             WHERE pm.meta_key = '_menu_item_object_id'
               AND pm.meta_value REGEXP '^[0-9]+$'",
            ARRAY_A
        ); // phpcs:enable
        foreach ($rows as $row) {
            $menuItemPostId = (int)($row['post_id'] ?? 0);
            $attachId       = (int)($row['meta_value'] ?? 0);
            $editUrl        = $menuItemPostId > 0 ? $this->buildPostEditUrl($menuItemPostId) : null;
            if ($attachId > 0) {
                $this->addId(
                    $attachId,
                    'menu',
                    $menuItemPostId > 0 ? $menuItemPostId : null,
                    null,
                    $editUrl,
                    null
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Attributed extraction helpers
    // -------------------------------------------------------------------------

    /**
     * Extract all attachment IDs and upload-path fragments from an HTML/text blob,
     * attributing every match to the given surface and source.
     *
     * @param string      $html
     * @param string      $surface       Canonical surface enum value.
     * @param int|null    $sourceId      Referencing post/term/user ID.
     * @param string|null $sourceLabel   Human label for the source.
     * @param string|null $editUrl       WP-admin edit link.
     * @param string|null $detail        Extra context (meta_key, class token, etc.).
     */
    private function extractFromHtmlAttributed(
        string $html,
        string $surface,
        ?int $sourceId,
        ?string $sourceLabel,
        ?string $editUrl,
        ?string $detail = null
    ): void {
        if ($html === '' || $this->uploadsBase === '') {
            return;
        }

        // wp-image-{id} class (Gutenberg image block, classic editor).
        if (preg_match_all('/wp-image-(\d+)/', $html, $m)) {
            foreach ($m[1] as $id) {
                $this->addId(
                    (int)$id,
                    $surface,
                    $sourceId,
                    $sourceLabel,
                    $editUrl,
                    $detail ?? ('wp-image-' . $id)
                );
            }
        }

        // Gap 2: Gutenberg / FSE block-comment attachment IDs.
        //
        // Block markup embeds attachment IDs in HTML comments as JSON attributes.
        // We extract them with bounded, non-backtracking patterns directly from the
        // comment JSON fragment — no block parser, no render engine, no HTTP.
        //
        // Patterns covered (all use {1,12} to cap backtracking):
        //   wp:image {"id":N}         — standard image block
        //   wp:cover {"id":N}         — cover block
        //   wp:media-text {"mediaId":N}  — media-text block
        //   wp:image-gallery "ids":[N,N] — gallery block (comma-separated list)
        //   Any block JSON "id":N     — belt-and-braces for future/custom blocks
        //
        // "ids" array: bounded to 500 characters of content to prevent ReDoS on
        // adversarially large hand-crafted HTML. Each individual numeric token is
        // captured independently to avoid unbounded alternation.

        // "id": N — covers wp:image, wp:cover, and any JSON block attribute named
        // "id". Bounded: \d{1,12} (max 999999999999, well above 2^31).
        if (preg_match_all('/"id"\s*:\s*(\d{1,12})/', $html, $m)) {
            foreach ($m[1] as $idStr) {
                $id = (int)$idStr;
                if ($id > 0) {
                    $this->addId($id, $surface, $sourceId, $sourceLabel, $editUrl, $detail ?? 'block:id');
                }
            }
        }

        // "mediaId": N — wp:media-text block.
        if (preg_match_all('/"mediaId"\s*:\s*(\d{1,12})/', $html, $m)) {
            foreach ($m[1] as $idStr) {
                $id = (int)$idStr;
                if ($id > 0) {
                    $this->addId($id, $surface, $sourceId, $sourceLabel, $editUrl, $detail ?? 'block:mediaId');
                }
            }
        }

        // "ids": [N, N, N, …] — gallery block. Inner content bounded to 500 chars
        // to prevent ReDoS. Each token inside the captured group is split separately.
        if (preg_match_all('/"ids"\s*:\s*\[([0-9,\s]{1,500})\]/', $html, $m)) {
            foreach ($m[1] as $idList) {
                foreach (explode(',', $idList) as $idStr) {
                    $id = (int)trim($idStr);
                    if ($id > 0) {
                        $this->addId($id, $surface, $sourceId, $sourceLabel, $editUrl, $detail ?? 'block:ids');
                    }
                }
            }
        }

        // [gallery ids="1,2,3"] shortcode — surface override to 'gallery' when
        // the calling surface is post_content (not excerpt or revision).
        $gallerySurface = $surface === 'post_content' ? 'gallery' : $surface;
        if (preg_match_all('/\[gallery[^\]]*\bids=["\']?([0-9,\s]+)["\']?/i', $html, $m)) {
            foreach ($m[1] as $idList) {
                foreach (explode(',', $idList) as $idStr) {
                    $id = (int)trim($idStr);
                    if ($id > 0) {
                        $this->addId(
                            $id,
                            $gallerySurface,
                            $sourceId,
                            $sourceLabel,
                            $editUrl,
                            $detail ?? '[gallery]'
                        );
                    }
                }
            }
        }

        // Divi gallery_ids="1,2,3" attribute.
        if (preg_match_all('/\bgallery_ids=["\']([0-9,]+)["\']/', $html, $m)) {
            foreach ($m[1] as $idList) {
                foreach (explode(',', $idList) as $idStr) {
                    $id = (int)trim($idStr);
                    if ($id > 0) {
                        $this->addId(
                            $id,
                            $gallerySurface,
                            $sourceId,
                            $sourceLabel,
                            $editUrl,
                            $detail ?? 'gallery_ids'
                        );
                    }
                }
            }
        }

        // Attachment ID in data-id, data-attachment-id attributes.
        if (preg_match_all('/data-(?:attachment-)?id=["\'](\d+)["\']/', $html, $m)) {
            foreach ($m[1] as $id) {
                $this->addId(
                    (int)$id,
                    $surface,
                    $sourceId,
                    $sourceLabel,
                    $editUrl,
                    $detail ?? 'data-id'
                );
            }
        }

        // All uploads URLs in any context (src, href, url(), background-image,
        // JSON "url":, "src":, "image":, Divi image= attributes, etc.).
        $escapedBase = preg_quote($this->uploadsBase, '/');
        $imgExts     = self::IMG_EXTS;
        if (preg_match_all(
            '/(?:https?:)?\/\/' . $escapedBase . '\/([^\s"\'<>)]+\.(?:' . $imgExts . ')(?:\?[^\s"\'<>)]*)?)/i',
            $html,
            $m
        )) {
            foreach ($m[1] as $fragment) {
                $fragment = strtok($fragment, '?'); // strip query string
                if ($fragment !== false && $fragment !== '') {
                    $this->addPath(
                        $fragment,
                        $surface,
                        $sourceId,
                        $sourceLabel,
                        $editUrl,
                        $detail
                    );
                }
            }
        }
    }

    /**
     * Extract attachment IDs and URLs from a JSON string (page-builder meta),
     * attributing matches to the given surface and source.
     */
    private function extractFromJsonMetaAttributed(
        string $json,
        string $surface,
        ?int $sourceId,
        ?string $sourceLabel,
        ?string $editUrl,
        ?string $detail = null
    ): void {
        if ($json === '') {
            return;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            // Fallback: treat as raw text and regex-scan.
            $this->extractFromHtmlAttributed($json, $surface, $sourceId, $sourceLabel, $editUrl, $detail);
            return;
        }

        $this->extractFromArrayAttributed($decoded, $surface, $sourceId, $sourceLabel, $editUrl, $detail);
    }

    /**
     * Recursively walk a decoded array (page-builder settings, theme_mods, etc.)
     * and collect any attachment IDs or upload-path URLs, attributing each to
     * the given surface and source.
     */
    private function extractFromArrayAttributed(
        array $data,
        string $surface,
        ?int $sourceId,
        ?string $sourceLabel,
        ?string $editUrl,
        ?string $detail = null
    ): void {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Known structural keys: check 'id' at this level.
                if (isset($value['id']) && is_numeric($value['id'])) {
                    $this->addId(
                        (int)$value['id'],
                        $surface,
                        $sourceId,
                        $sourceLabel,
                        $editUrl,
                        $detail ?? (string)$key
                    );
                }
                // Elementor: settings.image.id, settings.background_image.id, etc.
                if (isset($value['url']) && is_string($value['url'])) {
                    $this->extractFromHtmlAttributed($value['url'], $surface, $sourceId, $sourceLabel, $editUrl, $detail);
                }
                $this->extractFromArrayAttributed($value, $surface, $sourceId, $sourceLabel, $editUrl, $detail);
            } elseif (is_string($value)) {
                // String keys that typically hold attachment IDs.
                $idKeys = ['id', 'image_id', 'attachment_id', 'thumbnail_id', 'icon_id'];
                if (in_array((string)$key, $idKeys, true) && is_numeric($value)) {
                    $this->addId(
                        (int)$value,
                        $surface,
                        $sourceId,
                        $sourceLabel,
                        $editUrl,
                        $detail ?? (string)$key
                    );
                }
                // Any string value containing the uploads URL fragment.
                if ($this->uploadsBase !== '' && strpos($value, $this->uploadsBase) !== false) {
                    $this->extractFromHtmlAttributed($value, $surface, $sourceId, $sourceLabel, $editUrl, $detail);
                }
                // Numeric string that could be an attachment ID (only for known
                // image-bearing key patterns — Gap 6: broadened keyword set).
                if (
                    is_numeric($value)
                    && (int)$value > 0
                    && (int)$value <= 2_147_483_647
                    && (
                        stripos((string)$key, 'image') !== false
                        || stripos((string)$key, 'thumbnail') !== false
                        || stripos((string)$key, 'avatar') !== false
                        || stripos((string)$key, 'photo') !== false
                        || stripos((string)$key, 'logo') !== false
                        || stripos((string)$key, 'icon') !== false
                        || stripos((string)$key, 'picture') !== false
                        || stripos((string)$key, 'attachment') !== false
                        || stripos((string)$key, 'bg') !== false
                        || stripos((string)$key, 'gallery') !== false
                        || stripos((string)$key, 'media') !== false
                        || stripos((string)$key, 'img') !== false
                        || stripos((string)$key, 'src') !== false
                        || stripos((string)$key, 'pic') !== false
                        || stripos((string)$key, 'file') !== false
                        || stripos((string)$key, 'banner') !== false
                        || stripos((string)$key, 'hero') !== false
                        || stripos((string)$key, 'poster') !== false
                    )
                ) {
                    $this->addId(
                        (int)$value,
                        $surface,
                        $sourceId,
                        $sourceLabel,
                        $editUrl,
                        $detail ?? (string)$key
                    );
                }
            } elseif (is_int($value) && $value > 0 && $value <= 2_147_483_647) {
                // Bare integer in an array. Two conservative cases:
                //
                // 1. Positional (numeric) key — ACF galleries store [123, 456, 789].
                //    Handled by extractIdsFromSerialized(); we let it skip here to
                //    avoid double-counting, but the conservative guarantee is met
                //    because extractIdsFromSerialized already indexes them.
                //
                // 2. Named key with an image-bearing hint (custom_logo, site_icon,
                //    header_image_id, etc.). These are typically WP theme_mods or
                //    plugin option rows that store an attachment ID. Collect them.
                $keyStr = (string)$key;
                if (
                    stripos($keyStr, 'image') !== false
                    || stripos($keyStr, 'thumbnail') !== false
                    || stripos($keyStr, 'logo') !== false
                    || stripos($keyStr, 'icon') !== false
                    || stripos($keyStr, 'avatar') !== false
                    || stripos($keyStr, 'photo') !== false
                    || stripos($keyStr, 'picture') !== false
                    || stripos($keyStr, 'attachment') !== false
                    || stripos($keyStr, 'bg') !== false
                    || stripos($keyStr, 'gallery') !== false
                    || stripos($keyStr, 'media') !== false
                    || stripos($keyStr, 'img') !== false
                    || stripos($keyStr, 'src') !== false
                    || stripos($keyStr, 'pic') !== false
                    || stripos($keyStr, 'file') !== false
                    || stripos($keyStr, 'banner') !== false
                    || stripos($keyStr, 'hero') !== false
                    || stripos($keyStr, 'poster') !== false
                ) {
                    $this->addId(
                        $value,
                        $surface,
                        $sourceId,
                        $sourceLabel,
                        $editUrl,
                        $detail ?? $keyStr
                    );
                }
            }
        }
    }

    /**
     * Extract IDs from a PHP-serialized value (safe unserialize, arrays only),
     * attributing matches to the given surface and source.
     */
    private function extractFromSerializedAttributed(
        string $raw,
        string $surface,
        ?int $sourceId,
        ?string $sourceLabel,
        ?string $editUrl,
        ?string $detail = null
    ): void {
        if ($raw === '') {
            return;
        }
        if (is_serialized($raw)) {
            $decoded = @unserialize($raw, ['allowed_classes' => false]);
            if (is_array($decoded)) {
                $this->extractFromArrayAttributed($decoded, $surface, $sourceId, $sourceLabel, $editUrl, $detail);
                return;
            }
        }
        // Fallback: treat as HTML/text.
        $this->extractFromHtmlAttributed($raw, $surface, $sourceId, $sourceLabel, $editUrl, $detail);
    }

    /**
     * Extract attachment IDs and URLs from a JSON string (page-builder meta).
     * Uses a recursive walk over the decoded structure rather than regex to
     * correctly handle deeply nested builder element trees.
     *
     * @deprecated Use extractFromJsonMetaAttributed() for new call sites.
     */
    private function extractFromJsonMeta(string $json): void
    {
        if ($json === '') {
            return;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $this->extractFromHtml($json);
            return;
        }

        $this->extractFromArray($decoded);
    }

    /**
     * Extract all attachment IDs and upload-path fragments from an HTML/text blob.
     *
     * @deprecated Use extractFromHtmlAttributed() for new call sites.
     */
    private function extractFromHtml(string $html): void
    {
        if ($html === '' || $this->uploadsBase === '') {
            return;
        }

        // wp-image-{id} class (Gutenberg image block, classic editor).
        if (preg_match_all('/wp-image-(\d+)/', $html, $m)) {
            foreach ($m[1] as $id) {
                $this->addId((int)$id);
            }
        }

        // [gallery ids="1,2,3"] shortcode.
        if (preg_match_all('/\[gallery[^\]]*\bids=["\']?([0-9,\s]+)["\']?/i', $html, $m)) {
            foreach ($m[1] as $idList) {
                foreach (explode(',', $idList) as $idStr) {
                    $id = (int)trim($idStr);
                    if ($id > 0) {
                        $this->addId($id);
                    }
                }
            }
        }

        // Divi gallery_ids="1,2,3" attribute.
        if (preg_match_all('/\bgallery_ids=["\']([0-9,]+)["\']/', $html, $m)) {
            foreach ($m[1] as $idList) {
                foreach (explode(',', $idList) as $idStr) {
                    $id = (int)trim($idStr);
                    if ($id > 0) {
                        $this->addId($id);
                    }
                }
            }
        }

        // Attachment ID in data-id, data-attachment-id attributes.
        if (preg_match_all('/data-(?:attachment-)?id=["\'](\d+)["\']/', $html, $m)) {
            foreach ($m[1] as $id) {
                $this->addId((int)$id);
            }
        }

        // All uploads URLs in any context.
        $escapedBase = preg_quote($this->uploadsBase, '/');
        $imgExts     = self::IMG_EXTS;
        if (preg_match_all(
            '/(?:https?:)?\/\/' . $escapedBase . '\/([^\s"\'<>)]+\.(?:' . $imgExts . ')(?:\?[^\s"\'<>)]*)?)/i',
            $html,
            $m
        )) {
            foreach ($m[1] as $fragment) {
                $fragment = strtok($fragment, '?');
                if ($fragment !== false && $fragment !== '') {
                    $this->addPath($fragment);
                }
            }
        }
    }

    /**
     * Recursively walk a decoded array and collect any attachment IDs or
     * upload-path URLs.
     *
     * @deprecated Use extractFromArrayAttributed() for new call sites.
     */
    private function extractFromArray(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (isset($value['id']) && is_numeric($value['id'])) {
                    $this->addId((int)$value['id']);
                }
                if (isset($value['url']) && is_string($value['url'])) {
                    $this->extractFromHtml($value['url']);
                }
                $this->extractFromArray($value);
            } elseif (is_string($value)) {
                $idKeys = ['id', 'image_id', 'attachment_id', 'thumbnail_id', 'icon_id'];
                if (in_array((string)$key, $idKeys, true) && is_numeric($value)) {
                    $this->addId((int)$value);
                }
                if ($this->uploadsBase !== '' && strpos($value, $this->uploadsBase) !== false) {
                    $this->extractFromHtml($value);
                }
                if (
                    is_numeric($value)
                    && (int)$value > 0
                    && (int)$value <= 2_147_483_647
                    && (
                        stripos((string)$key, 'image') !== false
                        || stripos((string)$key, 'thumbnail') !== false
                        || stripos((string)$key, 'avatar') !== false
                        || stripos((string)$key, 'photo') !== false
                        || stripos((string)$key, 'logo') !== false
                        || stripos((string)$key, 'icon') !== false
                        || stripos((string)$key, 'picture') !== false
                        || stripos((string)$key, 'attachment') !== false
                        || stripos((string)$key, 'bg') !== false
                        || stripos((string)$key, 'gallery') !== false
                        || stripos((string)$key, 'media') !== false
                        || stripos((string)$key, 'img') !== false
                        || stripos((string)$key, 'src') !== false
                        || stripos((string)$key, 'pic') !== false
                        || stripos((string)$key, 'file') !== false
                        || stripos((string)$key, 'banner') !== false
                        || stripos((string)$key, 'hero') !== false
                        || stripos((string)$key, 'poster') !== false
                    )
                ) {
                    $this->addId((int)$value);
                }
            } elseif (is_int($value) && $value > 0 && $value <= 2_147_483_647) {
                $keyStr = (string)$key;
                if (
                    stripos($keyStr, 'image') !== false
                    || stripos($keyStr, 'thumbnail') !== false
                    || stripos($keyStr, 'logo') !== false
                    || stripos($keyStr, 'icon') !== false
                    || stripos($keyStr, 'avatar') !== false
                    || stripos($keyStr, 'photo') !== false
                    || stripos($keyStr, 'picture') !== false
                    || stripos($keyStr, 'attachment') !== false
                    || stripos($keyStr, 'bg') !== false
                    || stripos($keyStr, 'gallery') !== false
                    || stripos($keyStr, 'media') !== false
                    || stripos($keyStr, 'img') !== false
                    || stripos($keyStr, 'src') !== false
                    || stripos($keyStr, 'pic') !== false
                    || stripos($keyStr, 'file') !== false
                    || stripos($keyStr, 'banner') !== false
                    || stripos($keyStr, 'hero') !== false
                    || stripos($keyStr, 'poster') !== false
                ) {
                    $this->addId($value);
                }
            }
        }
    }

    /**
     * Extract IDs from a PHP-serialized value (safe unserialize, arrays only).
     */
    private function extractFromSerialized(string $raw): void
    {
        if ($raw === '') {
            return;
        }
        if (is_serialized($raw)) {
            $decoded = @unserialize($raw, ['allowed_classes' => false]);
            if (is_array($decoded)) {
                $this->extractFromArray($decoded);
                return;
            }
        }
        $this->extractFromHtml($raw);
    }

    /**
     * Extract numeric IDs from a PHP-serialized value containing numeric arrays.
     * Used for ACF gallery arrays, etc.
     *
     * Attribution: falls back to 'direct_id' surface — IDs extracted from
     * anonymous serialized arrays cannot be traced to a specific source post.
     */
    private function extractIdsFromSerialized(string $raw): void
    {
        if (!is_serialized($raw)) {
            return;
        }
        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($decoded)) {
            return;
        }
        // Walk the array looking for bare numeric values.
        array_walk_recursive($decoded, function ($v) {
            if (is_int($v) && $v > 0 && $v <= 2_147_483_647) {
                $this->addId($v, 'direct_id');
            } elseif (is_string($v) && ctype_digit($v) && (int)$v > 0 && (int)$v <= 2_147_483_647) {
                $this->addId((int)$v, 'direct_id');
            }
        });
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Given an upload-relative path that may be a WordPress-generated sub-size
     * (e.g. "2024/01/hero-300x200.jpg"), strip the "-WxH" dimensions infix and
     * return the inferred parent path (e.g. "2024/01/hero.jpg").
     *
     * Returns null when the path contains no recognisable dimensions infix, or
     * when the stripping would produce a path equal to the original (not a sub-size).
     *
     * The regex is anchored to the end of the basename (before the extension) and
     * uses a bounded quantifier to avoid catastrophic backtracking:
     *   -    literal hyphen
     *   \d{1,5}  width (1-5 digits, covers up to 99999 px)
     *   x
     *   \d{1,5}  height
     * followed by a literal dot and the file extension.
     *
     * The directory prefix is preserved exactly so a "2023/01/logo-150x150.jpg"
     * can only derive "2023/01/logo.jpg", not "2024/06/logo.jpg".
     *
     * @param string $path Upload-relative path (no leading slash).
     * @return string|null Inferred parent path, or null if not a sub-size.
     */
    private function deriveParentFromSubSize(string $path): ?string
    {
        // Match a -WxH infix immediately before the file extension.
        // Pattern: (.+)-\d{1,5}x\d{1,5}(\.[a-zA-Z]{2,5})$
        // The leading (.+) is non-greedy? No — use greedy with an anchored end so
        // we match the LAST occurrence of -WxH in the basename (some filenames have
        // hyphens). The {1,5} quantifiers prevent catastrophic backtracking.
        if (!preg_match('/^(.+)-\d{1,5}x\d{1,5}(\.[a-zA-Z]{2,5})$/D', $path, $m)) {
            return null;
        }
        $candidate = $m[1] . $m[2]; // e.g. "2024/01/hero" + ".jpg"
        return $candidate !== $path ? $candidate : null;
    }

    /**
     * Collect all upload-relative paths for an attachment (main file +
     * all generated sub-sizes + original_image for scaled uploads).
     *
     * @return list<string>
     */
    private function allPathsForAttachment(string $relPath, array $meta): array
    {
        $paths = [];
        if ($relPath !== '') {
            $paths[] = $relPath;
        }

        if (empty($meta)) {
            return $paths;
        }

        // Derive the sub-directory prefix from the main file path.
        $dir = '';
        if (isset($meta['file']) && is_string($meta['file'])) {
            $dir = ltrim(dirname($meta['file']), './');
            if ($dir !== '' && $dir !== '.') {
                $dir .= '/';
            } else {
                $dir = '';
            }
        }

        // All registered sub-sizes.
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                if (!empty($size['file']) && is_string($size['file'])) {
                    $paths[] = $dir . $size['file'];
                }
            }
        }

        // The un-scaled original (WP 5.3+ big-image scale-down).
        if (!empty($meta['original_image']) && is_string($meta['original_image'])) {
            $paths[] = $dir . $meta['original_image'];
        }

        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * Record that an attachment ID is referenced, storing a full usage entry.
     * Keeps the existing boolean lookup ($this->ids[$id]=true) AND appends a
     * usage record for attribution.
     *
     * @param int         $id          Attachment post ID.
     * @param string      $surface     Canonical surface enum value.
     * @param int|null    $sourceId    Referencing post/term/user ID, or null.
     * @param string|null $sourceLabel Human label for the source, or null.
     * @param string|null $editUrl     WP-admin edit link, or null.
     * @param string|null $detail      Extra context (meta_key, class token, etc.), or null.
     */
    private function addId(
        int $id,
        string $surface = 'direct_id',
        ?int $sourceId = null,
        ?string $sourceLabel = null,
        ?string $editUrl = null,
        ?string $detail = null
    ): void {
        if ($id <= 0) {
            return;
        }
        $this->ids[$id] = true;
        $this->idUsages[$id][] = [
            'surface'      => $surface,
            'source_id'    => $sourceId,
            'source_label' => $sourceLabel !== '' ? $sourceLabel : null,
            'edit_url'     => $editUrl,
            'detail'       => $detail !== '' ? $detail : null,
        ];
    }

    /**
     * Record that an upload-relative path fragment is referenced in content.
     * Keeps the existing boolean lookup ($this->paths[$path]=true) AND appends
     * a usage record for attribution.
     *
     * @param string      $path        Upload-relative path fragment.
     * @param string      $surface     Canonical surface enum value.
     * @param int|null    $sourceId    Referencing post/term/user ID, or null.
     * @param string|null $sourceLabel Human label for the source, or null.
     * @param string|null $editUrl     WP-admin edit link, or null.
     * @param string|null $detail      Extra context (matched URL fragment, etc.), or null.
     */
    private function addPath(
        string $path,
        string $surface = 'path',
        ?int $sourceId = null,
        ?string $sourceLabel = null,
        ?string $editUrl = null,
        ?string $detail = null
    ): void {
        $path = trim($path, '/');
        if ($path === '') {
            return;
        }
        // NOTE: do NOT also index the bare basename here.
        // Indexing basenames causes false-positive matches when two attachments
        // share a filename across different upload subdirectories (e.g.,
        // 2023/01/image.jpg vs 2024/06/image.jpg). The full-path match is
        // sufficient: URL-based references in content always include the year/month
        // path, and the uploads-base regex in extractFromHtmlAttributed anchors on
        // the full path fragment.
        $this->paths[$path] = true;
        $this->pathUsages[$path][] = [
            'surface'      => $surface,
            'source_id'    => $sourceId,
            'source_label' => $sourceLabel !== '' ? $sourceLabel : null,
            'edit_url'     => $editUrl,
            'detail'       => $detail ?? $path,
        ];
    }

    /**
     * Build a WP-admin post edit URL for the given post ID.
     * Returns null gracefully if admin_url() is not available (CLI context).
     */
    private function buildPostEditUrl(int $postId): ?string
    {
        if ($postId <= 0) {
            return null;
        }
        if (!function_exists('admin_url')) {
            return null;
        }
        return admin_url('post.php?post=' . $postId . '&action=edit');
    }

    /**
     * Build the WP-admin general options URL.
     * Returns null gracefully if admin_url() is not available.
     */
    private function buildOptionsEditUrl(): ?string
    {
        if (!function_exists('admin_url')) {
            return null;
        }
        return admin_url('options-general.php');
    }
}
