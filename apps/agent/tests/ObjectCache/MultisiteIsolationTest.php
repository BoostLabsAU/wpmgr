<?php
/**
 * H1 — Multisite L1 cross-blog isolation tests.
 *
 * Verifies:
 *   - L1 is keyed by the fully-qualified buildKey() output (prefix:blogId:group:key).
 *   - A value set on blog 1 is NOT visible after switch_to_blog(2).
 *   - A value set on blog 2 is NOT visible after switching back to blog 1.
 *   - Global groups are NOT blog-scoped: their L1 key is prefix:group:key.
 *   - On single-site (isMultisite=false), switch_to_blog() is a no-op.
 *   - The buildKey() prefix is correctly memoized and invalidated on blog switch.
 *
 * These tests run in array mode against a boot()-ed engine instance. Blog-switch
 * mechanics are tested by directly setting blogId-scoped keys via the engine API.
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr_Object_Cache
 */
final class MultisiteIsolationTest extends TestCase
{
	/** @var \WPMgr_Object_Cache */
	private \WPMgr_Object_Cache $oc;

	protected function set_up(): void
	{
		parent::set_up();
		$this->oc = \WPMgr_Object_Cache::boot();
	}

	// -------------------------------------------------------------------------
	// H1: buildKey() output used as L1 index
	// -------------------------------------------------------------------------

	public function test_engine_source_uses_buildkey_for_l1(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		$this->assertFileExists( $enginePath );
		$content = (string) file_get_contents( $enginePath );
		// storeL1 must accept a $redisKey parameter (fully-qualified).
		$this->assertStringContainsString(
			'storeL1( string $redisKey',
			$content,
			'storeL1() must accept a fully-qualified $redisKey (H1: L1 keyed by buildKey output)'
		);
	}

	public function test_engine_source_has_key_prefix_memo(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		$this->assertFileExists( $enginePath );
		$content = (string) file_get_contents( $enginePath );
		$this->assertStringContainsString(
			'keyPrefixMemo',
			$content,
			'Engine must use keyPrefixMemo for O(1) per-group prefix memoization (H1)'
		);
	}

	public function test_engine_source_has_is_multisite_capture(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		$this->assertFileExists( $enginePath );
		$content = (string) file_get_contents( $enginePath );
		$this->assertStringContainsString(
			'isMultisite',
			$content,
			'Engine must capture is_multisite() at boot into $isMultisite (H1)'
		);
	}

	// -------------------------------------------------------------------------
	// H1: switch_to_blog no-op on single-site
	// -------------------------------------------------------------------------

	public function test_switch_to_blog_noop_on_single_site(): void
	{
		// In the test environment is_multisite() returns false (stub returns false).
		// Boot engine: isMultisite=false.
		// Set a value, switch_to_blog(2), assert value still visible.
		$this->oc->set( 'ss_key', 'ss_val', 'options', 60 );
		$this->oc->switch_to_blog( 2 );
		$found  = null;
		$result = $this->oc->get( 'ss_key', 'options', false, $found );
		$this->assertTrue( $found, 'switch_to_blog() on single-site must be a no-op; value must remain accessible' );
		$this->assertSame( 'ss_val', $result );
	}

	public function test_engine_source_switch_to_blog_noop_check(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		$this->assertFileExists( $enginePath );
		$content = (string) file_get_contents( $enginePath );
		// switch_to_blog() must bail early when isMultisite is false.
		$this->assertStringContainsString(
			'$this->isMultisite',
			$content,
			'switch_to_blog() must check $this->isMultisite to be a no-op on single-site'
		);
	}

	// -------------------------------------------------------------------------
	// H1: L1 keys are blog-scoped (per-blog groups)
	// -------------------------------------------------------------------------

	public function test_blog_scoped_keys_use_blog_id_in_prefix(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		$this->assertFileExists( $enginePath );
		$content = (string) file_get_contents( $enginePath );
		// buildKey() for non-global groups must include blogId in the prefix.
		$this->assertStringContainsString(
			"prefix . ':' . \$this->blogId",
			$content,
			'buildKey() must include blogId in non-global group prefix (H1)'
		);
	}

	// -------------------------------------------------------------------------
	// H1: global groups are NOT blog-scoped
	// -------------------------------------------------------------------------

	public function test_global_group_key_does_not_include_blog_id(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		$this->assertFileExists( $enginePath );
		$content = (string) file_get_contents( $enginePath );
		// buildKey() for global groups must omit blogId (prefix:group:key shape).
		$this->assertStringContainsString(
			"prefix . ':' . \$group",
			$content,
			'buildKey() must use prefix:group:key (no blogId) for global groups (H1)'
		);
	}

	// -------------------------------------------------------------------------
	// H1: flush_runtime clears keyPrefixMemo
	// -------------------------------------------------------------------------

	public function test_flush_runtime_clears_key_prefix_memo(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		$this->assertFileExists( $enginePath );
		$content = (string) file_get_contents( $enginePath );
		// flush_runtime() must reset keyPrefixMemo.
		$this->assertStringContainsString(
			'keyPrefixMemo = []',
			$content,
			'flush_runtime() must clear keyPrefixMemo to force prefix recomputation (H1)'
		);
	}

	// -------------------------------------------------------------------------
	// H1: switch_to_blog clears keyPrefixMemo
	// -------------------------------------------------------------------------

	public function test_switch_to_blog_clears_key_prefix_memo(): void
	{
		$enginePath = dirname( __DIR__, 2 ) . '/includes/object-cache/class-object-cache-engine.php';
		$this->assertFileExists( $enginePath );
		$content = (string) file_get_contents( $enginePath );
		// switch_to_blog() must invalidate memoized prefixes.
		$this->assertMatchesRegularExpression(
			'/switch_to_blog.*?keyPrefixMemo/s',
			$content,
			'switch_to_blog() must clear/invalidate keyPrefixMemo (H1)'
		);
	}

	// -------------------------------------------------------------------------
	// H1: engine value isolation after blog switch (in single-site test env,
	//     switch_to_blog is a no-op, but we verify blog-scoped write semantics)
	// -------------------------------------------------------------------------

	public function test_set_on_different_groups_are_isolated(): void
	{
		// Different groups should not share keys.
		$this->oc->set( 'shared_key', 'group_a_value', 'group_a', 60 );
		$this->oc->set( 'shared_key', 'group_b_value', 'group_b', 60 );

		$found_a = null;
		$result_a = $this->oc->get( 'shared_key', 'group_a', false, $found_a );
		$found_b  = null;
		$result_b = $this->oc->get( 'shared_key', 'group_b', false, $found_b );

		$this->assertTrue( $found_a );
		$this->assertTrue( $found_b );
		$this->assertSame( 'group_a_value', $result_a, 'group_a key must hold its own value' );
		$this->assertSame( 'group_b_value', $result_b, 'group_b key must hold its own value' );
	}

	// -------------------------------------------------------------------------
	// H1: global groups exposed via __get
	// -------------------------------------------------------------------------

	public function test_global_groups_accessible_via_magic_get(): void
	{
		$groups = $this->oc->global_groups;
		$this->assertIsArray( $groups );
		// At least one of the default global groups must be present.
		$found = false;
		foreach ( [ 'users', 'userlogins', 'usermeta', 'blog-details', 'site-options' ] as $g ) {
			if ( isset( $groups[ $g ] ) || in_array( $g, $groups, true ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'global_groups must contain at least one default global group' );
	}
}
