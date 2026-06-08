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
            if (!@rename($filePath, $consuming)) {
                // Another process may have grabbed it; skip safely.
                continue;
            }

            $count = $this->countLines($consuming);
            @unlink($consuming);

            if ($kind === 'hit') {
                $hits  += $count;
            } else {
                $misses += $count;
            }
            $found = true;
        }

        return $found ? ['hits' => $hits, 'misses' => $misses] : null;
    }

    /**
     * Count the number of newline characters in a file.
     *
     * Each tally write appends exactly one "\n", so the newline count equals
     * the number of events recorded in that bucket.
     *
     * @param string $path Absolute path to the file.
     * @return int Number of newlines (0 on read failure or empty file).
     */
    private function countLines(string $path): int
    {
        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return 0;
        }
        return substr_count($content, "\n");
    }
}
