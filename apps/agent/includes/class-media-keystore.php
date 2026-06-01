<?php
/**
 * MediaKeystore — typed postmeta accessor for the WPMgr image-optimization blob.
 *
 * Implements the standard WordPress "thin repository over postmeta" pattern:
 *   - Write:  update_post_meta()  — WordPress serialises the PHP array automatically.
 *   - Read:   get_post_meta()     — WordPress deserialises on read; absent key returns ''.
 *   - Delete: delete_post_meta()  — removes all entries for the key on the given post.
 *
 * Reference: https://developer.wordpress.org/reference/functions/update_post_meta/
 *            https://developer.wordpress.org/reference/functions/get_post_meta/
 *            https://developer.wordpress.org/reference/functions/delete_post_meta/
 *
 * All WordPress function calls are guarded with function_exists() so the class
 * remains loadable in unit-test or CLI contexts where WordPress is unavailable.
 * This is standard WordPress plugin practice for testability.
 *
 * @package WPMgr\Agent
 */

declare(strict_types=1);

namespace WPMgr\Agent;

/**
 * Pure stateless accessor (thin-repository pattern) over the single postmeta
 * key `wpmgr_image_optimization`. Carries no optimization logic.
 *
 * All callers instantiate this with `new MediaKeystore()` — no constructor
 * parameters, no injected dependencies.
 */
final class MediaKeystore
{
    /**
     * The postmeta key stored in wp_postmeta.meta_key.
     * Value must match exactly — referenced by DbRewriter::SKIP_META_KEYS.
     */
    public const KEY = 'wpmgr_image_optimization';

    /** Blob status value: all requested sizes were successfully optimized. */
    public const STATUS_OPTIMIZED = 'optimized';

    /** Blob status value: the attachment was excluded from optimization. */
    public const STATUS_EXCLUDED = 'excluded';

    /** Blob status value: on-disk originals have been permanently deleted. */
    public const STATUS_ORIGINALS_DELETED = 'originals_deleted';

    // -------------------------------------------------------------------------
    // Core read / write / delete
    // -------------------------------------------------------------------------

    /**
     * Retrieve the optimization blob for an attachment.
     *
     * Uses get_post_meta() with $single = true, which returns the stored PHP
     * array (deserialised by WordPress) or an empty string when the key is
     * absent. Any non-array result — including the empty-string sentinel — is
     * coerced to [].
     *
     * If get_post_meta() is unavailable (e.g. unit-test context without WP
     * loaded), returns [] immediately without calling anything.
     */
    public function get(int $attachmentId): array
    {
        if (!function_exists('get_post_meta')) {
            return [];
        }

        $value = get_post_meta($attachmentId, self::KEY, true);

        return is_array($value) ? $value : [];
    }

    /**
     * Return true when an optimization blob exists (non-empty) for the
     * given attachment. Purely derived from get().
     */
    public function has(int $attachmentId): bool
    {
        return $this->get($attachmentId) !== [];
    }

    /**
     * Return true when the blob's status field equals STATUS_OPTIMIZED exactly.
     *
     * Used as an idempotency gate (e.g. AutoOptimizeUpload bails early when
     * this returns true for an already-optimized attachment).
     */
    public function isOptimized(int $attachmentId): bool
    {
        $blob = $this->get($attachmentId);

        return ($blob['status'] ?? '') === self::STATUS_OPTIMIZED;
    }

    /**
     * Return true when the integer-cast value of original_deleted is exactly 1,
     * meaning on-disk originals have been permanently removed and restore is
     * impossible.
     */
    public function originalsDeleted(int $attachmentId): bool
    {
        $blob = $this->get($attachmentId);

        return (int) ($blob['original_deleted'] ?? 0) === 1;
    }

    /**
     * Persist an optimization blob for an attachment.
     *
     * Calls update_post_meta(), which serialises the PHP array automatically
     * via maybe_serialize() when writing to wp_postmeta.meta_value.
     *
     * If update_post_meta() is unavailable, returns immediately without
     * storing anything.
     */
    public function set(int $attachmentId, array $blob): void
    {
        if (!function_exists('update_post_meta')) {
            return;
        }

        update_post_meta($attachmentId, self::KEY, $blob);
    }

    /**
     * Remove the optimization blob for an attachment entirely.
     *
     * Calls delete_post_meta(), which deletes all wp_postmeta rows for the
     * key on the given post.
     *
     * If delete_post_meta() is unavailable, returns immediately.
     */
    public function delete(int $attachmentId): void
    {
        if (!function_exists('delete_post_meta')) {
            return;
        }

        delete_post_meta($attachmentId, self::KEY);
    }

    // -------------------------------------------------------------------------
    // Lifecycle mutations
    // -------------------------------------------------------------------------

    /**
     * Lifecycle shape #2 — post-restore stub.
     *
     * Called by MediaRestoreCommand::restoreOne after all disk and metadata
     * restoration is complete.
     *
     * Fast path (nothing unrestorable): when $sizesUnoptimized is empty, the
     * blob is fully removed via delete() and the method returns.
     *
     * Stub path (some sizes were unoptimizable): the blob is overwritten with
     * only compression_level and sizes_unoptimized so future optimize attempts
     * know to skip those sizes under the same profile. All other blob fields
     * are intentionally absent in the stub.
     */
    public function reduceAfterRestore(
        int $attachmentId,
        string $compressionLevel,
        array $sizesUnoptimized
    ): void {
        if ($sizesUnoptimized === []) {
            $this->delete($attachmentId);
            return;
        }

        $this->set($attachmentId, [
            'compression_level' => $compressionLevel,
            'sizes_unoptimized' => $sizesUnoptimized,
        ]);
    }

    /**
     * Flip the blob to the originals-deleted state after on-disk originals
     * have been permanently removed.
     *
     * Called by MediaDeleteOriginalsCommand::deleteOne after the on-disk
     * originals have been deleted.
     *
     * Guard: if the blob is absent ([]), the method does nothing and returns
     * immediately — there is nothing to mark.
     *
     * All other existing blob keys are preserved unchanged.
     */
    public function markOriginalsDeleted(int $attachmentId): void
    {
        $blob = $this->get($attachmentId);

        if ($blob === []) {
            return;
        }

        $blob['original_deleted'] = 1;
        $blob['status']           = self::STATUS_ORIGINALS_DELETED;

        $this->set($attachmentId, $blob);
    }
}
