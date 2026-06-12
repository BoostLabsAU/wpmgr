<?php
/**
 * HeartbeatCatchup: shutdown-hook catch-up sender for the agent heartbeat.
 *
 * Problem: the agent heartbeat rides WP-Cron, which only fires when WordPress
 * boots. On fully page-cached idle sites WordPress never boots, so heartbeats
 * stop and the control plane eventually marks the site as disconnected — a false
 * positive. The control plane gains an active-verify dial (the ping command), but
 * a safety net is also needed at the agent side.
 *
 * This class hooks onto 'shutdown' (priority 9999, late) and sends one heartbeat
 * when ALL of the following hold:
 *   1. The agent is enrolled.
 *   2. wp_remote_post is available (we are in a full WP context).
 *   3. The last heartbeat is more than 120 s overdue per the wpmgr_last_heartbeat_at
 *      option (a missing option counts as overdue).
 *   4. A per-process stampede-throttle lock is acquired: the option
 *      wpmgr_hb_catchup_lock stores a Unix timestamp; if its stored value is
 *      within the last 60 s this process skips. Otherwise this process writes
 *      time() FIRST (accept the benign race; the CP dedupes heartbeats trivially)
 *      then proceeds.
 *   5. The current request is not an uninstall or deactivation lifecycle hook
 *      (checked via WP_UNINSTALL_PLUGIN and the $_REQUEST pagenow/action pair).
 *
 * The HTTP timeout is capped at 5 s so a slow or unreachable CP cannot hold the
 * FPM worker long after output has been sent.
 *
 * @package WPMgr\Agent
 */

declare(strict_types=1);

namespace WPMgr\Agent;

use WPMgr\Agent\Commands\PingCommand;

/**
 * Shutdown-hook catch-up sender for the heartbeat.
 */
final class HeartbeatCatchup
{
    /**
     * Option key for the stampede-throttle lock. Stores a Unix timestamp.
     * Non-autoloaded: read only on the shutdown path, never on page load.
     */
    public const OPTION_LOCK = 'wpmgr_hb_catchup_lock';

    /**
     * Minimum seconds overdue before a catch-up heartbeat is sent.
     * Set to 120 s (two missed heartbeat intervals) to allow a single miss
     * without triggering a catch-up on every page load of a slightly-slow site.
     */
    private const OVERDUE_THRESHOLD = 120;

    /**
     * Lock validity window in seconds. Within this window a second concurrent
     * process sees the lock as fresh and skips the send.
     */
    private const LOCK_WINDOW = 60;

    /**
     * Maximum HTTP timeout (seconds) for the catch-up heartbeat POST. Short
     * enough to not hold an FPM worker long after output.
     */
    private const SEND_TIMEOUT = 5;

    private Settings $settings;

    private Enrollment $enrollment;

    /**
     * @param Settings   $settings   Enrollment/config state.
     * @param Enrollment $enrollment Outbound heartbeat sender.
     */
    public function __construct(Settings $settings, Enrollment $enrollment)
    {
        $this->settings   = $settings;
        $this->enrollment = $enrollment;
    }

    /**
     * Register the shutdown hook. Bound in Plugin::registerHooks().
     *
     * @return void
     */
    public function register(): void
    {
        add_action('shutdown', [$this, 'maybeSend'], 9999);
    }

    /**
     * Shutdown callback: send a catch-up heartbeat when conditions are met.
     *
     * Public so WordPress can call it as a named hook callback (closure
     * capturing $this would be unsafe if the hook table is ever serialized by a
     * persistent object cache or a cron-inspector plugin).
     *
     * @return void
     */
    public function maybeSend(): void
    {
        // Guard 1: agent must be enrolled.
        if (!$this->settings->isEnrolled()) {
            return;
        }

        // Guard 2: wp_remote_post must be available (full WP context).
        if (!function_exists('wp_remote_post')) {
            return;
        }

        // Guard 3: skip during uninstall/deactivation lifecycle hooks.
        if ($this->isLifecycleRequest()) {
            return;
        }

        // Guard 4: heartbeat must be overdue by more than OVERDUE_THRESHOLD seconds.
        if (!$this->isOverdue()) {
            return;
        }

        // Guard 5: acquire the stampede-throttle lock.
        if (!$this->acquireLock()) {
            return;
        }

        // Send a catch-up heartbeat with a short timeout so a slow CP can never
        // hold the FPM worker long after output. Reuse Enrollment::sendHeartbeat
        // so the signed transport and the ledger update run in one call.
        try {
            $this->enrollment->sendHeartbeat(self::SEND_TIMEOUT);
        } catch (\Throwable $e) {
            // Best-effort: a catch-up failure must never fatal the shutdown hook.
        }
    }

    /**
     * Whether the last heartbeat timestamp is absent or overdue by more than
     * OVERDUE_THRESHOLD seconds.
     *
     * @return bool
     */
    private function isOverdue(): bool
    {
        if (!function_exists('get_option')) {
            // Can't read the ledger — treat as overdue (conservative).
            return true;
        }

        $stored = get_option(PingCommand::OPTION_LAST_HEARTBEAT_AT, false);
        if ($stored === false || !is_numeric($stored)) {
            // Option never written — no heartbeat has ever succeeded.
            return true;
        }

        $elapsed = time() - (int) $stored;

        return $elapsed > self::OVERDUE_THRESHOLD;
    }

    /**
     * Acquire the stampede-throttle lock. Reads the stored lock timestamp;
     * if it is within LOCK_WINDOW seconds, returns false (another process has
     * the lock). Otherwise writes the current timestamp and returns true.
     *
     * The benign race (two processes read stale simultaneously and both write)
     * results in at most two catch-up heartbeats reaching the CP; the CP
     * dedupes heartbeats trivially by last-seen timestamp.
     *
     * @return bool True when the lock was acquired.
     */
    private function acquireLock(): bool
    {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return false;
        }

        $stored = get_option(self::OPTION_LOCK, false);
        if ($stored !== false && is_numeric($stored)) {
            $age = time() - (int) $stored;
            if ($age < self::LOCK_WINDOW) {
                return false; // Lock is fresh; skip.
            }
        }

        // Write the lock FIRST before sending.
        update_option(self::OPTION_LOCK, time(), false);

        return true;
    }

    /**
     * Whether the current request is a WP lifecycle hook (uninstall / deactivation).
     * We must not send a heartbeat during these paths because Enrollment may be
     * in the middle of a signed last-will disconnect.
     *
     * @return bool
     */
    private function isLifecycleRequest(): bool
    {
        // Uninstall path: WordPress defines this constant before requiring
        // uninstall.php. The guard is defined in our own uninstall.php too.
        if (defined('WP_UNINSTALL_PLUGIN')) {
            return true;
        }

        // Deactivation path: recognise the admin AJAX/POST that calls the
        // deactivate hook. This is a best-effort check; false negatives are
        // acceptable (a spurious catch-up during deactivation is harmless).
        if (defined('DOING_AJAX') && DOING_AJAX) {
            $action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['action'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only heuristic; no state change on this value
            if ($action === 'deactivate-plugin' || $action === 'update-plugin') {
                return true;
            }
        }

        return false;
    }
}
