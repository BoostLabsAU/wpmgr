<?php
/**
 * MediaAttachmentRow: single source of truth for the syncBatchAttachmentDTO row shape.
 *
 * This helper is the shared seam used by BOTH MediaSyncCommand (batch sync) and
 * AutoOptimizeUpload::drain() (auto-optimize-on-upload) to build the attachment
 * row that matches the CP's syncBatchAttachmentDTO
 * (apps/api/internal/media/handler/agent_handler.go):
 *
 *   { wp_attachment_id, title, original_path, original_url, original_mime,
 *     original_width, original_height, original_size_bytes }
 *
 * Having a single static method here guarantees that both callers always produce
 * the same shape. Any future field addition or size-backfill logic change only
 * needs to happen in one place.
 *
 * @package WPMgr\Agent\Media
 */

declare(strict_types=1);

namespace WPMgr\Agent\Media;

/**
 * Builds one syncBatchAttachmentDTO-shaped row from a WP attachment id.
 */
final class MediaAttachmentRow
{
    /**
     * Build one syncBatchAttachmentDTO-shaped row for the given attachment id.
     *
     * Returns null when the attachment cannot be resolved (e.g. deleted between
     * the time the id was buffered and the drain/sync invocation).
     *
     * Row keys (exact, matching the CP contract):
     *   - wp_attachment_id    (int)
     *   - title               (string)
     *   - original_path       (string)
     *   - original_url        (string)
     *   - original_mime       (string)
     *   - original_width      (int|null)
     *   - original_height     (int|null)
     *   - original_size_bytes (int)
     *
     * Size baseline: original_size_bytes is the attachment's FULL image file
     * size — the figure WordPress's own "File size" shows and users expect, NOT
     * the sum of every generated sub-size (summing the renditions double-counts
     * a `-scaled` image ≈ 2x). For a never-optimized attachment the live
     * `filesize` IS the original full. For one already optimized by WPMgr the
     * live `filesize` is now the OPTIMIZED full, so we read the original full
     * from the optimization blob's `original_data` snapshot (key:
     * wpmgr_image_optimization) — a copy of _wp_attachment_metadata taken BEFORE
     * optimization. This keeps a re-sync reporting the true original baseline.
     * Fallback: if the blob is absent (never optimized), malformed, or yields
     * zero, we keep the plain full-file size computed above.
     *
     * @param int $id WP attachment post id.
     * @return array<string,mixed>|null Null when the attachment cannot be resolved.
     */
    public static function build(int $id): ?array
    {
        if (!function_exists('wp_get_attachment_metadata')) {
            return null;
        }
        $meta = wp_get_attachment_metadata($id);
        $meta = is_array($meta) ? $meta : [];

        $path = function_exists('get_attached_file') ? (string) get_attached_file($id) : '';
        $url  = function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url($id) : '';
        $mime = function_exists('get_post_mime_type') ? (string) get_post_mime_type($id) : '';

        $title = '';
        if (function_exists('get_the_title')) {
            $title = (string) get_the_title($id);
        }

        $width  = isset($meta['width']) ? (int) $meta['width'] : null;
        $height = isset($meta['height']) ? (int) $meta['height'] : null;

        $size = 0;
        if (isset($meta['filesize']) && is_numeric($meta['filesize'])) {
            $size = (int) $meta['filesize'];
        } elseif ($path !== '' && @is_file($path)) {
            $size = (int) @filesize($path);
        }

        // variant_count is the image-FILE count for this attachment: the full
        // image (1) PLUS every generated sub-size. It drives the dashboard's
        // "Images (incl. thumbnails)" headline. The live metadata's `sizes` count
        // is stable across optimization (a same-ext/coexist apply keeps the same
        // set of sizes), so the live meta is an accurate source whether or not the
        // attachment is optimized.
        $variantCount = 1;
        if (isset($meta['sizes']) && is_array($meta['sizes'])) {
            $variantCount += count($meta['sizes']);
        }

        // Original-baseline restore + all-variant savings: for an attachment
        // already optimized by WPMgr the live `filesize` above is the OPTIMIZED
        // full (small), but original_size_bytes must be the ORIGINAL full. The
        // optimization blob (key: wpmgr_image_optimization, stored as a PHP array
        // by MediaKeystore::set / update_post_meta) carries `original_data` — the
        // pre-optimize _wp_attachment_metadata snapshot — and `optimized_data` —
        // per-variant optimized records. Read the original full file's bytes for
        // the baseline, and the all-variant savings so a re-sync heals an
        // already-optimized row's saved_bytes WITHOUT re-optimizing. Fallback: if
        // the blob is absent (never optimized), malformed, or yields zero, keep
        // the plain full-file $size and saved_bytes = 0.
        $savedBytes = 0;
        if (function_exists('get_post_meta')) {
            $blob = get_post_meta($id, 'wpmgr_image_optimization', true);
            if (is_array($blob) && $blob !== []) {
                $origData = isset($blob['original_data']) && is_array($blob['original_data'])
                    ? $blob['original_data']
                    : [];
                if ($origData !== [] && !empty($origData['file'])) {
                    // The full file's bytes — NOT a sum of sub-sizes (that
                    // double-counts a `-scaled` image's intermediates ≈ 2x).
                    $originalFull = AttachmentMeta::fullBytes($origData);
                    if ($originalFull > 0) {
                        $size = $originalFull;
                    }
                }
                $optData = isset($blob['optimized_data']) && is_array($blob['optimized_data'])
                    ? $blob['optimized_data']
                    : [];
                $savedBytes = AttachmentMeta::savedBytes($origData, $optData);
            }
        }

        return [
            'wp_attachment_id'    => $id,
            'title'               => $title,
            'original_path'       => $path,
            'original_url'        => $url,
            'original_mime'       => $mime,
            'original_width'      => $width,
            'original_height'     => $height,
            'original_size_bytes' => $size,
            'variant_count'       => $variantCount,
            'saved_bytes'         => $savedBytes,
        ];
    }

    // Non-instantiable utility class.
    private function __construct() {}
}
