<?php
/**
 * TagHelper unit tests: attribute read/write/remove/rename on a single tag, the
 * boolean-attribute form, and the exclusion matcher.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use WPMgr\Agent\Optimizer\TagHelper;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Optimizer\TagHelper
 */
final class OptimizerTagHelperTest extends TestCase
{
    public function test_reads_value_and_boolean_attrs(): void
    {
        $tag = '<script defer src="a.js" type="module"></script>';
        $this->assertSame('a.js', TagHelper::attr($tag, 'src'));
        $this->assertSame('module', TagHelper::attr($tag, 'type'));
        $this->assertSame('', TagHelper::attr($tag, 'defer'), 'boolean attr returns empty string');
        $this->assertNull(TagHelper::attr($tag, 'missing'));
        $this->assertTrue(TagHelper::hasAttr($tag, 'defer'));
        $this->assertFalse(TagHelper::hasAttr($tag, 'async'));
    }

    public function test_set_inserts_into_opening_tag_only(): void
    {
        $out = TagHelper::setAttr('<script src="a.js"></script>', 'defer', '');
        $this->assertSame('<script src="a.js" defer></script>', $out);
        $this->assertStringNotContainsString('</script defer', $out);
    }

    public function test_set_replaces_existing_value(): void
    {
        $out = TagHelper::setAttr('<link href="a.css" rel="stylesheet">', 'href', 'b.css');
        $this->assertSame('<link href="b.css" rel="stylesheet">', $out);
    }

    public function test_set_handles_self_closing(): void
    {
        $out = TagHelper::setAttr('<img src="a.jpg" />', 'loading', 'lazy');
        $this->assertSame('<img src="a.jpg" loading="lazy" />', $out);
    }

    public function test_rename_keeps_value(): void
    {
        $out = TagHelper::renameAttr('<script src="a.js"></script>', 'src', 'data-wpmgr-src');
        $this->assertStringContainsString('data-wpmgr-src="a.js"', $out);
        $this->assertNull(TagHelper::attr($out, 'src'));
    }

    public function test_remove_attr(): void
    {
        $out = TagHelper::removeAttr('<img src="a.jpg" alt="x">', 'alt');
        $this->assertSame('<img src="a.jpg">', $out);
    }

    public function test_matches_any_is_case_insensitive(): void
    {
        $this->assertTrue(TagHelper::matchesAny(['analytics'], '<script src="ANALYTICS.js"></script>'));
        $this->assertFalse(TagHelper::matchesAny(['analytics'], '<script src="app.js"></script>'));
        $this->assertFalse(TagHelper::matchesAny([], '<script>'));
    }
}
