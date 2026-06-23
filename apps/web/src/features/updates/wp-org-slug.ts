/**
 * Derive the WordPress.org directory slug from a plugin file path.
 *
 * The agent reports the plugin file as "slug/slug.php" (e.g.
 * "suremails/suremails.php"). WordPress.org URLs use only the directory
 * part — the segment before the first slash. A bare slug (no slash) is
 * returned as-is so single-file plugins still work.
 *
 * Examples:
 *   "suremails/suremails.php" → "suremails"
 *   "akismet/akismet.php"     → "akismet"
 *   "hello-dolly"             → "hello-dolly"
 */
export function wpOrgSlug(rawSlug: string): string {
  const slash = rawSlug.indexOf("/");
  return slash === -1 ? rawSlug : rawSlug.slice(0, slash);
}
