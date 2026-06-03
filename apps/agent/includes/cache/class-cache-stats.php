<?php
/**
 * CacheStats — counts cached pages and total bytes for the heartbeat gauge.
 *
 * The heartbeat fires every 60s, so this walk must be cheap; callers pass the
 * cache root and we recursively count only *.html.gz files and sum their sizes.
 * On very large caches a caller may cap the walk; here we keep it simple and
 * bounded by a max-files guard so a pathological cache can't stall the heartbeat.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Computes page count + total size of the disk cache.
 */
final class CacheStats
{
    /** Absolute cache root (…/cache/wpmgr). */
    private string $cacheRoot;

    /** Safety cap on files counted in one walk. */
    private int $maxFiles;

    /**
     * @param string $cacheRoot Absolute cache root.
     * @param int    $maxFiles  Max files to count before stopping (default 50k).
     */
    public function __construct(string $cacheRoot, int $maxFiles = 50000)
    {
        $this->cacheRoot = rtrim($cacheRoot, '/\\');
        $this->maxFiles  = max(1, $maxFiles);
    }

    /**
     * Compute {pages, bytes} for the disk cache.
     *
     * @return array{pages:int,bytes:int,truncated:bool}
     */
    public function collect(): array
    {
        $pages     = 0;
        $bytes     = 0;
        $truncated = false;

        if ($this->cacheRoot !== '' && @is_dir($this->cacheRoot)) {
            $this->walk($this->cacheRoot, $pages, $bytes, $truncated);
        }

        return ['pages' => $pages, 'bytes' => $bytes, 'truncated' => $truncated];
    }

    /**
     * Recursive walk accumulating page count + byte total for *.html.gz files.
     *
     * @param string $dir       Directory to scan.
     * @param int    $pages     Accumulator (by-ref).
     * @param int    $bytes     Accumulator (by-ref).
     * @param bool   $truncated Set true if the max-files cap was hit (by-ref).
     * @return void
     */
    private function walk(string $dir, int &$pages, int &$bytes, bool &$truncated): void
    {
        if ($truncated) {
            return;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;

            if (@is_dir($full) && !@is_link($full)) {
                $this->walk($full, $pages, $bytes, $truncated);
                if ($truncated) {
                    return;
                }
            } elseif (substr($entry, -strlen(CacheKey::EXTENSION)) === CacheKey::EXTENSION) {
                $pages++;
                $size = @filesize($full);
                if ($size !== false) {
                    $bytes += (int) $size;
                }
                if ($pages >= $this->maxFiles) {
                    $truncated = true;
                    return;
                }
            }
        }
    }
}
