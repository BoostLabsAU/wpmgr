<?php
/**
 * MarketingParams tests: the ignore list strips tracking params (utm_*, gclid,
 * fbclid, …) and the include list keeps cache-varying params (lang, currency, …).
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use WPMgr\Agent\Cache\MarketingParams;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Cache\MarketingParams
 */
final class MarketingParamsTest extends TestCase
{
    public function test_known_marketing_params_are_ignored(): void
    {
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'gclid', 'fbclid', 'msclkid', 'mc_cid', 'ttclid'] as $p) {
            $this->assertTrue(MarketingParams::isIgnored($p), "$p should be ignored");
        }
    }

    public function test_ignore_is_case_insensitive(): void
    {
        $this->assertTrue(MarketingParams::isIgnored('UTM_SOURCE'));
        $this->assertTrue(MarketingParams::isIgnored('GCLID'));
    }

    public function test_cache_varying_params_are_not_ignored(): void
    {
        foreach (['lang', 'currency', 'orderby', 'max_price', 'min_price', 'rating_filter'] as $p) {
            $this->assertFalse(MarketingParams::isIgnored($p), "$p should NOT be ignored");
            $this->assertTrue(MarketingParams::isIncluded($p), "$p should be in the include list");
        }
    }

    public function test_unknown_param_is_neither(): void
    {
        $this->assertFalse(MarketingParams::isIgnored('add-to-cart'));
        $this->assertFalse(MarketingParams::isIncluded('add-to-cart'));
    }

    public function test_ignore_list_is_substantial(): void
    {
        // Roughly ~70 marketing params per the recon's factual list.
        $this->assertGreaterThanOrEqual(60, count(MarketingParams::ignoreList()));
    }

    public function test_lists_have_no_duplicates(): void
    {
        $ignore = MarketingParams::ignoreList();
        $this->assertSame(count($ignore), count(array_unique($ignore)), 'ignore list must be unique');

        $include = MarketingParams::includeList();
        $this->assertSame(count($include), count(array_unique($include)), 'include list must be unique');

        // The two lists must not overlap.
        $this->assertSame([], array_intersect($ignore, $include));
    }
}
