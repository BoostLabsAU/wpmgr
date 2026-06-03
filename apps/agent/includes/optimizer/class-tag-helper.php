<?php
/**
 * TagHelper — stateless regex helpers for reading/writing attributes on a SINGLE
 * HTML tag string.
 *
 * The optimizer edits a fully-rendered page by locating individual tags with
 * preg_match_all, transforming each tag string, and str_replace-ing it back.
 * This helper is the workhorse for those per-tag edits: get an attribute, set/
 * replace one, remove one, or rename one — all on the FIRST element in the
 * supplied tag string. It never parses the whole document (that is the caller's
 * job), so each call is O(tag length).
 *
 * Quote-aware and idempotent: setAttr replaces an existing attribute in place
 * or inserts it before the closing `>`/`/>`. Original implementation.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

/**
 * Single-tag attribute read/write helpers.
 */
final class TagHelper
{
    /**
     * Read an attribute value from the first tag in $tag.
     *
     * @param string $tag  Tag string (e.g. '<img src="a.jpg" loading="lazy">').
     * @param string $attr Attribute name.
     * @return string|null Value, true-string for valueless boolean attrs, null if absent.
     */
    public static function attr(string $tag, string $attr): ?string
    {
        $a = preg_quote($attr, '/');
        // value form: attr="..." or attr='...'
        if (preg_match('/\s' . $a . '\s*=\s*(["\'])(.*?)\1/is', $tag, $m)) {
            return $m[2];
        }
        // boolean form: bare attribute
        if (preg_match('/\s' . $a . '(?=[\s\/>])/is', $tag)) {
            return '';
        }
        return null;
    }

    /**
     * Whether an attribute is present (value or boolean).
     *
     * @param string $tag  Tag string.
     * @param string $attr Attribute name.
     * @return bool
     */
    public static function hasAttr(string $tag, string $attr): bool
    {
        return self::attr($tag, $attr) !== null;
    }

    /**
     * Set (or replace) an attribute on the first tag. Inserts before the closing
     * delimiter when absent; replaces in place when present.
     *
     * @param string $tag   Tag string.
     * @param string $attr  Attribute name.
     * @param string $value Attribute value (HTML-escaped for the double-quoted form).
     * @return string New tag string.
     */
    public static function setAttr(string $tag, string $attr, string $value): string
    {
        $a       = preg_quote($attr, '/');
        $escaped = str_replace('"', '&quot;', $value);
        // Empty value => boolean attribute (no ="..."); otherwise a quoted value.
        $replace = $value === '' ? ' ' . $attr : ' ' . $attr . '="' . $escaped . '"';

        if (self::hasAttr($tag, $attr)) {
            // Replace value form first; fall back to boolean form.
            $valuePattern = '/\s' . $a . '\s*=\s*(["\']).*?\1/is';
            if (preg_match($valuePattern, $tag)) {
                return (string) preg_replace($valuePattern, $replace, $tag, 1);
            }
            $boolPattern = '/\s' . $a . '(?=[\s\/>])/is';
            return (string) preg_replace($boolPattern, $replace, $tag, 1);
        }

        // Insert before the closing delimiter of the FIRST (opening) tag only,
        // never the closing tag. Match the first tag-end in the string.
        return (string) preg_replace('/(\s*\/?>)/', $replace . '$1', $tag, 1);
    }

    /**
     * Remove an attribute from the first tag.
     *
     * @param string $tag  Tag string.
     * @param string $attr Attribute name.
     * @return string New tag string.
     */
    public static function removeAttr(string $tag, string $attr): string
    {
        $a = preg_quote($attr, '/');
        $tag = (string) preg_replace('/\s' . $a . '\s*=\s*(["\']).*?\1/is', '', $tag, 1);
        return (string) preg_replace('/\s' . $a . '(?=[\s\/>])/is', '', $tag, 1);
    }

    /**
     * Rename an attribute (keeps its value), e.g. src -> data-src.
     *
     * @param string $tag  Tag string.
     * @param string $from Existing attribute.
     * @param string $to   New attribute name.
     * @return string New tag string.
     */
    public static function renameAttr(string $tag, string $from, string $to): string
    {
        $value = self::attr($tag, $from);
        if ($value === null) {
            return $tag;
        }
        $tag = self::removeAttr($tag, $from);
        return self::setAttr($tag, $to, $value);
    }

    /**
     * Test whether any of the keyword substrings appears in $haystack
     * (case-insensitive). Used for exclusion lists.
     *
     * @param list<string> $keywords Substrings.
     * @param string       $haystack Tag/URL to test.
     * @return bool
     */
    public static function matchesAny(array $keywords, string $haystack): bool
    {
        foreach ($keywords as $kw) {
            if ($kw !== '' && stripos($haystack, $kw) !== false) {
                return true;
            }
        }
        return false;
    }
}
