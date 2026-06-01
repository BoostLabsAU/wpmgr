<?php
/**
 * FileScanner — resumable depth-first filesystem walker with MD5 integrity hashing.
 *
 * Technique 1 (MD5 integrity):
 *   The WordPress.org Checksums API (api.wordpress.org/core/checksums/1.0/) uses MD5 hashes
 *   for core file integrity verification. PHP's md5_file() computes a whole-file MD5 without
 *   loading the file into memory as a string. WP-CLI's `checksum` command uses the same
 *   approach. The 32-character lowercase hex output is the canonical format.
 *
 * Technique 2 (Resumable cursor-based DFS):
 *   Standard technique for bounded, resumable filesystem depth-first search used in
 *   incremental crawl and cursor-based pagination systems. The cursor encodes three pieces
 *   of state: (a) the fixed base directory of the scan, (b) a stack of [dirname, read-offset]
 *   frames from root to current node, and (c) the read-offset within the current (deepest)
 *   node. On resume, directories are reopened and repositioned by sequential readdir() calls
 *   (PHP directory handles are not seekable by position). Symlinks are recorded but never
 *   recursed into — a standard safe-crawl convention preventing infinite loops.
 *
 * @package WPMgr\Agent\Support
 */

declare(strict_types=1);

namespace WPMgr\Agent\Support;

/**
 * Stateless filesystem scanner. Instantiated fresh per request; holds no shared state.
 */
final class FileScanner
{
    /**
     * Files/directories in the WordPress root that the control plane allowlist-diffs.
     * Order is preserved and must match the CP contract exactly.
     *
     * @var list<string>
     */
    public const ALLOWED_FILES = [
        '.htaccess',
        '.user.ini',
        'wp-config.php',
        '.maintenance',
        'object-cache.php',
        'advanced-cache.php',
        'wp-content/',
    ];

