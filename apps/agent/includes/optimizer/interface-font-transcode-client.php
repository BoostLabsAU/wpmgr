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
 * Resolves a font source to its WOFF2 transcode result (ready/pending/negative).
 */
interface FontTranscodeClientInterface
{
    /**
     * Request (or check) a WOFF2 transcode for the given source font bytes.
     *
     * Returns an array with keys:
     *   state       : "ready" | "pending" | "negative"
     *   woff2_url   : local asset URL (non-empty only when state=="ready")
     *
     * Returns null on any internal failure; callers must serve the original.
     *
     * @param string $sourceBytes Raw bytes of the original font file.
     * @param string $ext         File extension hint: "ttf" | "otf" | "woff".
     * @return array{state:string,woff2_url:string}|null
     */
    public function resolve(string $sourceBytes, string $ext): ?array;
}
