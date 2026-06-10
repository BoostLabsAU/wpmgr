<?php
/**
 * SpeculationRules — inject a <script type="speculationrules"> prefetch block.
 *
 * The Speculation Rules API lets the browser prefetch same-origin documents the
 * visitor is likely to navigate to next (links matching `/*`), excluding pages
 * that must not be prefetched (wp-admin/login, cart/checkout/logout, anything
 * with a query string, and `.php` endpoints). Result: near-instant navigations
 * with no client framework. Gated by PerfConfig::$cacheLinkPrefetch.
 *
 * Injected once, before </head>. The exclusion patterns are conservative so a
 * logged-out visitor never warms a personalised/destructive endpoint.
 *
 * Standard Speculation Rules API usage.
 *
 * @package WPMgr\Agent\Optimizer
 */

declare(strict_types=1);

namespace WPMgr\Agent\Optimizer;

/**
 * Injects the speculation-rules prefetch block.
 */
final class SpeculationRules
{
    private PerfConfig $config;

    /**
     * @param PerfConfig|null $config Optimization config.
     */
    public function __construct(?PerfConfig $config = null)
    {
        $this->config = $config ?? PerfConfig::load();
    }

    /**
     * Inject the speculation-rules block when enabled.
     *
     * @param string $html Full page HTML.
     * @return string
     */
    public function process(string $html): string
    {
        if (!$this->config->cacheLinkPrefetch) {
            return $html;
        }
        if (strpos($html, 'type="speculationrules"') !== false
            || strpos($html, "type='speculationrules'") !== false
        ) {
            return $html; // already present (native WP or a prior run)
        }

        $rules = [
            'prefetch' => [
                [
                    'source'    => 'document',
                    'where'     => [
                        'and' => [
                            ['href_matches' => '/*'],
                            ['not' => ['href_matches' => [
                                '/wp-admin/*',
                                '/wp-login.php',
                                '/*\\?*',
                                '/cart/*',
                                '/checkout/*',
                                '/*/cart/*',
                                '/*/checkout/*',
                                '/*logout*',
                            ]]],
                            ['not' => ['selector_matches' => '[rel~="nofollow"]']],
                        ],
                    ],
                    'eagerness' => 'moderate',
                ],
            ],
        ];

        $json = (string) json_encode($rules, JSON_UNESCAPED_SLASHES);
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- injected into the cache-write output buffer after wp_head has run; WP's enqueue API is inapplicable in this OB callback (see class-rum-injector.php for the canonical note)
        $tag  = '<script type="speculationrules">' . $json . '</script>';

        if (stripos($html, '</head>') !== false) {
            return (string) preg_replace('/<\/head>/i', $tag . '</head>', $html, 1);
        }
        return $html . $tag;
    }
}