    /**
     * Top-level wp-content subdirectories excluded from scans by default.
     * Mirrors BackupSource::EXCLUDE_DIRS — must stay in sync.
     *
     * @var list<string>
     */
    private const EXCLUDE_DIRS = [
        'wpmgr-snapshots',
        'wpmgr-agent',
        'cache',
        'upgrade',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Perform a resumable depth-first scan of the filesystem under $absRoot.
     *
     * Uses a cursor (dir + traversal_stack + folder_offset) so that large trees
     * can be walked incrementally across multiple HTTP requests without re-visiting
     * any entry. This is the standard cursor-based DFS pagination pattern.
     *
     * @param string      $absRoot           Absolute path to the WordPress root.
     * @param string      $relStartDir       Relative sub-directory to start from ('' = root).
     * @param bool        $includeMd5        Whether to compute md5_file() for each regular file.
     * @param float       $timeBudgetS       Wall-clock seconds before yielding a partial cursor.
     * @param int         $pathsLimit        Max entries to process (0 = unlimited).
     * @param int         $batchSize         Unused; reserved for future chunking strategies.
     * @param int         $traversalStackMax Max directory depth (frames on the traversal stack).
     * @param array|null  $resumeCursor      Opaque cursor from a previous partial result, or null.
     * @param array       $excludeTopDirs    Top-level directory names to skip entirely.
     *
     * @return array{
     *   status: "partial"|"done",
     *   files_scanned: int,
     *   next_cursor: array{dir:string,traversal_stack:list<list<mixed>>,folder_offset:int}|null,
     *   links: list<string>,
     *   hashes: list<array<string,mixed>>
     * }
     */
    public function scan(
        string $absRoot,
        string $relStartDir,
        bool $includeMd5,
        float $timeBudgetS,
        int $pathsLimit,
        int $batchSize,
        int $traversalStackMax,
        ?array $resumeCursor,
        array $excludeTopDirs = []
    ): array {
        // ── 1. Normalise absRoot ────────────────────────────────────────────
        $absRoot = $this->normaliseRoot($absRoot);

        // ── 2. Deadline ─────────────────────────────────────────────────────
        $deadline = microtime(true) + $timeBudgetS;

        // ── 3. Unpack cursor ────────────────────────────────────────────────
        $dir            = $resumeCursor['dir']             ?? $relStartDir;
        $traversalStack = $resumeCursor['traversal_stack'] ?? [];
        $folderOffset   = (int) ($resumeCursor['folder_offset'] ?? 0);

        // ── 4. Open the starting directory handle ───────────────────────────
        $basePath      = $this->buildBasePath($dir, $traversalStack);
        $absDirTrimmed = rtrim($absRoot . '/' . $basePath, '/');
        if ($absDirTrimmed === '') {
            $absDirTrimmed = $absRoot;
        }

        $handle = @opendir($absDirTrimmed);
        if ($handle !== false) {
            // Reposition to after the already-processed entries.
            $this->seekDirHandle($handle, $folderOffset);
        }

        // ── Accumulator initialisation ──────────────────────────────────────
        $filesScanned = 0;
        $links        = [];
        $hashes       = [];
        $status       = 'done';

        // ── Main DFS loop ───────────────────────────────────────────────────
        while (true) {
            // Time budget check.
            if (microtime(true) >= $deadline) {
                $status = 'partial';
                break;
            }

            // Paths limit check.
            if ($pathsLimit > 0 && $filesScanned >= $pathsLimit) {
                $status = 'partial';
                break;
            }

            // Read next entry from the current directory handle.
            $file = ($handle !== false) ? @readdir($handle) : false;

            if ($file === false) {
                // Current directory exhausted.
                if ($handle !== false) {
                    closedir($handle);
                    $handle = false;
                }

                if (empty($traversalStack)) {
                    // DFS complete.
                    $status = 'done';
                    break;
                }

                // Pop parent frame and reopen it, seeking to its recorded offset.
                $frame        = array_pop($traversalStack);
                $resumeOffset = (int) $frame[1];

                $basePath  = $this->buildBasePath($dir, $traversalStack);
                $absParent = rtrim($absRoot . '/' . $basePath, '/');
                if ($absParent === '') {
                    $absParent = $absRoot;
                }

                $handle = @opendir($absParent);
                if ($handle !== false) {
                    $this->seekDirHandle($handle, $resumeOffset);
                }
                $folderOffset = $resumeOffset;
                continue;
            }

            // Skip dot-entries.
            if ($file === '.' || $file === '..') {
                continue;
            }

            // Advance position counter (tracks real entries read from current dir).
            ++$folderOffset;

            $basePath     = $this->buildBasePath($dir, $traversalStack);
            $relativePath = $basePath . $file;
            $absolutePath = $absRoot . '/' . $relativePath;

            // ── excludeTopDirs ──────────────────────────────────────────────
            $slashPos     = strpos($relativePath, '/');
            $topComponent = ($slashPos !== false)
                ? substr($relativePath, 0, $slashPos)
                : $relativePath;

            if (in_array($topComponent, $excludeTopDirs, true)) {
                continue;
            }

            // ── Symlink: record but do not recurse ──────────────────────────
            // Safe-crawl convention: recording symlinks without following them
            // prevents infinite loops in the DFS while still surfacing them to
            // the control plane for inspection.
            if (is_link($absolutePath)) {
                $links[]  = $relativePath;
                $hashes[] = $this->fileStat($absRoot, $relativePath, $includeMd5);
                ++$filesScanned;
                continue;
            }

            // ── Directory: push frame and descend ───────────────────────────
            if (is_dir($absolutePath)) {
                if (count($traversalStack) >= $traversalStackMax) {
                    // Depth guard: skip the entire subtree.
                    continue;
                }

                if ($handle !== false) {
                    closedir($handle);
                    $handle = false;
                }

                // Record current offset before descending so that resuming
                // from this frame's parent will skip past this entry.
                $traversalStack[] = [$file, $folderOffset];

                $newBasePath = $this->buildBasePath($dir, $traversalStack);
                $absChild    = rtrim($absRoot . '/' . $newBasePath, '/');

                $handle       = @opendir($absChild);
                $folderOffset = 0;
                continue;
            }

            // ── Regular file ─────────────────────────────────────────────────
            $hashes[] = $this->fileStat($absRoot, $relativePath, $includeMd5);
            ++$filesScanned;
        }

        // ── Teardown ─────────────────────────────────────────────────────────
        if ($handle !== false) {
            closedir($handle);
        }

        $nextCursor = null;
        if ($status === 'partial') {
            $nextCursor = [
                'dir'             => $dir,
                'traversal_stack' => $traversalStack,
                'folder_offset'   => $folderOffset,
            ];
        }

        return [
            'status'        => $status,
            'files_scanned' => $filesScanned,
            'next_cursor'   => $nextCursor,
            'links'         => $links,
            'hashes'        => $hashes,
        ];
    }

    /**
     * Return the base64-encoded content of a single regular file.
     *
     * Multiple guards enforce: path containment within $absRoot, rejection of
     * symlinks and directories, and an optional byte-size cap.
     *
     * @param string $absRoot  Absolute WordPress root path.
     * @param string $relPath  Site-relative path to the file.
     * @param int    $maxBytes Maximum allowed file size in bytes (0 = unlimited).
     *
     * @return array{
     *   ok: bool,
     *   path: string,
     *   size: int,
     *   is_dir: bool,
     *   is_link: bool,
     *   content_base64: string|null,
     *   error: string|null
     * }
     */
    public function getFileContent(
        string $absRoot,
        string $relPath,
        int $maxBytes
    ): array {
        // ── 1. Normalise roots ───────────────────────────────────────────────
        $absRoot = $this->normaliseRoot($absRoot);
        $relPath = str_replace('\\', '/', $relPath);

        // ── Base return skeleton ─────────────────────────────────────────────
        $result = [
            'ok'             => false,
            'path'           => $relPath,
            'size'           => 0,
            'is_dir'         => false,
            'is_link'        => false,
            'content_base64' => null,
            'error'          => null,
        ];

        // ── 2. Strip leading slash; validate path segments ───────────────────
        $stripped = ltrim($relPath, '/');

        // Reject empty or NUL-containing paths.
        if ($stripped === '' || strpos($stripped, "\0") !== false) {
            $result['error'] = 'NOT_READABLE';
            return $result;
        }

        // Reject any segment equal to '..' or '.' to block traversal.
        $segments = explode('/', $stripped);
        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.') {
                $result['error'] = 'OUTSIDE_ROOT';
                return $result;
            }
        }

