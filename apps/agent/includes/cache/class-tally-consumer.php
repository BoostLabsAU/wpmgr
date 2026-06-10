<?php
/**
 * TallyConsumer — reads and drains the append-only hit/miss tally files written
 * by the page-cache drop-in (wpmgr-advanced-cache.php).
 *
 * The drop-in appends a single newline to an hour-bucketed counter file on every
 * cache HIT and MISS, completely without DB or WP calls. The files live at:
 *
 *   {wp-content}/cache/wpmgr/.metrics/hit-YYYYMMDDHH
 *   {wp-content}/cache/wpmgr/.metrics/miss-YYYYMMDDHH
 *
 * At heartbeat time (full WP loaded) this class consumes only COMPLETED hour
 * buckets — every bucket whose hour is strictly less than the current UTC hour.
 * For each file it: (1) atomically renames it to a .consuming suffix so a
 * concurrent drop-in append goes to a fresh file rather than the one being read,
 * (2) counts the newlines, (3) unlinks the .consuming file.
 *
 * Unlinking is the reset mechanism: there is no cumulative drift and a
 * purge/restart cannot double-count. The in-progress (current-hour) bucket is
 * always left untouched.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Drains completed hour-bucket tally files and returns the delta counts.
 */
final class TallyConsumer
{
    /** Sub-directory under the cache root where tally files are written. */
    public const METRICS_SUBDIR = '.metrics';

    /** Absolute path to the .metrics directory. */
    private string $metricsDir;

    /**
     * @param string $cacheRoot Absolute path to the WPMgr cache root
     *                         (…/wp-content/cache/wpmgr). Trailing slashes are stripped.
     */
    public function __construct(string $cacheRoot)
    {
        $this->metricsDir = rtrim($cacheRoot, '/\\') . '/' . self::METRICS_SUBDIR;
    }

    /**
     * Consume all completed hour buckets and return the summed delta.
     *
     * "Completed" means the bucket's UTC hour < the current UTC hour. The
     * in-progress (current-hour) bucket is never touched.
     *
     * Persist-then-acknowledge safety: buckets are renamed to a .consuming
     * staging name and counted BEFORE deletion. Deletion only happens for
     * buckets that were successfully staged in THIS call — it does not happen
     * before the counts are assembled and returned. Any pre-existing orphaned
     * .consuming files from a prior crashed cycle are recovered here and their
     * counts included, so a crash mid-cycle never permanently loses events.
     *
     * Returns null when no completed buckets exist (no history row should be
     * emitted; the CP chart must not flatline with zero rows).
     *
     * @return array{hits:int,misses:int}|null Delta counts, or null when empty.
     */
    public function consume(): ?array
    {
        if (!@is_dir($this->metricsDir)) {
            return null;
        }

        $entries = @scandir($this->metricsDir);
        if ($entries === false) {
            return null;
        }

        // Current UTC hour string (e.g. "2026060814"). Completed buckets have a
        // smaller suffix.
        $currentHour = gmdate('YmdH');

        $hits   = 0;
        $misses = 0;
        $found  = false;

        // Track .consuming files staged in THIS call so we can delete them only
        // after all counts are assembled (persist-then-acknowledge).
        $toDelete = [];

        // --- Pass 1: recover orphaned .consuming files from a prior crashed cycle.
        // These were renamed in a previous call that exited before wp_delete_file().
        // Include their counts now so the events are never permanently lost.
        foreach ($entries as $entry) {
            // Match hit-YYYYMMDDHH.consuming or miss-YYYYMMDDHH.consuming.
            if (!preg_match('/^(hit|miss)-(\d{10})\.consuming$/', $entry, $m)) {
                continue;
            }
            $kind       = $m[1];
            $bucketHour = $m[2];

            // Only recover orphans from completed hours; skip current-hour orphans
            // (should not exist, but guard against clock skew).
            if ($bucketHour >= $currentHour) {
                continue;
            }

            $consuming = $this->metricsDir . '/' . $entry;
            $count     = $this->countEvents($consuming);

            if ($kind === 'hit') {
                $hits += $count;
            } else {
                $misses += $count;
            }
            $toDelete[] = $consuming;
            $found      = true;
        }

        // --- Pass 2: stage and count fresh completed buckets.
        foreach ($entries as $entry) {
            // Match hit-YYYYMMDDHH or miss-YYYYMMDDHH files only.
            if (!preg_match('/^(hit|miss)-(\d{10})$/', $entry, $m)) {
                continue;
            }
            $kind       = $m[1]; // 'hit' or 'miss'
            $bucketHour = $m[2]; // e.g. '2026060813'

            // Skip the in-progress (current) hour bucket.
            if ($bucketHour >= $currentHour) {
                continue;
            }

            $filePath = $this->metricsDir . '/' . $entry;

            // Atomically rename so concurrent drop-in appends start a fresh file.
            $consuming = $filePath . '.consuming';
            if (!@rename($filePath, $consuming)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic same-filesystem swap; WP_Filesystem::move() is copy+delete (non-atomic) and breaks crash-resume safety
                // Another process may have grabbed it; skip safely.
                continue;
            }

            $count = $this->countEvents($consuming);

            if ($kind === 'hit') {
                $hits  += $count;
            } else {
                $misses += $count;
            }
            $toDelete[] = $consuming;
            $found      = true;
        }

        // --- Acknowledge: delete staged files only after all counts are final.
        // If the process exits before this point the orphan-recovery pass above
        // picks them up on the next heartbeat, so no events are permanently lost.
        foreach ($toDelete as $consuming) {
            wp_delete_file($consuming);
        }

        return $found ? ['hits' => $hits, 'misses' => $misses] : null;
    }

    /**
     * Count the number of events recorded in a tally file.
     *
     * Each tally write appends exactly one byte ("\n"), so the file size in
     * bytes equals the event count exactly. Using filesize() is O(1) (a stat
     * call) and avoids loading the entire file into memory — important on
     * high-traffic sites where a single hour bucket can hold millions of events.
     *
     * clearstatcache() is called before filesize() because the file was just
     * renamed, and PHP may have cached a stale stat entry for the new path.
     *
     * @param string $path Absolute path to the (already-renamed) .consuming file.
     * @return int Event count (0 on stat failure or empty file).
     */
    private function countEvents(string $path): int
    {
        clearstatcache(true, $path);
        $size = @filesize($path);
        return ($size === false || $size <= 0) ? 0 : (int) $size;
    }
}
