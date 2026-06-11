<?php
/**
 * WooFragmentsProbe — detects whether the active theme supports WooCommerce's
 * native cart-fragments mechanism.
 *
 * The check is conservative by design (safe-by-default): it only returns true
 * when two independent signals are confirmed:
 *
 *   1. The `wc-cart-fragments` script handle is registered and enqueued by
 *      WooCommerce on the front end — this confirms WooCommerce itself is active
 *      and has primed the fragment-refresh mechanism.
 *
 *   2. A standard fragment-refreshable mini-cart selector is present in the
 *      rendered page HTML — specifically the `.widget_shopping_cart_content`
 *      wrapper that WooCommerce's `woocommerce_add_to_cart_fragments` filter
 *      targets. Themes that replace this with a wholly custom cart widget that
 *      bypasses fragments correctly return false here, leaving the three Woo
 *      cookies in the hard-bypass set (the pre-feature safe default).
 *
 * Only runs when WooCommerce is active. If anything is uncertain the probe
 * returns false, keeping the full-bypass default.
 *
 * This probe runs REGARDLESS of the woo_cacheable_session flag so the dashboard
 * can surface fragment-support status before the operator enables the feature.
 *
 * Front-end probe (the authoritative path):
 * runFrontEndProbe() is called from the output-buffer handler (CacheWriter::handle)
 * on cacheable front-end renders where WooCommerce actually enqueues its scripts.
 * The result is latched asymmetrically to prevent cache-purge thrash:
 *   - Any single TRUE result latches immediately.
 *   - FALSE latches only after NEGATIVE_LATCH_THRESHOLD consecutive negative
 *     probes on DISTINCT URLs (one product page can be a false negative because
 *     WooCommerce enqueues wc-cart-fragments only on cart/mini-cart pages).
 *
 * Tri-state: the option's absence means "never probed". The option is deleted
 * (reset to unknown) on switch_theme / activated_plugin / deactivated_plugin so
 * that theme/plugin changes trigger a fresh probe cycle.
 *
 * @package WPMgr\Agent\Cache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Cache;

/**
 * Detects whether the active theme supports WooCommerce cart-fragments.
 */
final class WooFragmentsProbe
{
    /**
     * The WordPress script handle WooCommerce registers for its cart-fragments
     * mechanism. Its presence in the enqueued set confirms fragments are active.
     */
    private const WC_FRAGMENTS_HANDLE = 'wc-cart-fragments';

    /**
     * CSS class WooCommerce targets with `woocommerce_add_to_cart_fragments`: the
     * `.widget_shopping_cart_content` element is the standard mini-cart refresh
     * target in the default Storefront theme and any theme that uses the canonical
     * WooCommerce mini-cart widget/block. We look for the CSS class (not the full
     * element) so minor markup variations do not generate false negatives.
     */
    private const MINI_CART_SELECTOR = 'widget_shopping_cart_content';

    /**
     * Maximum number of bytes of the page buffer to scan. Caps memory use on
     * very large pages; the fragment handle and selector are near the top of
     * every well-formed HTML document.
     */
    private const BUFFER_SCAN_LIMIT = 524288; // 512 KiB

    /**
     * Transient/option key for the front-end probe state. Stores a serialised
     * array:
     *   {
     *     "result":   bool|null,   // current latched result (null = never probed)
     *     "negatives": string[],   // distinct URLs that returned false
     *     "last_at":  int          // Unix timestamp of the last probe run
     *   }
     *
     * This option is written with autoload=false and should NOT be cached (it
     * drives a security/correctness decision). It is deleted entirely to signal
     * "never probed".
     */
    public const OPTION_PROBE_STATE = 'wpmgr_woo_probe_state';

    /**
     * How often the front-end probe is allowed to run, in seconds (12 hours).
     * Once a result is latched the throttle extends to 12 h between re-checks
     * so an already-known-positive site does not re-probe on every cache-write.
     */
    public const PROBE_THROTTLE_SECONDS = 43200; // 12 hours

    /**
     * Number of consecutive negative probes on DISTINCT URLs required before
     * the FALSE result is persisted. Guards against the common case where WC
     * only enqueues wc-cart-fragments on cart/mini-cart pages, making a single
     * product page a false negative.
     */
    public const NEGATIVE_LATCH_THRESHOLD = 3;