        // ── 3. Build joined path and lstat ───────────────────────────────────
        $joined = $absRoot . '/' . $stripped;
        $lstat  = @lstat($joined);
        if ($lstat === false) {
            $result['error'] = 'NOT_READABLE';
            return $result;
        }

        // ── 4. Symlink guard ─────────────────────────────────────────────────
        if (is_link($joined)) {
            $result['is_link'] = true;
            $result['error']   = 'IS_LINK';
            return $result;
        }

        // ── 5. Directory guard ───────────────────────────────────────────────
        if (is_dir($joined)) {
            $result['is_dir'] = true;
            $result['error']  = 'IS_DIR';
            return $result;
        }

        // ── 6. Realpath containment check ────────────────────────────────────
        $real = realpath($joined);
        if ($real === false) {
            $result['error'] = 'NOT_READABLE';
            return $result;
        }

        // Verify the resolved path stays within absRoot (guards symlink escapes).
        // strncmp is used instead of str_starts_with for PHP 7.x portability.
        if (strncmp($real, $absRoot . '/', strlen($absRoot) + 1) !== 0) {
            $result['error'] = 'OUTSIDE_ROOT';
            return $result;
        }

        // ── 7. File size ─────────────────────────────────────────────────────
        $size = @filesize($real);
        if ($size === false) {
            $result['error'] = 'NOT_READABLE';
            return $result;
        }
        $result['size'] = $size;

        // ── 8. Size cap ──────────────────────────────────────────────────────
        if ($maxBytes > 0 && $size > $maxBytes) {
            $result['error'] = 'too_large';
            return $result;
        }

        // ── 9. Read content ──────────────────────────────────────────────────
        $content = @file_get_contents($real);
        if ($content === false) {
            $result['error'] = 'NOT_READABLE';
            return $result;
        }

        // ── 10. Success ──────────────────────────────────────────────────────
        $result['ok']             = true;
        $result['content_base64'] = base64_encode($content);
        $result['error']          = null;
        return $result;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the relative path prefix for entries in the current DFS node.
     *
     * Combines the fixed base directory ($dir) with the directory names extracted
     * from the traversal stack frames to produce a prefix ending with '/' (or ''
     * when at the root with an empty stack).
     *
     * @param string      $dir            The relStartDir argument (fixed for the whole scan).
     * @param list<array> $traversalStack Stack frames: each is [string $name, int $offset].
     * @return string Path prefix ending with '/' or empty string.
     */
    private function buildBasePath(string $dir, array $traversalStack): string
    {
        // Strip trailing slash from the base directory.
        $dir = rtrim($dir, '/');

        if (empty($traversalStack)) {
            return ($dir === '') ? '' : $dir . '/';
        }

        // Collect the directory name (index 0) from each stack frame.
        $names   = array_column($traversalStack, 0);
        $subPath = implode('/', $names);

        if ($dir === '') {
            return $subPath . '/';
        }

        return $dir . '/' . $subPath . '/';
    }

