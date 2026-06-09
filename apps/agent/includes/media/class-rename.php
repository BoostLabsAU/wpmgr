<?php
/**
 * Rename: the `.wpmgr-original.*` archive/restore engine.
 *
 * Implements the §2 archive rename pattern (analysis doc §2, lines 766-781
 * `rename_files()` + lines 871-884 `change_extension()`) under WPMgr naming.
 * The marker is `wpmgr-original`.
 *
 * The whole same-ext archive scheme hinges on this class:
 *   archive('/x/banner.jpg')  -> renames to '/x/banner.wpmgr-original.jpg'
 *   restore('/x/banner.wpmgr-original.jpg') -> renames back to '/x/banner.jpg'
 *
 * Pure filesystem ops — no WP, no DB. Every method is idempotent-friendly:
 * a missing source is a no-op (returns the would-be target), so a partial
 * archive can be retried without error. The archive name is a DOUBLE
 * extension: `change_extension($path, 'wpmgr-original.' . $ext)`
 * (analysis doc line 777).
 *
 * @package WPMgr\Agent\Media
 */

declare(strict_types=1);

namespace WPMgr\Agent\Media;

/**
 * Archive an original image to a double-extension name and reverse it.
 */
final class Rename
{
    /**
     * The archive marker inserted before the extension. Renaming
     * `banner.jpg` to `banner.wpmgr-original.jpg` makes the archive name a
     * "double extension" so a restore can strip the marker unambiguously.
     */
    public const SUFFIX = 'wpmgr-original';

    /**
     * Archive an original file by renaming it to its double-extension name.
     * `/x/banner.jpg` -> `/x/banner.wpmgr-original.jpg`. Used in the SAME-EXT
     * (target_format='original') branch BEFORE writing the optimized bytes at
     * the original path (analysis doc lines 90, 126).
     *
     * @param string $path Absolute path to the original file.
     * @return string The archive path (whether or not the source existed).
     */
    public function archive(string $path): string
    {
        $ext     = $this->extensionOf($path);
        $archive = $this->changeExtension($path, self::SUFFIX . '.' . $ext);

        if ($path !== '' && @is_file($path)) {
            @rename($path, $archive); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic same-filesystem swap; WP_Filesystem::move() is copy+delete (non-atomic) and breaks crash-resume safety
        }

        return $archive;
    }

    /**
     * Restore an archived original by stripping the marker.
     * `/x/banner.wpmgr-original.jpg` -> `/x/banner.jpg`. The reverse of
     * archive() (analysis doc line 776).
     *
     * @param string $archived Absolute path to the archived (double-extension) file.
     * @return string The restored path (whether or not the archive existed).
     */
    public function restore(string $archived): string
    {
        $restored = str_replace('.' . self::SUFFIX, '', $archived);

        if ($archived !== '' && $archived !== $restored && @is_file($archived)) {
            @rename($archived, $restored); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic same-filesystem swap; WP_Filesystem::move() is copy+delete (non-atomic) and breaks crash-resume safety
        }

        return $restored;
    }

    /**
     * Compute the archive path for an original WITHOUT touching the disk.
     * Used to predict where the archive lives during restore/delete-originals.
     *
     * @param string $path Absolute path to the original file.
     * @return string The archive path.
     */
    public function archivePathFor(string $path): string
    {
        $ext = $this->extensionOf($path);

        return $this->changeExtension($path, self::SUFFIX . '.' . $ext);
    }

    /**
     * Swap a path/URL's trailing extension. Pure string op (analysis doc lines
     * 871-884). Returns the input unchanged when the extension already matches;
     * otherwise replaces the trailing `.<ext>` segment.
     *
     * @param string $pathOrUrl Path or URL.
     * @param string $newExt    New extension (no leading dot), e.g. 'avif' or
     *                          'wpmgr-original.jpg' for the synthetic archive name.
     * @return string The path/URL with the new trailing extension.
     */
    public function changeExtension(string $pathOrUrl, string $newExt): string
    {
        if ($pathOrUrl === '' || $newExt === '') {
            return $pathOrUrl;
        }

        $currentExt = $this->extensionOf($pathOrUrl);
        if ($currentExt === $newExt) {
            return $pathOrUrl;
        }

        // Replace the trailing `.<ext>` only (analysis doc line 883).
        $result = preg_replace('/\.[^\.\/]+$/', '.' . $newExt, $pathOrUrl);

        return is_string($result) ? $result : $pathOrUrl;
    }

    /**
     * The lower-cased trailing extension of a path/URL ('' when none).
     *
     * @param string $pathOrUrl Path or URL.
     * @return string
     */
    public function extensionOf(string $pathOrUrl): string
    {
        $ext = pathinfo($pathOrUrl, PATHINFO_EXTENSION);

        return is_string($ext) ? strtolower($ext) : '';
    }
}
