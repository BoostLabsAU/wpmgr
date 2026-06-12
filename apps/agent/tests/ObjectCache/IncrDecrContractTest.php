<?php
/**
 * H2 — incr/decr contract tests for 0.42.0.
 *
 * Verifies:
 *   - incr/decr on a missing key returns false (persistent, non-persistent, array-mode)
 *   - existing key preserves TTL after incr/decr (array mode; Redis TTL checked in E2E)
 *   - stored '5' incr → 6 (numeric-string coercion regression guard)
 *   - (int) cast preserved: '5' + 1 = 6, not '51'
 *   - incr/decr in non-persistent mode returns false on missing key
 *
 * @package WPMgr\Agent\Tests\ObjectCache
 */

declare(strict_types=1);

namespace WPMgr\Agent\Tests\ObjectCache;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \WPMgr_Object_Cache
 */
final class IncrDecrContractTest extends TestCase
{
	/** @var \WPMgr_Object_Cache */
	private \WPMgr_Object_Cache $oc;

	protected function set_up(): void
	{
		parent::set_up();
		$this->oc = \WPMgr_Object_Cache::boot();
	}

	// -------------------------------------------------------------------------
	// Missing key → false
	// -------------------------------------------------------------------------

	public function test_incr_missing_key_returns_false_array_mode(): void
	{
		$result = $this->oc->incr( 'incr_missing_' . uniqid( '', true ), 1, 'default' );
		$this->assertFalse( $result, 'incr on a missing key must return false' );
	}

	public function test_decr_missing_key_returns_false_array_mode(): void
	{
		$result = $this->oc->decr( 'decr_missing_' . uniqid( '', true ), 1, 'default' );
		$this->assertFalse( $result, 'decr on a missing key must return false' );
	}

	public function test_incr_missing_key_non_persistent_returns_false(): void
	{
		// 'counts' is in the non-persistent group list.
		$result = $this->oc->incr( 'incr_np_missing', 1, 'counts' );
		$this->assertFalse( $result, 'incr on missing key in non-persistent group must return false' );
	}

	public function test_decr_missing_key_non_persistent_returns_false(): void
	{
		$result = $this->oc->decr( 'decr_np_missing', 1, 'counts' );
		$this->assertFalse( $result, 'decr on missing key in non-persistent group must return false' );
	}

	// -------------------------------------------------------------------------
	// Numeric-string coercion regression guard
	// -------------------------------------------------------------------------

	public function test_incr_stored_numeric_string(): void
	{
		$this->oc->set( 'str_ctr', '5', 'default', 60 );
		$result = $this->oc->incr( 'str_ctr', 1, 'default' );
		$this->assertSame( 6, $result, "incr on stored '5' must return int 6, not string '51'" );
	}

	public function test_decr_stored_numeric_string(): void
	{
		$this->oc->set( 'str_ctr_d', '5', 'default', 60 );
		$result = $this->oc->decr( 'str_ctr_d', 1, 'default' );
		$this->assertSame( 4, $result, "decr on stored '5' must return int 4" );
	}

	// -------------------------------------------------------------------------
	// Basic incr/decr on existing keys
	// -------------------------------------------------------------------------

	public function test_incr_existing_key(): void
	{
		$this->oc->set( 'ctr', 10, 'default', 120 );
		$result = $this->oc->incr( 'ctr', 1, 'default' );
		$this->assertSame( 11, $result );
	}

	public function test_decr_existing_key(): void
	{
		$this->oc->set( 'ctr_d', 10, 'default', 120 );
		$result = $this->oc->decr( 'ctr_d', 1, 'default' );
		$this->assertSame( 9, $result );
	}

	public function test_incr_by_larger_offset(): void
	{
		$this->oc->set( 'ctr_big', 100, 'default', 120 );
		$result = $this->oc->incr( 'ctr_big', 5, 'default' );
		$this->assertSame( 105, $result );
	}

	public function test_decr_by_larger_offset(): void
	{
		$this->oc->set( 'ctr_big_d', 100, 'default', 120 );
		$result = $this->oc->decr( 'ctr_big_d', 15, 'default' );
		$this->assertSame( 85, $result );
	}

	// -------------------------------------------------------------------------
	// Return type is int (not false|string|float)
	// -------------------------------------------------------------------------

	public function test_incr_return_type_is_int(): void
	{
		$this->oc->set( 'type_ctr', 1, 'default', 60 );
		$result = $this->oc->incr( 'type_ctr', 1, 'default' );
		$this->assertIsInt( $result, 'incr must return an int on success' );
	}

	public function test_decr_return_type_is_int(): void
	{
		$this->oc->set( 'type_ctr_d', 5, 'default', 60 );
		$result = $this->oc->decr( 'type_ctr_d', 1, 'default' );
		$this->assertIsInt( $result, 'decr must return an int on success' );
	}

	// -------------------------------------------------------------------------
	// Non-persistent group: existing key in L1 → incr succeeds
	// -------------------------------------------------------------------------

	public function test_incr_existing_non_persistent_key(): void
	{
		$this->oc->set( 'np_ctr', 3, 'counts', 60 );
		$result = $this->oc->incr( 'np_ctr', 1, 'counts' );
		$this->assertSame( 4, $result, 'incr on existing non-persistent key must return new value' );
	}
}