    /**
     * Detect whether the active theme uses WooCommerce cart-fragments.
     *
     * Conservative: returns true ONLY when BOTH the script handle is enqueued AND
     * the standard mini-cart selector is present in the supplied page HTML. Any
     * uncertainty (WooCommerce inactive, missing handle, missing selector) returns
     * false — the safe default that keeps the Woo cookies in the hard-bypass set.
     *
     * Input is the site's own rendered HTML; still treated as untrusted. The check
     * is two bounded substring lookups with no DOM parsing, no regex, and no eval.
     * The buffer is capped at BUFFER_SCAN_LIMIT bytes before scanning.
     *
     * @param string $pageHtml The fully-rendered page HTML (from the output buffer).
     * @return bool True when cart-fragments support is confidently detected.
     */
    public static function detect(string $pageHtml): bool
    {
        if (!class_exists('WooCommerce')) {
            return false;
        }

        // Guard 1: the wc-cart-fragments script handle must be enqueued. This
        // confirms WooCommerce has registered its fragment-refresh mechanism for
        // this page. wp_script_is() is the correct public API for this check.
        if (!function_exists('wp_script_is')) {
            return false;
        }
        if (!wp_script_is(self::WC_FRAGMENTS_HANDLE, 'enqueued')) {
            return false;
        }

        // Cap the buffer before substring scan to bound memory use on large pages.
        if (strlen($pageHtml) > self::BUFFER_SCAN_LIMIT) {
            $pageHtml = substr($pageHtml, 0, self::BUFFER_SCAN_LIMIT);
        }

        // Guard 2: the standard mini-cart selector must be present in the page
        // HTML. A theme that replaces the WooCommerce mini-cart widget entirely
        // (dropping `.widget_shopping_cart_content`) would leave the fragment
        // mechanism without a target element, so fragments would not actually
        // repaint the cart. We require the class to be present as a substring of
        // the raw HTML — a simple, fast check with no DOM parsing.
        if (strpos($pageHtml, self::MINI_CART_SELECTOR) === false) {
            return false;
        }

        return true;
    }

    /**
     * Run the front-end probe on a real rendered page buffer.
     *
     * Called from the output-buffer handler (CacheWriter::handle) on cacheable
     * front-end requests. Conditions that short-circuit this call (all checked by
     * the caller):
     *   - WooCommerce must be active (class_exists('WooCommerce')).
     *   - The request must be front-end (not admin, not cron, not REST).
     *   - The probe is throttled: it only runs when the option is absent (never
     *     probed) or when PROBE_THROTTLE_SECONDS have elapsed since last_at.
     *
     * Asymmetric latch:
     *   - A single TRUE result persists immediately via persistWooSupported(true).
     *   - FALSE persists only after NEGATIVE_LATCH_THRESHOLD distinct-URL negatives.
     *   - Repeated negatives on the SAME URL do not count toward the threshold.
     *
     * @param string            $buffer The fully-rendered page HTML.
     * @param string            $url    The request URL (used for distinct-URL tracking).
     * @param CacheManager|null $cache  Cache manager for drop-in rewrite + purge on flip.
     * @return void
     */
    public static function runFrontEndProbe(string $buffer, string $url, ?CacheManager $cache = null): void
    {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }

        // Only probe when WooCommerce is active. Probe with the full detect()
        // signal (enqueued + selector) — never loosen to registry-only here.
        if (!class_exists('WooCommerce')) {
            return;
        }

        $state = self::loadProbeState();
        $now   = time();

        // Throttle: skip if we probed recently AND a result is already latched.
        // When the result is still null (never probed), skip the throttle so the
        // very first front-end render always tries to probe.
        if ($state['result'] !== null) {
            $elapsed = $now - $state['last_at'];
            if ($elapsed < self::PROBE_THROTTLE_SECONDS) {
                return;
            }
        }

        $detected = self::detect($buffer);
        $state['last_at'] = $now;

        if ($detected) {
            // Positive: latch immediately regardless of prior state.
            $state['result']    = true;
            $state['negatives'] = [];
            self::saveProbeState($state);
            PerfReporter::persistWooSupported(true, $cache);
            return;
        }

