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
     * Detect whether the active theme uses WooCommerce cart-fragments.
     *
     * Conservative: returns true ONLY when BOTH the script handle is enqueued AND
     * the standard mini-cart selector is present in the supplied page HTML. Any
     * uncertainty (WooCommerce inactive, missing handle, missing selector) returns
     * false — the safe default that keeps the Woo cookies in the hard-bypass set.
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
     * Safe wrapper for use in the PerfReporter context where the full page HTML is
     * not available. Runs a reduced check: WooCommerce active AND wc-cart-fragments
     * is registered (not necessarily enqueued on the current request). Returns
     * false when uncertain.
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
}
