<?php
/**
 * WPMgr Object Cache engine — implements the full WordPress wp_cache_* API
 * backed by phpredis with graceful degradation to a pure in-memory array cache.
 *
 * This file is included from the object-cache.php drop-in stub. It:
 *   1. Loads the supporting classes (autoloader may not be available this early).
 *   2. Builds the config from the 0600 config file.
 *   3. Attempts to connect; on any boot Throwable, falls back to a pure-array
 *      cache so the site never errors.
 *   4. Instantiates the global $wp_object_cache and registers the shutdown hook.
 *
 * @package WPMgr\Agent\ObjectCache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Load supporting classes. Neither the Composer autoloader nor any plugin
// constant exists at drop-in load time (wp-settings.php includes
// object-cache.php before plugins), so resolve the siblings from this file's
// own directory: they always live next to the engine.
// ---------------------------------------------------------------------------

foreach (
	[
		'class-object-cache-config.php',
		'class-redis-connection.php',
	] as $wpmgr_oc_dep
) {
	$wpmgr_oc_dep_path = __DIR__ . '/' . $wpmgr_oc_dep;
	if ( @is_file( $wpmgr_oc_dep_path ) ) {
		require_once $wpmgr_oc_dep_path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.NotAbsolutePath -- __DIR__-anchored sibling, always absolute
	}
}

unset( $wpmgr_oc_dep, $wpmgr_oc_dep_path );

// ---------------------------------------------------------------------------
// Boot the cache: try Redis, fall back to pure array on any Throwable.
// ---------------------------------------------------------------------------

/**
 * Returns the global WP Object Cache instance, booting it if necessary.
 *
 * @return \WPMgr_Object_Cache
 */
function wpmgr_get_object_cache(): \WPMgr_Object_Cache
{
	global $wp_object_cache;
	if ( ! ( $wp_object_cache instanceof \WPMgr_Object_Cache ) ) {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- object-cache drop-ins MUST assign $wp_object_cache; this is the required WP pattern
		$wp_object_cache = \WPMgr_Object_Cache::boot();
	}
	return $wp_object_cache;
}

// Boot now and install the shutdown hook for stats persist + close.
global $wp_object_cache;
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- object-cache drop-ins MUST assign $wp_object_cache; this is the required WP pattern
$wp_object_cache = \WPMgr_Object_Cache::boot();

register_shutdown_function(
	static function (): void {
		global $wp_object_cache;
		if ( $wp_object_cache instanceof \WPMgr_Object_Cache ) {
			$wp_object_cache->shutdown();
		}
	}
);

// ---------------------------------------------------------------------------
// WordPress wp_cache_* function bridge.
// WordPress defines these functions in wp-includes/cache.php ONLY when an
// object-cache drop-in is NOT present. Since we ARE the drop-in we must
// define them all here. All names are mandated by the WordPress cache API;
// they cannot carry a plugin prefix — PrefixAllGlobals is disabled for
// this bridge section only.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- required WordPress object-cache drop-in API; function names are not ours to choose
// ---------------------------------------------------------------------------

/**
 * Adds data to the cache if the key doesn't already exist.
 *
 * @param int|string $key    Cache key.
 * @param mixed      $data   Data to store.
 * @param mixed      $group  Cache group (any scalar; cast to string internally).
 * @param mixed      $expire TTL in seconds (any numeric; cast to int internally).
 * @return bool
 */
function wp_cache_add( $key, $data, $group = '', $expire = 0 ): bool
{
	try {
		return wpmgr_get_object_cache()->add( $key, $data, $group, $expire );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
		return false;
	}
}

/**
 * Adds multiple cache entries.
 *
 * @param array<int|string,mixed> $data   Map of key => value.
 * @param mixed                   $group  Cache group (any scalar; cast to string internally).
 * @param mixed                   $expire TTL in seconds (any numeric; cast to int internally).
 * @return array<int|string,bool>
 */
function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ): array
{
	try {
		return wpmgr_get_object_cache()->add_multiple( $data, $group, $expire );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
		return array_fill_keys( array_keys( $data ), false );
	}
}

/**
 * Replaces the cached data only when it already exists.
 *
 * @param int|string $key    Cache key.
 * @param mixed      $data   New data.
 * @param mixed      $group  Cache group (any scalar; cast to string internally).
 * @param mixed      $expire TTL in seconds (any numeric; cast to int internally).
 * @return bool
 */
function wp_cache_replace( $key, $data, $group = '', $expire = 0 ): bool
{
	try {
		return wpmgr_get_object_cache()->replace( $key, $data, $group, $expire );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
		return false;
	}
}

/**
 * Saves data to the cache.
 *
 * WP core signature: wp_cache_set( $key, $data, $group = '', $expire = 0 ).
 * The $group parameter is the THIRD argument; $expire is FOURTH.
 * Callers that pass an int as $group (e.g. wp_cache_set($k, $v, 3600)) are
 * treated by WP core as setting group='3600' with expire=0. We match that
 * semantic via scalar normalization in the engine.
 *
 * @param int|string $key    Cache key.
 * @param mixed      $data   Data to store.
 * @param mixed      $group  Cache group (any scalar; cast to string internally).
 * @param mixed      $expire TTL in seconds (any numeric; cast to int internally).
 * @return bool
 */
function wp_cache_set( $key, $data, $group = '', $expire = 0 ): bool
{
	try {
		return wpmgr_get_object_cache()->set( $key, $data, $group, $expire );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
		return false;
	}
}

/**
 * Sets multiple cache entries.
 *
 * @param array<int|string,mixed> $data   Map of key => value.
 * @param mixed                   $group  Cache group (any scalar; cast to string internally).
 * @param mixed                   $expire TTL in seconds (any numeric; cast to int internally).
 * @return array<int|string,bool>
 */
function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ): array
{
	try {
		return wpmgr_get_object_cache()->set_multiple( $data, $group, $expire );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
		return array_fill_keys( array_keys( $data ), false );
	}
}

/**
 * Retrieves cached data.
 *
 * @param int|string $key   Cache key.
 * @param mixed      $group Cache group (any scalar; cast to string internally).
 * @param bool       $force Force a fresh fetch from the backend.
 * @param bool|null  $found Output: whether the key was found.
 * @return mixed False when not found.
 */
function wp_cache_get( $key, $group = '', $force = false, &$found = null )
{
	try {
		return wpmgr_get_object_cache()->get( $key, $group, $force, $found );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
		$found = false;
		return false;
	}
}

/**
 * Retrieves multiple cached values.
 *
 * @param array<int|string>  $keys  Cache keys.
 * @param mixed              $group Cache group (any scalar; cast to string internally).
 * @param bool               $force Force fetch.
 * @return array<int|string,mixed>
 */
function wp_cache_get_multiple( $keys, $group = '', $force = false ): array
{
	try {
		return wpmgr_get_object_cache()->get_multiple( $keys, $group, $force );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
		return array_fill_keys( (array) $keys, false );
	}
}

/**
 * Deletes cached data.
 *
 * @param int|string $key   Cache key.
 * @param mixed      $group Cache group (any scalar; cast to string internally).
 * @return bool
 */
function wp_cache_delete( $key, $group = '' ): bool
{
	try {
		return wpmgr_get_object_cache()->delete( $key, $group );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
		return false;
	}
}

/**
 * Deletes multiple cached entries.
 *
 * @param array<int|string> $keys  Cache keys.
 * @param mixed             $group Cache group (any scalar; cast to string internally).
 * @return array<int|string,bool>
 */
function wp_cache_delete_multiple( array $keys, $group = '' ): array
{
	try {
		return wpmgr_get_object_cache()->delete_multiple( $keys, $group );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
		return array_fill_keys( $keys, false );
	}
}

/**
 * Increments a numeric cache item.
 *
 * @param int|string $key    Cache key.
 * @param int        $offset Amount to increment.
 * @param mixed      $group  Cache group (any scalar; cast to string internally).
 * @return int|false New value or false on failure.
 */
function wp_cache_incr( $key, $offset = 1, $group = '' )
{
	try {
		return wpmgr_get_object_cache()->incr( $key, $offset, $group );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
		return false;
	}
}

