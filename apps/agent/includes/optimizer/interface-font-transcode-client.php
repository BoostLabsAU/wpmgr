<?php
/**
 * FontTranscodeClientInterface — contract for the WOFF2 transcode resolver.
 *
 * Separating the interface from the concrete implementation allows the
 * Font optimizer to accept a test double without extending the final
 * FontTranscodeClient class.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

/**
 * Resolves a font source to its WOFF2 transcode result (ready/pending/negative/subset/skipped).
 */
interface FontTranscodeClientInterface
{
    /**
     * Request (or check) a WOFF2 transcode for the given source font bytes.
     *
     * Returns an array with keys:
     *   state        : "ready" | "pending" | "negative" | "subset" | "skipped"
     *   woff2_url    : local asset URL (non-empty when state is "ready" or "subset")
     *   subset_url   : local subset asset URL (non-empty only when state == "subset")
     *   unicode_range: CSS unicode-range value (non-empty only when state == "subset")
     *
     * Returns null on any internal failure; callers must serve the original.
     *
     * State semantics:
     *   "ready"   — full WOFF2 ready; no subset produced (or subsetting disabled).
     *   "subset"  — full WOFF2 ready AND a latin-ext subset WOFF2 is available.
     *   "skipped" — the media-encoder skipped subsetting (icon/variable font); full
     *               WOFF2 is still served; subset_url is empty.
     *   "pending" — transcode job in flight; serve the original this build.
     *   "negative"— permanent failure; serve the original.
     *
     * @param string $sourceBytes  Raw bytes of the original font file.
     * @param string $ext          File extension hint: "ttf" | "otf" | "woff".
     * @param string $subsetMode   Subset mode: "" (none) | "range".
     * @param string $subsetRange  Range name: "latin-ext" | "latin" (only when mode=="range").
     * @return array{state:string,woff2_url:string,subset_url:string,unicode_range:string}|null
     */
    public function resolve(
        string $sourceBytes,
        string $ext,
        string $subsetMode = '',
        string $subsetRange = ''
    ): ?array;
}
