<?php
/**
 * WpConfigEditor — atomic, corruption-proof editor for wp-config.php constants.
 *
 * Inserting / removing a `define()` in wp-config.php is dangerous: a half-written
 * file or a botched regex bricks the entire site. This editor:
 *
 *   - Resolves the real wp-config.php path (ABSPATH, or one level up — the
 *     standard "config outside web root" layout).
 *   - Validates writability BEFORE attempting any change; refuses (returns
 *     false) rather than risk a partial write it cannot complete.
 *   - Writes to a sibling temp file then atomically rename()s over the target
 *     (atomic on POSIX), so a concurrent PHP request never reads a half-written
 *     config.
 *   - Is idempotent: setting a constant to its current value is a no-op;
 *     removing an absent constant is a no-op.
 *
 * The managed-define marker convention follows the same idiom WordPress core
 * uses for `// Added by ...` annotations. Original implementation.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Atomic wp-config.php constant editor.
 */
final class WpConfigEditor
{
    /** Annotation appended to defines this editor inserts. */
    public const MARKER = '// Added by WPMgr Cache';

    /** Optional explicit path override (test fixtures). */
    private ?string $explicitPath;

    /**
     * @param string|null $explicitPath Absolute path to a wp-config.php (used by
     *   tests / unusual layouts). When null the path is auto-resolved.
     */
    public function __construct(?string $explicitPath = null)
    {
        $this->explicitPath = $explicitPath;
    }

    /**
     * Resolve the wp-config.php path, preferring the in-root file, then the
     * one-directory-up location (WP's documented "move wp-config out of web
     * root" layout). Returns '' when neither is found.
     *
     * @return string Absolute path or ''.
     */
    public function configPath(): string
    {
        if ($this->explicitPath !== null) {
            return $this->explicitPath;
        }

        $candidates = [];
        if (defined('ABSPATH')) {
            $abs = rtrim((string) constant('ABSPATH'), '/\\');
            $candidates[] = $abs . '/wp-config.php';
            // One level up, but NOT if a wp-settings.php sits there (then the
            // parent dir belongs to another install — WP's own guard).
            $candidates[] = dirname($abs) . '/wp-config.php';
        }

        foreach ($candidates as $i => $path) {
            if (@is_file($path) && @is_readable($path)) {
                // For the parent-dir candidate, mirror WP core's safety check:
                // skip it if a wp-settings.php is a sibling (different install).
                if ($i === 1 && @is_file(dirname($path) . '/wp-settings.php')) {
                    continue;
                }
                return $path;
            }
        }

        return '';
    }

    /**
     * Whether wp-config.php exists and can be safely rewritten (the file itself
     * is writable AND its directory is writable so the temp+rename can complete).
     *
     * @return bool
     */
    public function isWritable(): bool
    {
        $path = $this->configPath();
        if ($path === '' || !@is_file($path)) {
            return false;
        }
        return @is_writable($path) && @is_writable(dirname($path));
    }

    /**
     * Ensure `define('NAME', value);` exists with exactly $value. Idempotent.
     *
     * If the constant is already defined to the same value the file is left
     * untouched (returns true). Otherwise any prior define line is stripped and a
     * fresh one is inserted immediately after the opening `<?php` tag.
     *
     * @param string          $name  Constant name (validated [A-Z0-9_]).
     * @param string|bool|int $value Value to set.
     * @return bool True when the file ends in the desired state.
     */
    public function setConstant(string $name, $value): bool
    {
        if (!$this->validName($name)) {
            return false;
        }

        $path = $this->configPath();
        if ($path === '') {
            return false;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return false;
        }

        $literal = $this->literal($value);
        $defineLine = sprintf("define('%s', %s); %s", $name, $literal, self::MARKER);

        // Already present and identical? No-op.
        if ($this->hasExactDefine($content, $name, $literal)) {
            return true;
        }

        if (!$this->isWritable()) {
            return false;
        }

        $stripped = $this->stripDefine($content, $name);
        $updated  = $this->insertAfterOpenTag($stripped, $defineLine);

        if ($updated === $content) {
            return true;
        }

        return $this->writeAtomic($path, $updated);
    }