/**
 * Decrements a numeric cache item.
 *
 * @param int|string $key    Cache key.
 * @param int        $offset Amount to decrement.
 * @param mixed      $group  Cache group (any scalar; cast to string internally).
 * @return int|false New value or false on failure.
 */
function wp_cache_decr( $key, $offset = 1, $group = '' )
{
	try {
		return wpmgr_get_object_cache()->decr( $key, $offset, $group );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
		return false;
	}
}

/**
 * Flushes the entire object cache.
 *
 * @return bool
 */
function wp_cache_flush(): bool
{
	try {
		return wpmgr_get_object_cache()->flush();
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
		return false;
	}
}

/**
 * Flushes only the in-memory runtime cache (not the persistent backend).
 *
 * @return bool
 */
function wp_cache_flush_runtime(): bool
{
	try {
		return wpmgr_get_object_cache()->flush_runtime();
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
		return false;
	}
}

/**
 * Flushes all entries in a specific cache group.
 *
 * @param mixed $group Cache group (any scalar; cast to string internally).
 * @return bool
 */
function wp_cache_flush_group( $group ): bool
{
	try {
		return wpmgr_get_object_cache()->flush_group( $group );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
		return false;
	}
}

/**
 * Initialises the cache. Called by WordPress on init.
 *
 * @return void
 */
function wp_cache_init(): void
{
	try {
		wpmgr_get_object_cache()->init();
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
	}
}

/**
 * Closes the cache connection. Called at shutdown.
 *
 * @return bool
 */
function wp_cache_close(): bool
{
	try {
		return wpmgr_get_object_cache()->close();
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
		return false;
	}
}

/**
 * Switches the blog context in multisite.
 *
 * @param int $blog_id Blog ID to switch to.
 * @return void
 */
function wp_cache_switch_to_blog( $blog_id ): void
{
	try {
		wpmgr_get_object_cache()->switch_to_blog( (int) $blog_id );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
	}
}

/**
 * Adds a list of groups that should share a global namespace.
 *
 * @param array<string>|string $groups Groups to add.
 * @return void
 */
function wp_cache_add_global_groups( $groups ): void
{
	try {
		wpmgr_get_object_cache()->add_global_groups( (array) $groups );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
	}
}

/**
 * Adds a list of groups that should not be backed by the persistent cache.
 *
 * @param array<string>|string $groups Groups to add.
 * @return void
 */
function wp_cache_add_non_persistent_groups( $groups ): void
{
	try {
		wpmgr_get_object_cache()->add_non_persistent_groups( (array) $groups );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
	}
}

/**
 * Registers groups that should not be prefetched (v2 stub).
 *
 * @param array<string>|string $groups Groups to register.
 * @return void
 */
function wp_cache_add_non_prefetchable_groups( $groups ): void
{
	try {
		wpmgr_get_object_cache()->add_non_prefetchable_groups( (array) $groups );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
	}
}

/**
 * Reports whether a specific feature is supported.
 *
 * @param string $feature Feature name.
 * @return bool
 */