    /**
     * Advance a directory handle past $offset real entries (skipping . and ..).
     *
     * PHP directory handles are not seekable by numeric position; the only way
     * to reposition them is sequential reads. This is the standard approach for
     * cursor-based directory pagination with opendir/readdir.
     *
     * @param resource $handle Directory handle (as returned by opendir()).
     * @param int      $offset Number of real entries to skip.
     */
    private function seekDirHandle($handle, int $offset): void
    {
        while ($offset > 0) {
            $entry = @readdir($handle);
            if ($entry === false) {
                break;
            }
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            --$offset;
        }
    }

    /**
     * Stat a single filesystem entry and return a hash-row array.
     *
     * For regular files with $includeMd5 = true, computes an MD5 using md5_file() —
     * the same technique as the WordPress.org Checksums API and WP-CLI's `checksum`
     * command. lstat() is used for symlinks so the stat reflects the link itself
     * rather than its target.
     *
     * @param string $absRoot      Absolute root path (no trailing slash).
     * @param string $relativePath Site-relative path of the entry.
     * @param bool   $includeMd5   Whether to compute md5_file().
     * @return array<string,mixed> Hash row.
     */
    private function fileStat(string $absRoot, string $relativePath, bool $includeMd5): array
    {
        $absFile = $absRoot . '/' . $relativePath;
        $isLink  = is_link($absFile);

        // Normalise forward slashes in the stored path.
        $normPath = str_replace('\\', '/', $relativePath);

        $row = [
            'path'    => $normPath,
            'size'    => 0,
            'md5'     => '',
            'mtime'   => 0,
            'mode'    => 0,
            'is_link' => $isLink,
        ];

        // Readability guard: unreadable entries return a minimal error row.
        if (!@is_readable($absFile)) {
            unset($row['md5']);
            $row['error'] = 'NOT_READABLE';
            return $row;
        }

        // lstat for symlinks (reflects the link), stat for everything else.
        $statResult = $isLink ? @lstat($absFile) : @stat($absFile);
        if ($statResult === false) {
            unset($row['md5']);
            $row['error'] = 'NOT_READABLE';
            return $row;
        }

        // Extract named stat keys (exact PHP stat() key names).
        foreach (['size', 'uid', 'gid', 'mode', 'mtime'] as $key) {
            if (isset($statResult[$key])) {
                $row[$key] = $statResult[$key];
            }
        }

        // Force int casts for the three mandatory numeric fields.
        $row['size']  = (int) ($row['size']  ?? 0);
        $row['mtime'] = (int) ($row['mtime'] ?? 0);
        $row['mode']  = (int) ($row['mode']  ?? 0);

        // MD5: only for non-symlink regular files when requested.
        // md5_file() is the PHP built-in implementing the WordPress.org
        // Checksums API methodology — whole-file MD5, 32-char lowercase hex.
        $isDir = is_dir($absFile);
        if ($includeMd5 && !$isLink && !$isDir) {
            $row['md5'] = $this->calculateMd5($absFile);
        }

        return $row;
    }

    /**
     * Compute the MD5 hash of a file using PHP's md5_file() built-in.
     *
     * This follows the WordPress.org Checksums API (api.wordpress.org/core/checksums/1.0/)
     * and WP-CLI `checksum` convention: whole-file MD5, 32-character lowercase hex output.
     *
     * @param string $absFile Absolute path to the file.
     * @return string 32-char lowercase hex MD5, or '' on failure.
     */
    private function calculateMd5(string $absFile): string
    {
        try {
            $hash = @md5_file($absFile);
            return ($hash !== false) ? $hash : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Normalise an absolute root path: forward slashes, strip trailing slash,
     * then resolve via realpath() to canonicalise OS-level symlinked mount points
     * (e.g. macOS /tmp -> /private/tmp).
     *
     * @param string $root Raw root path.
     * @return string Normalised absolute path without trailing slash.
     */
    private function normaliseRoot(string $root): string
    {
        $root = str_replace('\\', '/', $root);
        $root = rtrim($root, '/');

        $resolved = realpath($root);
        if ($resolved !== false) {
            $root = str_replace('\\', '/', $resolved);
        }

        return $root;
    }
}