    /**
     * Remove any `define('NAME', ...)` line. Idempotent (absent ⇒ no-op true).
     *
     * @param string $name Constant name.
     * @return bool
     */
    public function removeConstant(string $name): bool
    {
        if (!$this->validName($name)) {
            return false;
        }

        $path = $this->configPath();
        if ($path === '') {
            return true; // nothing to remove
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return true;
        }

        $stripped = $this->stripDefine($content, $name);
        if ($stripped === $content) {
            return true; // not present
        }

        if (!$this->isWritable()) {
            return false;
        }

        return $this->writeAtomic($path, $stripped);
    }

    // -------------------------------------------------------------------------
    // Pure string transforms (unit-testable without disk)
    // -------------------------------------------------------------------------

    /**
     * Insert $line immediately after the FIRST `<?php` opening tag.
     *
     * @param string $content wp-config.php content.
     * @param string $line    The full define line (no trailing newline).
     * @return string
     */
    public function insertAfterOpenTag(string $content, string $line): string
    {
        // Match the first PHP open tag (with or without trailing whitespace).
        if (preg_match('/^(\xEF\xBB\xBF)?<\?php\b.*?$/m', $content, $m, PREG_OFFSET_CAPTURE) === 1) {
            $offset = (int) $m[0][1] + strlen($m[0][0]);
            return substr($content, 0, $offset)
                . "\n" . $line . "\n"
                . substr($content, $offset);
        }
        // No open tag found (unexpected): prepend a complete PHP block.
        return "<?php\n" . $line . "\n?>\n" . $content;
    }

    /**
     * Strip every `define('NAME', ...);` line (and the WPMgr annotation) from the
     * content. Matches single- or double-quoted names, any spacing.
     *
     * @param string $content wp-config.php content.
     * @param string $name    Constant name.
     * @return string
     */
    public function stripDefine(string $content, string $name): string
    {
        $pattern = '/^[ \t]*define\(\s*[\'"]' . preg_quote($name, '/') . '[\'"].*?\);.*$\n?/mi';
        $result  = preg_replace($pattern, '', $content);
        return $result ?? $content;
    }

    /**
     * Whether the content already contains the constant defined to exactly the
     * given literal value.
     *
     * @param string $content wp-config.php content.
     * @param string $name    Constant name.
     * @param string $literal The PHP literal ('true'|'false'|number|quoted).
     * @return bool
     */
    public function hasExactDefine(string $content, string $name, string $literal): bool
    {
        $pattern = '/^[ \t]*define\(\s*[\'"]' . preg_quote($name, '/')
            . '[\'"]\s*,\s*' . preg_quote($literal, '/') . '\s*\)\s*;/mi';
        return preg_match($pattern, $content) === 1;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Atomic write: temp file in the same directory + rename over the target.
     *
     * @param string $path    Destination wp-config.php path.
     * @param string $content New content.
     * @return bool
     */
    private function writeAtomic(string $path, string $content): bool
    {
        $tmp = $path . '.wpmgr-tmp-' . getmypid() . '.php';

        $bytes = @file_put_contents($tmp, $content, LOCK_EX);
        if ($bytes === false) {
            @unlink($tmp);
            return false;
        }

        // Preserve the original file's permissions on the temp file before swap.
        $perms = @fileperms($path);
        if ($perms !== false) {
            @chmod($tmp, $perms & 0o777);
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * Render a PHP literal for the value (bool/int as bare tokens, string as a
     * single-quoted literal).
     *
     * @param string|bool|int $value Value to render.
     * @return string
     */
    private function literal($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value) . "'";
    }

    /**
     * Validate a constant name is a safe PHP identifier.
     *
     * @param string $name Candidate name.
     * @return bool
     */
    private function validName(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }
}