        // Negative: only count this URL once toward the threshold. A repeat of
        // an already-counted URL changes nothing, so skip the option write
        // entirely (a busy unsupported store would otherwise update_option on
        // every cache MISS until the latch fills).
        $urlNorm = (string) substr($url, 0, 500); // bounded key
        if (in_array($urlNorm, $state['negatives'], true)) {
            return;
        }
        $state['negatives'][] = $urlNorm;

        if (count($state['negatives']) >= self::NEGATIVE_LATCH_THRESHOLD) {
            // Enough distinct-URL negatives: latch FALSE.
            $state['result'] = false;
            self::saveProbeState($state);
            PerfReporter::persistWooSupported(false, $cache);
        } else {
            // Not enough negatives yet: save progress but leave result as null.
            self::saveProbeState($state);
        }
    }

    /**
     * Return the current probe tri-state:
     *   null  = never probed (option absent or never written).
     *   true  = probed and confirmed supported.
     *   false = probed and confirmed unsupported (N distinct-URL negatives).
     *
     * @return bool|null
     */
    public static function getStoredResult(): ?bool
    {
        $state = self::loadProbeState();
        return $state['result'];
    }

    /**
     * Delete the probe state option, resetting to "never probed". Called on
     * switch_theme, activated_plugin, and deactivated_plugin so theme/plugin
     * changes trigger a fresh probe cycle and the cached support status does not
     * outlive the active theme.
     *
     * Also deletes OPTION_WOO_FRAGMENTS_SUPPORTED (the binary persisted result
     * read by CacheConfig / the drop-in) so the two options stay in sync.
     *
     * @return void
     */
    public static function resetState(): void
    {
        if (function_exists('delete_option')) {
            delete_option(self::OPTION_PROBE_STATE);
            delete_option(PerfReporter::OPTION_WOO_FRAGMENTS_SUPPORTED);
        }
    }

    /**
     * Safe wrapper for use in genuinely front-end contexts (not admin, not cron,
     * not REST) where wp_script_is('registered') can succeed. Used as an
     * opportunistic TRUE-upgrade: if the handle is registered on the current
     * request the probe can latch positive without waiting for the full HTML
     * buffer (the buffer detect() call still applies the selector guard).
     *
     * Returns false when uncertain. NEVER used as a source of FALSE in persistence
     * decisions (the registry check cannot confirm absence — WC skips registration
     * in non-frontend contexts, so false is structurally untrustworthy there).
     *
     * @return bool
     */
    public static function detectFromScriptRegistry(): bool
    {
        if (!class_exists('WooCommerce')) {
            return false;
        }
        if (!function_exists('wp_script_is')) {
            return false;
        }
        // 'registered' is weaker than 'enqueued' but is the correct check outside
        // of a real front-end request (e.g. in a REST handler or cron context).
        if (!wp_script_is(self::WC_FRAGMENTS_HANDLE, 'registered')) {
            return false;
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Load the probe state from the wp-option. Returns a well-typed array with
     * defaults when the option is absent or malformed.
     *
     * @return array{result:bool|null,negatives:string[],last_at:int}
     */
    private static function loadProbeState(): array
    {
        $default = ['result' => null, 'negatives' => [], 'last_at' => 0];
        if (!function_exists('get_option')) {
            return $default;
        }
        $raw = get_option(self::OPTION_PROBE_STATE, null);
        if (!is_array($raw)) {
            return $default;
        }
        // Preserve the tri-state: null means never probed, true/false are results.
        $result = null;
        if (array_key_exists('result', $raw) && $raw['result'] !== null) {
            $result = (bool) $raw['result'];
        }
        return [
            'result'    => $result,
            'negatives' => is_array($raw['negatives'] ?? null) ? array_values(array_filter($raw['negatives'], 'is_string')) : [],
            'last_at'   => isset($raw['last_at']) ? (int) $raw['last_at'] : 0,
        ];
    }

    /**
     * Save the probe state to the wp-option (autoload off).
     *
     * @param array{result:bool|null,negatives:string[],last_at:int} $state
     * @return void
     */
    private static function saveProbeState(array $state): void
    {
        if (!function_exists('update_option')) {
            return;
        }
        update_option(self::OPTION_PROBE_STATE, $state, false);
    }
}