function wp_cache_supports( $feature ): bool
{
	try {
		return wpmgr_get_object_cache()->supports( (string) $feature );
	} catch ( \Throwable $e ) {
		wpmgr_get_object_cache()->wpmgr_journal_wrapper_error( get_class( $e ) );
		return false;
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

// ---------------------------------------------------------------------------
// WPMgr_Object_Cache class definition.
// This class is in the global namespace as WordPress expects it.
// ---------------------------------------------------------------------------

/**
 * WPMgr persistent object cache backed by phpredis.
 *
 * Implements the full WordPress wp_cache_* API with:
 *   - L1 per-request runtime array cache (clone-on-store/read).
 *   - Group semantics: global groups, non-persistent groups, wildcard matching.
 *   - Key shape: prefix:[blogId:]group:key.
 *   - Graceful degradation: boot failure or runtime errors => array-only mode.
 *   - maxttl ceiling on every write (D6, default 7d).
 *   - NX/XX conditional writes, KEEPTTL incr/decr with old-server fallback.
 *   - MGET + pipelines for multi ops.
 *   - UNLINK for async deletes when configured.
 *   - Flush strategies: FLUSHDB (dedicated) or SCAN+MATCH+UNLINK (shared).
 *   - Metadata integrity: JSON metadata key, flush on risky-option change.
 *   - Per-request error journal for CP diagnostics.
 *   - In-process stats counters for heartbeat block.
 */
class WPMgr_Object_Cache
{
	// -------------------------------------------------------------------------
	// Engine version — visible on the heartbeat wire so operators can confirm
	// which code is actually executing after an agent update.
	// -------------------------------------------------------------------------

	/** Version of this engine class. Included in every heartbeat block. */
	public const ENGINE_VERSION = '0.41.6';

	// -------------------------------------------------------------------------
	// Feature advertisement (wp_cache_supports).
	// -------------------------------------------------------------------------

	/** @var array<string> Supported features. */
	private const SUPPORTS = [
		'add_multiple',
		'set_multiple',
		'get_multiple',
		'delete_multiple',
		'flush_runtime',
		'flush_group',
	];

	// -------------------------------------------------------------------------
	// Global group and non-persistent group defaults.
	// -------------------------------------------------------------------------

	/** @var array<string> Groups that share a global (site-agnostic) namespace. */
	private const DEFAULT_GLOBAL_GROUPS = [
		'blog-details',
		'blog-id-cache',
		'blog-lookup',
		'global-posts',
		'networks',
		'rss',
		'site-details',
		'site-lookup',
		'site-options',
		'site-transient',
		'users',
		'useremail',
		'userlogins',
		'usermeta',
		'user_meta',
		'userslugs',
	];

	/** @var array<string> Groups whose values are never stored in Redis. */
	private const DEFAULT_NON_PERSISTENT = [
		'comment',
		'counts',
		'plugins',
		'themes',
	];

	// -------------------------------------------------------------------------
	// Instance state.
	// -------------------------------------------------------------------------

	/** @var \WPMgr\Agent\ObjectCache\RedisConnection|null Redis connection (null in array-only mode). */
	private ?\WPMgr\Agent\ObjectCache\RedisConnection $connection = null;

	/** @var \Redis|null Active phpredis handle (null in array-only mode). */
	private ?\Redis $redis = null;

	/** @var bool True when boot failed and we are running as a pure-array cache. */
	private bool $arrayMode = false;

	/** @var bool True when a reconnect this request has already been attempted. */
	private bool $reconnectAttempted = false;

	/** @var array<string,array<string,mixed>> L1 runtime cache: group => key => value. */
	private array $cache = [];

	/** @var array<string> Global group registry. */
	private array $globalGroups = [];

	/** @var array<string> Non-persistent group registry. */
	private array $nonPersistentGroups = [];

	/** @var array<string> Non-prefetchable group registry (v2, stored for later). */
	private array $nonPrefetchableGroups = [];

	/** @var array<string,bool> Memoized wildcard group-match results. */
	private array $wildcardMemo = [];

	/** @var string Prefix applied to all keys. */
	private string $prefix = 'wpmgr';

	/** @var int Current blog ID for key namespacing in multisite. */
	private int $blogId = 1;

	/** @var int Max TTL in seconds (D6, default 7d). */
	private int $maxttl = 604800;

	/** @var int Query-group TTL in seconds (default 24h). */
	private int $queryttl = 86400;

	/** @var bool Whether to use UNLINK for deletes. */
	private bool $asyncFlush = false;

	/** @var string Flush strategy: 'auto' | 'flushdb' | 'scan'. */
	private string $flushStrategy = 'auto';

	/** @var bool Whether this is a shared Redis instance. */
	private bool $shared = true;

	/** @var bool Whether to flush on failback after an outage. */
	private bool $flushOnFailback = true;

	/** @var bool Whether we flushed on a previous failback this boot. */
	private bool $failbackFlushed = false;

	/** @var array<string,mixed> Loaded config array. */
	private array $config = [];

	/** @var int Hit counter for current request. */
	public int $cache_hits = 0;

	/** @var int Miss counter for current request. */
	public int $cache_misses = 0;

	/** Legacy aliases for plugins that poke internals. */
	public int $hits = 0;

	/** Legacy alias. */
	public int $misses = 0;

	/** @var array<string> Per-request error journal (last N errors). */
	private array $errorJournal = [];

	/** Maximum entries in the error journal. */
	private const MAX_JOURNAL = 20;

	/** @var float Total wait time (ms) for Redis commands this request. */
	private float $totalWaitMs = 0.0;

	/** @var int Total Redis reads this request. */
	private int $redisReads = 0;

	/** @var int Total Redis writes this request. */
	private int $redisWrites = 0;

	/** @var bool Whether KEEPTTL is supported (probed at connect). */
	private bool $keepttlSupported = false;

	// -------------------------------------------------------------------------
	// Factory + boot
	// -------------------------------------------------------------------------

	/**
	 * Boot the cache: load config, connect, return the instance.
	 * On any Throwable during boot, return an array-mode instance.
	 *
	 * @return self
	 */
	public static function boot(): self
	{
		$instance = new self();
		$instance->globalGroups         = array_flip( self::DEFAULT_GLOBAL_GROUPS );
		$instance->nonPersistentGroups  = array_flip( self::DEFAULT_NON_PERSISTENT );

		// Set the current blog ID from WordPress globals when available.
		if ( isset( $GLOBALS['blog_id'] ) ) {
			$instance->blogId = (int) $GLOBALS['blog_id'];
		}

		try {
			// Load config from the 0600 file.
			if ( ! class_exists( 'WPMgr\Agent\ObjectCache\ObjectCacheConfig' ) ) {
				// Supporting classes not loaded (e.g. engine loaded standalone).
				$instance->bootArrayMode( 'classes_missing' );
				return $instance;
			}

			$configLoader = new \WPMgr\Agent\ObjectCache\ObjectCacheConfig();
			$config       = $configLoader->load();

			if ( $config === [] ) {
				// No config stored yet; run in array mode.
				$instance->bootArrayMode( 'config_empty' );
				return $instance;
			}

			$instance->config     = $config;
			$instance->prefix     = isset( $config['prefix'] ) && is_string( $config['prefix'] )
				? $instance->sanitizePrefix( (string) $config['prefix'] )
				: 'wpmgr';
			$instance->maxttl     = isset( $config['maxttl_seconds'] ) ? (int) $config['maxttl_seconds'] : 604800;
			$instance->queryttl   = isset( $config['queryttl_seconds'] ) ? (int) $config['queryttl_seconds'] : 86400;
			$instance->asyncFlush = isset( $config['async_flush'] ) && (bool) $config['async_flush'];
			$instance->flushStrategy = isset( $config['flush_strategy'] ) && is_string( $config['flush_strategy'] )
				? (string) $config['flush_strategy'] : 'auto';
			$instance->shared        = ! isset( $config['shared'] ) || (bool) $config['shared'];
			$instance->flushOnFailback = ! isset( $config['flush_on_failback'] ) || (bool) $config['flush_on_failback'];

			// Connect.
			$instance->connection = new \WPMgr\Agent\ObjectCache\RedisConnection( $config );
			$instance->redis      = $instance->connection->acquire();

			// Probe KEEPTTL support.
			$caps = \WPMgr\Agent\ObjectCache\RedisConnection::probeCapabilities( $instance->redis );
			$instance->keepttlSupported = (bool) ( $caps['keepttl_supported'] ?? false );

			// Metadata integrity check.
			$instance->checkMetadataIntegrity( $config );

		} catch ( \Throwable $e ) {
			$instance->bootArrayMode( $e->getMessage() );
		}

		return $instance;
	}

	/**
	 * Enter array-only mode (graceful degradation).
	 *
	 * @param string $reason Reason for the fallback (logged when WP_DEBUG is on).
	 * @return void
	 */
	private function bootArrayMode( string $reason ): void
	{
		$this->arrayMode  = true;
		$this->redis      = null;
		$this->connection = null;

		if ( $reason !== '' ) {
			$this->journalError( 'boot_failure', $reason );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic
				error_log( 'WPMgr Object Cache: degraded to array-only mode. Reason: ' . $reason );
			}
		}
	}

	// -------------------------------------------------------------------------
	// wp_cache_* API implementation
	// -------------------------------------------------------------------------

	/**
	 * Adds data to the cache if the key does not already exist.
	 *
	 * Accepts any scalar for $group and $expire to match WP core's loose-typed
	 * cache.php API. Non-string/falsy $group is cast to string (empty => 'default').
	 * Non-int $expire is cast to int.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   Data to store.
	 * @param mixed      $group  Cache group (any scalar).
	 * @param mixed      $expire TTL in seconds (any numeric).
	 * @return bool
	 */
	public function add( $key, $data, $group = '', $expire = 0 ): bool
	{
		$group  = $this->castGroup( $group );
		$expire = (int) $expire;
		if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
			return false;
		}
		if ( ! $this->validateKey( $key ) ) {
			return false;
		}
		$group  = $this->normalizeGroup( $group );
		$keyStr = (string) $key;

		// L1 hit: key already exists.
		if ( isset( $this->cache[ $group ][ $keyStr ] ) ) {
			return false;
		}

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			$this->storeL1( $group, $keyStr, $data );
			return true;
		}

		$redisKey = $this->buildKey( $keyStr, $group );
		$ttl      = $this->clampTtl( $expire, $group );

		return $this->redisOp(
			function () use ( $redisKey, $data, $ttl ): bool {
				$this->redisWrites++;
				if ( $ttl > 0 ) {
					$result = $this->redis->set( $redisKey, $data, [ 'nx', 'ex' => $ttl ] );
				} else {
					$result = $this->redis->set( $redisKey, $data, [ 'nx' ] );
				}
				return $result === true;
			},
			static function (): bool {
				return false;
			}
		) && $this->storeL1( $group, $keyStr, $data );
	}

	/**
	 * Adds multiple cache entries.
	 *
	 * @param array<int|string,mixed> $data   Map of key => value.
	 * @param mixed                   $group  Cache group (any scalar).
	 * @param mixed                   $expire TTL in seconds (any numeric).
	 * @return array<int|string,bool>
	 */
	public function add_multiple( array $data, $group = '', $expire = 0 ): array
	{
		$group  = $this->castGroup( $group );
		$expire = (int) $expire;
		$results = [];
		foreach ( $data as $key => $value ) {
			$results[ $key ] = $this->add( $key, $value, $group, $expire );
		}
		return $results;
	}

	/**
	 * Replaces cached data only when the key already exists.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   New data.
	 * @param mixed      $group  Cache group (any scalar).
	 * @param mixed      $expire TTL in seconds (any numeric).
	 * @return bool
	 */
	public function replace( $key, $data, $group = '', $expire = 0 ): bool
	{
		$group  = $this->castGroup( $group );
		$expire = (int) $expire;
		if ( ! $this->validateKey( $key ) ) {
			return false;
		}
		$group  = $this->normalizeGroup( $group );
		$keyStr = (string) $key;

		// Must already exist.
		$found  = null;
		$this->get( $key, $group, false, $found );
		if ( ! $found ) {
			return false;
		}

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			$this->storeL1( $group, $keyStr, $data );
			return true;
		}

		$redisKey = $this->buildKey( $keyStr, $group );
		$ttl      = $this->clampTtl( $expire, $group );

		return $this->redisOp(
			function () use ( $redisKey, $data, $ttl ): bool {
				$this->redisWrites++;
				if ( $ttl > 0 ) {
					$result = $this->redis->set( $redisKey, $data, [ 'xx', 'ex' => $ttl ] );
				} else {
					$result = $this->redis->set( $redisKey, $data, [ 'xx' ] );
				}
				return $result === true;
			},
			static function (): bool {
				return false;
			}
		) && $this->storeL1( $group, $keyStr, $data );
	}

	/**
	 * Saves data to the cache.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   Data to store.
	 * @param mixed      $group  Cache group (any scalar).
	 * @param mixed      $expire TTL in seconds (any numeric; 0 = use maxttl).
	 * @return bool
	 */
	public function set( $key, $data, $group = '', $expire = 0 ): bool
	{
		$group  = $this->castGroup( $group );
		$expire = (int) $expire;
		if ( ! $this->validateKey( $key ) ) {
			return false;
		}
		$group  = $this->normalizeGroup( $group );
		$keyStr = (string) $key;

		$this->storeL1( $group, $keyStr, $data );

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			return true;
		}

		$redisKey = $this->buildKey( $keyStr, $group );
		$ttl      = $this->clampTtl( $expire, $group );

		return $this->redisOp(
			function () use ( $redisKey, $data, $ttl ): bool {
				$this->redisWrites++;
				if ( $ttl > 0 ) {
					return $this->redis->setex( $redisKey, $ttl, $data ) === true;
				}
				return $this->redis->set( $redisKey, $data ) !== false;
			},
			static function (): bool {
				return false;
			}
		);
	}

	/**
	 * Sets multiple cache entries using a pipeline.
	 *
	 * @param array<int|string,mixed> $data   Map of key => value.
	 * @param mixed                   $group  Cache group (any scalar).
	 * @param mixed                   $expire TTL in seconds (any numeric).
	 * @return array<int|string,bool>
	 */
	public function set_multiple( array $data, $group = '', $expire = 0 ): array
	{
		$group   = $this->castGroup( $group );
		$expire  = (int) $expire;
		$group   = $this->normalizeGroup( $group );
		$results = [];

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			foreach ( $data as $key => $value ) {
				if ( $this->validateKey( $key ) ) {
					$this->storeL1( $group, (string) $key, $value );
					$results[ $key ] = true;
				} else {
					$results[ $key ] = false;
				}
			}
			return $results;
		}

		// Validate and batch.
		$valid   = [];
		foreach ( $data as $key => $value ) {
			if ( $this->validateKey( $key ) ) {
				$valid[ (string) $key ] = $value;
				$this->storeL1( $group, (string) $key, $value );
				$results[ $key ] = false; // Pre-fill; overwritten on success.
			} else {
				$results[ $key ] = false;
			}
		}

		if ( $valid === [] ) {
			return $results;
		}

		$ttl = $this->clampTtl( $expire, $group );

		$this->redisOp(
			function () use ( $valid, $group, $ttl, &$results ): bool {
				$this->redisWrites += count( $valid );
				$pipe = $this->redis->pipeline();
				foreach ( $valid as $keyStr => $value ) {
					$redisKey = $this->buildKey( $keyStr, $group );
					if ( $ttl > 0 ) {
						$pipe->setex( $redisKey, $ttl, $value );
					} else {
						$pipe->set( $redisKey, $value );
					}
				}
				$pipeResults = $pipe->exec();
				if ( is_array( $pipeResults ) ) {
					$keys = array_keys( $valid );
					foreach ( $pipeResults as $i => $res ) {
						if ( isset( $keys[ $i ] ) ) {
							$results[ $keys[ $i ] ] = ( $res === true || $res === 'OK' );
						}
					}
				}
				return true;
			},
			static function () use ( &$results ): bool {
				// On failure all remain false.
				return false;
			}
		);

		return $results;
	}

	/**
	 * Retrieves cached data.
	 *
	 * @param int|string $key   Cache key.
	 * @param mixed      $group Cache group (any scalar).
	 * @param bool       $force Bypass L1.
	 * @param bool|null  $found Output: whether the key was found.
	 * @return mixed False when not found.
	 */
	public function get( $key, $group = '', bool $force = false, ?bool &$found = null )
	{
		$group = $this->castGroup( $group );
		if ( ! $this->validateKey( $key ) ) {
			$found = false;
			return false;
		}
		$group  = $this->normalizeGroup( $group );
		$keyStr = (string) $key;

		// L1 hit (unless forced).
		if ( ! $force && isset( $this->cache[ $group ][ $keyStr ] ) ) {
			$found = true;
			$this->cache_hits++;
			$this->hits++;
			return $this->cloneValue( $this->cache[ $group ][ $keyStr ] );
		}

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			$found = false;
			$this->cache_misses++;
			$this->misses++;
			return false;
		}

		$redisKey = $this->buildKey( $keyStr, $group );

		$value = $this->redisOp(
			function () use ( $redisKey ): mixed {
				$this->redisReads++;
				$val = $this->redis->get( $redisKey );
				return $val;
			},
			static function (): mixed {
				return false;
			},
			true // idempotent read: retry-once on timeout
		);

		if ( $value === false ) {
			$found = false;
			$this->cache_misses++;
			$this->misses++;
			return false;
		}

		$found = true;
		$this->cache_hits++;
		$this->hits++;
		$this->storeL1( $group, $keyStr, $value );
		return $this->cloneValue( $value );
	}

	/**
	 * Retrieves multiple cached values using MGET.
	 *
	 * @param array<int|string> $keys  Cache keys.
	 * @param mixed             $group Cache group (any scalar).
	 * @param bool              $force Bypass L1.
	 * @return array<int|string,mixed>
	 */
	public function get_multiple( array $keys, $group = '', bool $force = false ): array
	{
		$group   = $this->castGroup( $group );
		$group   = $this->normalizeGroup( $group );
		$results = [];

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			foreach ( $keys as $key ) {
				if ( $this->validateKey( $key ) ) {
					$keyStr = (string) $key;
					$results[ $key ] = isset( $this->cache[ $group ][ $keyStr ] )
						? $this->cloneValue( $this->cache[ $group ][ $keyStr ] )
						: false;
				} else {
					$results[ $key ] = false;
				}
			}
			return $results;
		}

		// Partition: L1 hits vs Redis misses.
		$l1Results   = [];
		$redisKeys   = [];
		$redisKeyMap = []; // redisKey => original key

		foreach ( $keys as $key ) {
			if ( ! $this->validateKey( $key ) ) {
				$results[ $key ] = false;
				continue;
			}
			$keyStr = (string) $key;
			if ( ! $force && isset( $this->cache[ $group ][ $keyStr ] ) ) {
				$l1Results[ $key ] = $this->cloneValue( $this->cache[ $group ][ $keyStr ] );
				$this->cache_hits++;
				$this->hits++;
			} else {
				$redisKey = $this->buildKey( $keyStr, $group );
				$redisKeys[]              = $redisKey;
				$redisKeyMap[ $redisKey ] = $key;
				$results[ $key ]          = false; // Default to miss.
			}
		}

		// Merge L1 hits.
		foreach ( $l1Results as $key => $value ) {
			$results[ $key ] = $value;
		}

		if ( $redisKeys === [] ) {
			return $results;
		}

		$this->redisOp(
			function () use ( $redisKeys, $redisKeyMap, $group, &$results ): bool {
				$this->redisReads += count( $redisKeys );
				$fetched = $this->redis->mget( $redisKeys );
				if ( ! is_array( $fetched ) ) {
					return false;
				}
				foreach ( $redisKeys as $i => $redisKey ) {
					if ( ! isset( $fetched[ $i ] ) ) {
						continue;
					}
					$val = $fetched[ $i ];
					$origKey = $redisKeyMap[ $redisKey ] ?? null;
					if ( $origKey === null ) {
						continue;
					}
					if ( $val === false ) {
						$this->cache_misses++;
						$this->misses++;
					} else {
						$this->cache_hits++;
						$this->hits++;
						$this->storeL1( $group, (string) $origKey, $val );
						$results[ $origKey ] = $this->cloneValue( $val );
					}
				}
				return true;
			},
			function () use ( $redisKeyMap, &$results ): bool {
				foreach ( $redisKeyMap as $origKey ) {
					$this->cache_misses++;
					$this->misses++;
				}
				return false;
			},
			true
		);

		return $results;
	}

	/**
	 * Deletes cached data.
	 *
	 * @param int|string $key   Cache key.
	 * @param mixed      $group Cache group (any scalar).
	 * @return bool
	 */
	public function delete( $key, $group = '' ): bool
	{
		$group = $this->castGroup( $group );
		if ( ! $this->validateKey( $key ) ) {
			return false;
		}
		$group  = $this->normalizeGroup( $group );
		$keyStr = (string) $key;

		unset( $this->cache[ $group ][ $keyStr ] );

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			return true;
		}

		$redisKey = $this->buildKey( $keyStr, $group );

		return $this->redisOp(
			function () use ( $redisKey ): bool {
				$this->redisWrites++;
				if ( $this->asyncFlush ) {
					return $this->redis->unlink( $redisKey ) >= 0;
				}
				return $this->redis->del( $redisKey ) >= 0;
			},
			static function (): bool {
				return false;
			}
		);
	}

	/**
	 * Deletes multiple cached entries.
	 *
	 * @param array<int|string> $keys  Cache keys.
	 * @param mixed             $group Cache group (any scalar).
	 * @return array<int|string,bool>
	 */
	public function delete_multiple( array $keys, $group = '' ): array
	{
		$group   = $this->castGroup( $group );
		$group   = $this->normalizeGroup( $group );
		$results = [];

		foreach ( $keys as $key ) {
			$results[ $key ] = $this->delete( $key, $group );
		}
		return $results;
	}

	/**
	 * Increments a numeric cache item, preserving TTL via KEEPTTL where supported.
	 *
	 * @param int|string $key    Cache key.
	 * @param int        $offset Amount to increment.
	 * @param mixed      $group  Cache group (any scalar).
	 * @return int|false New value or false on failure.
	 */
	public function incr( $key, int $offset = 1, $group = '' )
	{
		$group = $this->castGroup( $group );
		if ( ! $this->validateKey( $key ) ) {
			return false;
		}
		$group  = $this->normalizeGroup( $group );
		$keyStr = (string) $key;

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			$current = isset( $this->cache[ $group ][ $keyStr ] )
				? (int) $this->cache[ $group ][ $keyStr ] : 0;
			$new = max( 0, $current + $offset );
			$this->storeL1( $group, $keyStr, $new );
			return $new;
		}

		$redisKey = $this->buildKey( $keyStr, $group );

		$result = $this->redisOp(
			function () use ( $redisKey, $keyStr, $group, $offset ): int|false {
				$this->redisWrites++;
				if ( $this->keepttlSupported ) {
					// Get current value and TTL, then SET KEEPTTL.
					$current = $this->redis->get( $redisKey );
					$newVal  = max( 0, ( $current === false ? 0 : (int) $current ) + $offset );
					$ttl     = $this->redis->ttl( $redisKey );
					$opts    = [ 'keepttl' ];
					if ( $ttl > 0 ) {
						$opts = [ 'ex' => $ttl ];
					}
					$this->redis->set( $redisKey, $newVal, $opts );
					return $newVal;
				} else {
					// Fallback: INCRBY (does not preserve TTL, but is atomic).
					$newVal = $this->redis->incrBy( $redisKey, $offset );
					if ( $newVal < 0 ) {
						$this->redis->set( $redisKey, 0 );
						return 0;
					}
					return $newVal;
				}
			},
			static function (): false {
				return false;
			}
		);

		if ( $result !== false ) {
			$this->storeL1( $group, $keyStr, $result );
		} else {
			unset( $this->cache[ $group ][ $keyStr ] );
		}
		return $result;
	}

	/**
	 * Decrements a numeric cache item, preserving TTL via KEEPTTL where supported.
	 *
	 * @param int|string $key    Cache key.
	 * @param int        $offset Amount to decrement.
	 * @param mixed      $group  Cache group (any scalar).
	 * @return int|false New value or false on failure.
	 */
	public function decr( $key, int $offset = 1, $group = '' )
	{
		$group = $this->castGroup( $group );
		if ( ! $this->validateKey( $key ) ) {
			return false;
		}
		$group  = $this->normalizeGroup( $group );
		$keyStr = (string) $key;

		if ( $this->isNonPersistent( $group ) || $this->arrayMode ) {
			$current = isset( $this->cache[ $group ][ $keyStr ] )
				? (int) $this->cache[ $group ][ $keyStr ] : 0;
			$new = max( 0, $current - $offset );
			$this->storeL1( $group, $keyStr, $new );
			return $new;
		}

		$redisKey = $this->buildKey( $keyStr, $group );

		$result = $this->redisOp(
			function () use ( $redisKey, $offset ): int|false {
				$this->redisWrites++;
				if ( $this->keepttlSupported ) {
					$current = $this->redis->get( $redisKey );
					$newVal  = max( 0, ( $current === false ? 0 : (int) $current ) - $offset );
					$ttl     = $this->redis->ttl( $redisKey );
					$opts    = [ 'keepttl' ];
					if ( $ttl > 0 ) {
						$opts = [ 'ex' => $ttl ];
					}
					$this->redis->set( $redisKey, $newVal, $opts );
					return $newVal;
				} else {
					$newVal = $this->redis->decrBy( $redisKey, $offset );
					if ( $newVal < 0 ) {
						$this->redis->set( $redisKey, 0 );
						return 0;
					}
					return $newVal;
				}
			},
			static function (): false {
				return false;
			}
		);

		if ( $result !== false ) {
			$this->storeL1( $group, $keyStr, $result );
		} else {
			unset( $this->cache[ $group ][ $keyStr ] );
		}
		return $result;
	}

	/**
	 * Flushes the entire cache. Strategy: FLUSHDB on dedicated, SCAN+UNLINK on shared.
	 *
	 * @return bool
	 */
	public function flush(): bool
	{
		$this->cache = [];

		if ( $this->arrayMode ) {
			return true;
		}

		return $this->redisOp(
			function (): bool {
				return $this->executeFlush( 'all' );
			},
			static function (): bool {
				return false;
			}
		);
	}

	/**
	 * Flushes only the in-memory runtime cache.
	 *
	 * @return bool
	 */
	public function flush_runtime(): bool
	{
		$this->cache = [];
		return true;
	}

	/**
	 * Flushes all entries in a specific group.
	 *
	 * @param mixed $group Cache group (any scalar).
	 * @return bool
	 */
	public function flush_group( $group ): bool
	{
		$group = $this->castGroup( $group );
		$group = $this->normalizeGroup( $group );
		unset( $this->cache[ $group ] );

		if ( $this->arrayMode || $this->isNonPersistent( $group ) ) {
			return true;
		}

		return $this->redisOp(
			function () use ( $group ): bool {
				return $this->executeGroupFlush( $group );
			},
			static function (): bool {
				return false;
			}
		);
	}

	/**
	 * Initialises the cache (called from WordPress init hook).
	 *
	 * @return void
	 */
	public function init(): void
	{
		if ( isset( $GLOBALS['blog_id'] ) ) {
			$this->blogId = (int) $GLOBALS['blog_id'];
		}
	}

	/**
	 * Closes the connection. Work is deferred to shutdown(); this is a no-op.
	 *
	 * @return bool
	 */
	public function close(): bool
	{
		return true;
	}

	/**
	 * Shutdown hook: persist stats (for heartbeat), close connection.
	 *
	 * @return void
	 */
	public function shutdown(): void
	{
		try {
			$this->persistStats();
		} catch ( \Throwable $e ) {
			// Best-effort.
		}
		if ( $this->connection !== null ) {
			// pconnect handles stay pooled in the FPM worker; close is a no-op.
		}
	}

	/**
	 * Switches the blog context (multisite).
	 *
	 * @param int $blogId Blog ID to switch to.
	 * @return void
	 */
	public function switch_to_blog( int $blogId ): void
	{
		$this->blogId    = $blogId;
		$this->wildcardMemo = []; // Invalidate memos when blog changes.
	}

	/**
	 * Registers global groups.
	 *
	 * @param array<string> $groups Groups to add.
	 * @return void
	 */
	public function add_global_groups( array $groups ): void
	{
		foreach ( $groups as $group ) {
			if ( is_string( $group ) && $group !== '' ) {
				$this->globalGroups[ $group ] = true;
				// Memo invalidation: a late registration may change routing.
				unset( $this->wildcardMemo[ $group ] );
			}
		}
	}

	/**
	 * Registers non-persistent groups.
	 *
	 * @param array<string> $groups Groups to add.
	 * @return void
	 */
	public function add_non_persistent_groups( array $groups ): void
	{
		foreach ( $groups as $group ) {
			if ( is_string( $group ) && $group !== '' ) {
				$this->nonPersistentGroups[ $group ] = true;
				unset( $this->wildcardMemo[ $group ] );
			}
		}
	}

	/**
	 * Registers non-prefetchable groups (v2 stub; stored for future prefetch).
	 *
	 * @param array<string> $groups Groups to add.
	 * @return void
	 */
	public function add_non_prefetchable_groups( array $groups ): void
	{
		foreach ( $groups as $group ) {
			if ( is_string( $group ) ) {
				$this->nonPrefetchableGroups[] = $group;
			}
		}
	}

	/**
	 * Reports whether a specific feature is supported.
	 *
	 * @param string $feature Feature name.
	 * @return bool
	 */
	public function supports( string $feature ): bool
	{
		return in_array( $feature, self::SUPPORTS, true );
	}

	/**
	 * Whether the cache is in array-only (degraded) mode.
	 *
	 * @return bool
	 */
	public function isArrayMode(): bool
	{
		return $this->arrayMode;
	}

	/**
	 * Return the per-request error journal (for diagnostics/heartbeat).
	 *
	 * @return array<string>
	 */
	public function getErrorJournal(): array
	{
		return $this->errorJournal;
	}

	/**
	 * Record a Throwable class name caught in a wp_cache_* wrapper.
	 * Called from the global bridge functions so unexpected engine errors never
	 * escape to user-visible PHP fatals.
	 *
	 * @param string $class Throwable class name (from get_class($e)).
	 * @return void
	 */
	public function wpmgr_journal_wrapper_error( string $class ): void
	{
		$this->journalError( 'wrapper_catch', $class );
	}

	/**
	 * Return stats suitable for the heartbeat block.
	 *
	 * @return array<string,mixed>
	 */
	public function getHeartbeatStats(): array
	{
		$state = 'disabled';
		if ( $this->arrayMode && count( $this->errorJournal ) > 0 ) {
			$state = 'down';
		} elseif ( $this->arrayMode ) {
			$state = 'disabled';
		} elseif ( $this->connection !== null && $this->connection->isDegraded() ) {
			$state = 'degraded';
		} elseif ( $this->redis !== null ) {
			$state = 'connected';
		}

		$totalOps = $this->cache_hits + $this->cache_misses;
		$hitRatio = $totalOps > 0 ? round( $this->cache_hits / $totalOps * 100, 1 ) : 0.0;
		$latencyMs = $this->redisReads + $this->redisWrites > 0
			? round( $this->totalWaitMs / ( $this->redisReads + $this->redisWrites ), 2 )
			: 0.0;

		$lastError = $this->errorJournal !== [] ? $this->errorJournal[ count( $this->errorJournal ) - 1 ] : '';

		$stats = [
			'state'              => $state,
			'latency_ms'         => $latencyMs,
			'last_error_class'   => $lastError,
			'hit_ratio_window_pct' => $hitRatio,
			'engine_version'     => self::ENGINE_VERSION,
		];

		// used_memory_bytes: attempt a live INFO query (best-effort, no extra cost
		// if INFO is denied or throws).
		if ( $this->redis !== null && ! $this->arrayMode ) {
			try {
				$info = @$this->redis->info( 'memory' );
				if ( is_array( $info ) && isset( $info['used_memory'] ) ) {
					$stats['used_memory_bytes'] = (int) $info['used_memory'];
				}
			} catch ( \Throwable $e ) {
				// Best-effort; omit the field on denial.
			}
		}

		return $stats;
	}

	// -------------------------------------------------------------------------
	// Internal: key building, group classification, TTL, L1
	// -------------------------------------------------------------------------

	/**
	 * Build a fully-qualified Redis key.
	 * Shape: prefix:[blogId:]group:key
	 *
	 * @param string $key   Cache key (already validated as string).
	 * @param string $group Normalized group name.
	 * @return string
	 */
	private function buildKey( string $key, string $group ): string
	{
		$isGlobal = isset( $this->globalGroups[ $group ] );
		$prefix   = $this->prefix;

		if ( $isGlobal ) {
			return $prefix . ':' . $group . ':' . $key;
		}
		return $prefix . ':' . $this->blogId . ':' . $group . ':' . $key;
	}

	/**
	 * Cast any scalar $group to string, matching WP core's loose-typed cache API.
	 *
	 * WP core cache.php declares $group as untyped with a string default. Callers
	 * may legally pass any scalar (int, float, bool, null). We cast to string so
	 * normalizeGroup always receives a string. Empty/falsy values become '' which
	 * normalizeGroup then maps to 'default'.
	 *
	 * @param mixed $group Raw group value from the caller.
	 * @return string
	 */
	private function castGroup( $group ): string
	{
		if ( is_string( $group ) ) {
			return $group;
		}
		// null, false, 0 => '' (normalizeGroup will convert to 'default').
		if ( $group === null || $group === false || $group === 0 || $group === 0.0 ) {
			return '';
		}
		return (string) $group;
	}

	/**
	 * Normalize a group string: trim + default to 'default'.
	 *
	 * @param string $group Raw group.
	 * @return string
	 */
	private function normalizeGroup( string $group ): string
	{
		$group = trim( $group );
		return $group !== '' ? $group : 'default';
	}

	/**
	 * Sanitize and truncate the prefix to 32 characters, replacing unsafe chars.
	 *
	 * @param string $prefix Raw prefix.
	 * @return string
	 */
	private function sanitizePrefix( string $prefix ): string
	{
		$prefix = preg_replace( '/[^a-zA-Z0-9_-]/', '_', $prefix ) ?? 'wpmgr';
		$prefix = substr( $prefix, 0, 32 );
		// An empty prefix after sanitization defeats shared-Redis namespacing
		// and makes SCAN `:*` flush cross site boundaries. Fall back to 'wpmgr'.
		return $prefix !== '' ? $prefix : 'wpmgr';
	}

	/**
	 * Whether a group is non-persistent (runtime-only).
	 * Supports fnmatch wildcards in registered group names; results are memoized.
	 *
	 * @param string $group Normalized group.
	 * @return bool
	 */
	private function isNonPersistent( string $group ): bool
	{
		if ( isset( $this->nonPersistentGroups[ $group ] ) ) {
			return true;
		}
		// Wildcard match (memoized).
		if ( array_key_exists( 'np_' . $group, $this->wildcardMemo ) ) {
			return $this->wildcardMemo[ 'np_' . $group ];
		}
		foreach ( array_keys( $this->nonPersistentGroups ) as $pattern ) {
			if ( strpos( $pattern, '*' ) !== false && fnmatch( $pattern, $group ) ) {
				$this->wildcardMemo[ 'np_' . $group ] = true;
				return true;
			}
		}
		$this->wildcardMemo[ 'np_' . $group ] = false;
		return false;
	}

	/**
	 * Clamp a TTL: negative => 0 (delete), 0 or > maxttl => maxttl.
	 * Query groups get min(queryttl, maxttl).
	 *
	 * @param int    $ttl   Requested TTL.
	 * @param string $group Normalized group.
	 * @return int
	 */
	private function clampTtl( int $ttl, string $group ): int
	{
		if ( $ttl < 0 ) {
			return 1; // Treat negative as "expire immediately".
		}

		// Query groups.
		if ( strpos( $group, '-queries' ) !== false ) {
			$limit = min( $this->queryttl, $this->maxttl );
			if ( $ttl === 0 || $ttl > $limit ) {
				return $limit;
			}
			return $ttl;
		}

		if ( $ttl === 0 || $ttl > $this->maxttl ) {
			return $this->maxttl;
		}
		return $ttl;
	}

	/**
	 * Store a value in the L1 array cache with clone-on-store.
	 *
	 * @param string $group  Normalized group.
	 * @param string $keyStr Key string.
	 * @param mixed  $value  Value to store.
	 * @return bool Always true (for fluent chaining).
	 */
	private function storeL1( string $group, string $keyStr, mixed $value ): bool
	{
		$this->cache[ $group ][ $keyStr ] = $this->cloneValue( $value );
		return true;
	}

	/**
	 * Clone an object or return a scalar/array as-is. Clone-on-read/store
	 * prevents by-reference mutation leaks.
	 *
	 * @param mixed $value Value to clone.
	 * @return mixed
	 */
	private function cloneValue( mixed $value ): mixed
	{
		return is_object( $value ) ? clone $value : $value;
	}

	/**
	 * Validate that a key is a string or integer. Non-valid keys are journaled.
	 *
	 * @param mixed $key Raw key.
	 * @return bool
	 */
	private function validateKey( mixed $key ): bool
	{
		if ( is_int( $key ) || is_string( $key ) ) {
			return true;
		}
		$this->journalError( 'invalid_key', 'key must be int or string; got ' . gettype( $key ) );
		return false;
	}

	// -------------------------------------------------------------------------
	// Internal: Redis operation wrapper with degradation
	// -------------------------------------------------------------------------

	/**
	 * Execute a Redis operation with per-op try/catch degradation.
	 *
	 * On failure: journal the error, try reconnect-once (only for idempotent
	 * reads), then fall back to the $onError result for the remainder of the
	 * request. The site never errors.
	 *
	 * @template T
	 * @param callable(): T $op          Redis operation.
	 * @param callable(): T $onError     Fallback when degraded.
	 * @param bool          $idempotent  Whether a read-timeout retry is safe.
	 * @return T
	 */
	private function redisOp( callable $op, callable $onError, bool $idempotent = false ): mixed
	{
		if ( $this->arrayMode || $this->redis === null || $this->connection === null ) {
			return $onError();
		}

		$t0 = microtime( true );

		try {
			$result = $op();
			$this->totalWaitMs += ( microtime( true ) - $t0 ) * 1000.0;
			$this->connection->recordSuccess();

			// Failback flush: only when the connection genuinely recovered from a
			// prior outage THIS request (wasDegraded() is set by markDegraded() and
			// never cleared). Without the wasDegraded() guard, the first successful
			// op of every healthy request would trigger a full keyspace SCAN+DEL.
			if (
				$this->flushOnFailback
				&& ! $this->failbackFlushed
				&& $this->connection->wasDegraded()
				&& ! $this->connection->isDegraded()
			) {
				$this->executeFailbackFlush();
			}

			return $result;

		} catch ( \Throwable $e ) {
			$this->totalWaitMs += ( microtime( true ) - $t0 ) * 1000.0;
			$this->journalError( get_class( $e ), $e->getMessage() );

			// Attempt reconnect-once per request for idempotent reads.
			if ( $idempotent && ! $this->reconnectAttempted && $this->connection !== null ) {
				$this->reconnectAttempted = true;
				$this->connection->markDegraded();
				try {
					$this->redis = $this->connection->acquire();
					$t1 = microtime( true );
					$result = $op();
					$this->totalWaitMs += ( microtime( true ) - $t1 ) * 1000.0;
					return $result;
				} catch ( \Throwable $e2 ) {
					$this->journalError( 'reconnect_failed', $e2->getMessage() );
				}
			}

			$this->connection->markDegraded();
			return $onError();
		}
	}

	// -------------------------------------------------------------------------
	// Internal: flush strategies
	// -------------------------------------------------------------------------

	/**
	 * Execute the flush strategy for a full or site-scoped flush.
	 * FLUSHALL is never issued.
	 *
	 * @param string $scope 'all' | 'site' | 'group' (group handled separately).
	 * @return bool
	 */
	private function executeFlush( string $scope ): bool
	{
		$useFlushDb = false;

		if ( $this->flushStrategy === 'flushdb' && ! $this->shared ) {
			$useFlushDb = true;
		} elseif ( $this->flushStrategy === 'auto' && ! $this->shared ) {
			$useFlushDb = true;
		}

		if ( $useFlushDb ) {
			if ( $this->asyncFlush ) {
				$this->redis->flushDB( true );
			} else {
				$this->redis->flushDB( false );
			}
			return true;
		}

		// Shared or scan-only: SCAN+MATCH+UNLINK prefix-scoped.
		return $this->executeScanFlush( $this->prefix . ':' );
	}

	/**
	 * Execute a SCAN+MATCH+UNLINK flush scoped to the given pattern prefix.
	 * COUNT 500, inter-batch sleep (0.5ms) to bound instance impact.
	 *
	 * Uses the canonical phpredis SCAN idiom: by-ref integer iterator and
	 * SCAN_RETRY so phpredis handles empty-batch re-scanning internally,
	 * returning a flat key array (not the [cursor, keys] tuple used by Predis).
	 *
	 * @param string $prefixPattern Key prefix to match (e.g. "wpmgr:").
	 * @return bool
	 */
	private function executeScanFlush( string $prefixPattern ): bool
	{
		if ( $this->redis === null ) {
			return false;
		}

		$this->redis->setOption( \Redis::OPT_SCAN, \Redis::SCAN_RETRY );
		$it      = null;
		$pattern = $prefixPattern . '*';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scan -- phpredis SCAN command, not filesystem; by-ref iterator is the canonical phpredis pattern
		while ( ( $keys = $this->redis->scan( $it, $pattern, 500 ) ) !== false ) {
			if ( ! empty( $keys ) ) {
				if ( $this->asyncFlush ) {
					$this->redis->unlink( ...$keys );
				} else {
					$this->redis->del( ...$keys );
				}
				usleep( 500 ); // 0.5ms inter-batch sleep to reduce instance impact.
			}
			if ( $it === 0 ) {
				break;
			}
		}

		return true;
	}

	/**
	 * Flush all keys for a specific group via SCAN+MATCH+UNLINK.
	 *
	 * The SCAN globs use '*' to span blog-ID and key segments, but '*' in Redis
	 * glob spans ':' — so the pattern `prefix:*:post:*` also matches a key like
	 * `prefix:1:postmeta:key` if the group substring appears as an interior
	 * token. Post-filter each SCAN batch: only UNLINK keys whose colon-delimited
	 * segments contain the exact group token at the correct position.
	 *
	 * Key shapes:
	 *   Global:   prefix:group:key
	 *   Per-blog: prefix:blogId:group:key
	 *
	 * @param string $group Normalized group.
	 * @return bool
	 */
	private function executeGroupFlush( string $group ): bool
	{
		// Match both global (no blog segment) and per-blog variants.
		$globalPattern = $this->prefix . ':' . $group . ':';
		$blogPattern   = $this->prefix . ':*:' . $group . ':';

		$this->executeScanFlushWithGroupFilter( $globalPattern, $group, false );
		$this->executeScanFlushWithGroupFilter( $blogPattern, $group, true );

		return true;
	}

	/**
	 * SCAN+MATCH+UNLINK with exact group-segment post-filter.
	 *
	 * After each SCAN batch the keys are filtered to those where the group
	 * token sits at the exact colon-segment position:
	 *   $hasBlogSegment=false: prefix:group:key     => segment index 1
	 *   $hasBlogSegment=true:  prefix:blogId:group:key => segment index 2
	 *
	 * @param string $prefixPattern SCAN MATCH pattern.
	 * @param string $group         Exact group name to confirm.
	 * @param bool   $hasBlogSegment Whether the pattern includes a blog-ID wildcard.
	 * @return void
	 */
	private function executeScanFlushWithGroupFilter( string $prefixPattern, string $group, bool $hasBlogSegment ): void
	{
		if ( $this->redis === null ) {
			return;
		}

		$pattern = $prefixPattern . '*';
		// Group segment index in the colon-delimited key:
		// Global key:   0=prefix, 1=group, 2+=key
		// Per-blog key: 0=prefix, 1=blogId, 2=group, 3+=key
		$groupSegmentIndex = $hasBlogSegment ? 2 : 1;

		// Canonical phpredis SCAN idiom: by-ref integer iterator, SCAN_RETRY,
		// flat key array return (not the [cursor, keys] tuple used by Predis).
		$this->redis->setOption( \Redis::OPT_SCAN, \Redis::SCAN_RETRY );
		$it = null;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scan -- phpredis SCAN command, not filesystem; by-ref iterator is the canonical phpredis pattern
		while ( ( $keys = $this->redis->scan( $it, $pattern, 500 ) ) !== false ) {
			if ( ! empty( $keys ) ) {
				// Post-filter: confirm the key's group segment is an exact match.
				$confirmed = [];
				foreach ( $keys as $k ) {
					$parts = explode( ':', (string) $k );
					if ( isset( $parts[ $groupSegmentIndex ] ) && $parts[ $groupSegmentIndex ] === $group ) {
						$confirmed[] = $k;
					}
				}
				if ( $confirmed !== [] ) {
					if ( $this->asyncFlush ) {
						$this->redis->unlink( ...$confirmed );
					} else {
						$this->redis->del( ...$confirmed );
					}
				}
				usleep( 500 ); // 0.5ms inter-batch sleep to reduce instance impact.
			}
			if ( $it === 0 ) {
				break;
			}
		}
	}

	/**
	 * Flush on failback: executed once per request after connection recovery.
	 *
	 * @return void
	 */
	private function executeFailbackFlush(): void
	{
		$this->failbackFlushed = true;
		try {
			$this->executeFlush( 'all' );
		} catch ( \Throwable $e ) {
			$this->journalError( 'failback_flush_failed', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Internal: metadata integrity
	// -------------------------------------------------------------------------

	/**
	 * Metadata integrity key. Written raw (no serializer/compression) so it
	 * survives serializer changes. maxttl-exempt.
	 *
	 * @return string
	 */
	private function metadataKey(): string
	{
		return $this->prefix . ':__wpmgr_oc_meta__';
	}

	/**
	 * Check the metadata integrity key. If risky options changed, flush and
	 * rewrite the metadata key.
	 *
	 * @param array<string,mixed> $config Current config.
	 * @return void
	 */
	private function checkMetadataIntegrity( array $config ): void
	{
		if ( $this->redis === null ) {
			return;
		}

		$metaKey = $this->metadataKey();

		// Read metadata using a raw (no-serializer) client to survive format changes.
		try {
			// Temporarily switch to no-serializer for the raw read.
			$savedSerializer = $this->redis->getOption( \Redis::OPT_SERIALIZER );
			$this->redis->setOption( \Redis::OPT_SERIALIZER, (string) \Redis::SERIALIZER_NONE );

			$stored = $this->redis->get( $metaKey );
			$this->redis->setOption( \Redis::OPT_SERIALIZER, $savedSerializer );

			if ( $stored !== false && is_string( $stored ) ) {
				$meta = json_decode( $stored, true );
				if ( is_array( $meta ) ) {
					$riskyChanged = false;
					if ( isset( $meta['serializer'] ) && $meta['serializer'] !== ( $config['serializer'] ?? 'php' ) ) {
						$riskyChanged = true;
					}
					if ( isset( $meta['compression'] ) && $meta['compression'] !== ( $config['compression'] ?? 'none' ) ) {
						$riskyChanged = true;
					}
					if ( isset( $meta['database'] ) && (int) $meta['database'] !== (int) ( $config['database'] ?? 0 ) ) {
						$riskyChanged = true;
					}
					if ( $riskyChanged ) {
						// Integrity flush: risky option changed.
						$this->executeFlush( 'all' );
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic
							error_log( 'WPMgr Object Cache: integrity flush triggered by config change.' );
						}
					}
				}
			}

			// Write/rewrite the metadata key (raw bytes, no TTL).
			$newMeta = (string) wp_json_encode( [
				'database'    => (int) ( $config['database'] ?? 0 ),
				'prefix'      => $this->prefix,
				'serializer'  => $config['serializer'] ?? 'php',
				'compression' => $config['compression'] ?? 'none',
				'wp_version'  => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '',
			] );
			$this->redis->setOption( \Redis::OPT_SERIALIZER, (string) \Redis::SERIALIZER_NONE );
			$this->redis->set( $metaKey, $newMeta ); // maxttl-exempt: no TTL.
			$this->redis->setOption( \Redis::OPT_SERIALIZER, $savedSerializer );

		} catch ( \Throwable $e ) {
			// Tolerate metadata key failures gracefully.
			$this->journalError( 'metadata_integrity_failed', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Internal: stats persistence
	// -------------------------------------------------------------------------

	/**
	 * Persist aggregated stats for the heartbeat block.
	 *
	 * ACCUMULATES this request's counters into the wp-option so that the
	 * heartbeat can consume window-delta values (hit_count, miss_count,
	 * ops, wait_ms) across multiple requests between heartbeat pushes.
	 * The heartbeat consumer reads the accumulated deltas and resets them
	 * (consume-and-reset pattern).
	 *
	 * The STATE SNAPSHOT fields (state, latency_ms, last_error_class,
	 * hit_ratio_window_pct, engine_version) are persisted UNCONDITIONALLY so
	 * the heartbeat always has a fresh snapshot to report, even when analytics
	 * is disabled. The delta accumulation fields are gated on analytics_enabled.
	 * Missing analytics_enabled is treated as ON (matching the m68 default).
	 *
	 * @return void
	 */
	private function persistStats(): void
	{
		if ( ! function_exists( 'update_option' ) || ! function_exists( 'get_option' ) ) {
			return;
		}

		// Compute per-request snapshot fields (state, latency, last error).
		// These are written unconditionally so the heartbeat can always read
		// a fresh live snapshot, independent of the analytics setting.
		$snapshot = $this->getHeartbeatStats();

		// Read the existing accumulated option (default empty array).
		$existing = get_option( 'wpmgr_object_cache_stats', [] );
		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		// Analytics-gated: accumulate delta counters only when analytics is on.
		// Missing analytics_enabled is treated as ON (the default).
		$analyticsOn = ! isset( $this->config['analytics_enabled'] ) || (bool) $this->config['analytics_enabled'];

		if ( $analyticsOn ) {
			// Accumulate cumulative delta counters into the stored option.
			// These are consumed-and-reset by ObjectCacheHeartbeat::build().
			$totalOps = $this->redisReads + $this->redisWrites;

			$merged = array_merge( $snapshot, [
				// Carry forward any unconsumed deltas from prior requests.
				'delta_hit_count'   => ( isset( $existing['delta_hit_count'] ) ? (int) $existing['delta_hit_count'] : 0 )
					+ $this->cache_hits,
				'delta_miss_count'  => ( isset( $existing['delta_miss_count'] ) ? (int) $existing['delta_miss_count'] : 0 )
					+ $this->cache_misses,
				'delta_ops'         => ( isset( $existing['delta_ops'] ) ? (int) $existing['delta_ops'] : 0 )
					+ $totalOps,
				'delta_wait_ms'     => ( isset( $existing['delta_wait_ms'] ) ? (float) $existing['delta_wait_ms'] : 0.0 )
					+ $this->totalWaitMs,
				'delta_sample_count' => ( isset( $existing['delta_sample_count'] ) ? (int) $existing['delta_sample_count'] : 0 )
					+ ( $totalOps > 0 ? 1 : 0 ),
				// Timestamp of the first un-consumed delta (for ops_per_sec calculation).
				'delta_since_ts'    => isset( $existing['delta_since_ts'] ) && (float) $existing['delta_since_ts'] > 0
					? (float) $existing['delta_since_ts']
					: microtime( true ),
			] );
		} else {
			// Analytics off: persist only the state snapshot; preserve any
			// existing unconsumed delta fields so they are not silently lost.
			$merged = array_merge( $existing, $snapshot );
		}

		update_option( 'wpmgr_object_cache_stats', $merged, false );
	}

	// -------------------------------------------------------------------------
	// Internal: error journal
	// -------------------------------------------------------------------------

	/**
	 * Add an entry to the per-request error journal.
	 *
	 * @param string $class   Error class name.
	 * @param string $message Error message.
	 * @return void
	 */
	private function journalError( string $class, string $message ): void
	{
		if ( count( $this->errorJournal ) >= self::MAX_JOURNAL ) {
			array_shift( $this->errorJournal );
		}
		$this->errorJournal[] = $class;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic
			error_log( 'WPMgr Object Cache error [' . $class . ']: ' . $message );
		}
	}
}
