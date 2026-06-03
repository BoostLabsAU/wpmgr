<?php
/**
 * NginxHelper::snippet() prefix-validation tests.
 *
 * The cache-path prefix is interpolated into a generated nginx config. A prefix
 * containing whitespace, quotes, ';', braces, '$', newlines or '..' could break
 * out of the path or inject directives, so snippet() whitelists the prefix
 * against ^[A-Za-z0-9/_-]+$ and returns '' for anything else. These tests assert
 * the valid default still renders and that hostile prefixes are rejected.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests;

use WPMgr\Agent\Cache\NginxHelper;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr\Agent\Cache\NginxHelper
 */
final class CacheNginxHelperTest extends TestCase
{
    private NginxHelper $helper;

    protected function set_up(): void
    {
        parent::set_up();
        $this->helper = new NginxHelper();
    }

    public function test_default_prefix_renders_snippet(): void
    {
        $out = $this->helper->snippet();
        $this->assertNotSame('', $out);
        $this->assertStringContainsString('/wp-content/cache/wpmgr', $out);
        $this->assertStringContainsString('location /', $out);
    }

    public function test_simple_custom_prefix_is_accepted(): void
    {
        $out = $this->helper->snippet('/custom/cache_dir-1');
        $this->assertNotSame('', $out);
        $this->assertStringContainsString('/custom/cache_dir-1', $out);
    }

    /**
     * @dataProvider hostilePrefixes
     */
    public function test_hostile_prefixes_are_rejected(string $prefix): void
    {
        $this->assertSame('', $this->helper->snippet($prefix), 'unsafe prefix must yield no snippet');
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function hostilePrefixes(): array
    {
        return [
            'semicolon injection'  => ['/cache; add_header X 1'],
            'brace injection'      => ['/cache} location /x {'],
            'dollar var'           => ['/cache$host'],
            'quote'                => ['/cache"/x'],
            'whitespace'           => ['/cache dir'],
            'newline'              => ["/cache\nlocation /x {"],
            'path traversal'       => ['/cache/../../etc'],
            'empty'                => [''],
        ];
    }
}
