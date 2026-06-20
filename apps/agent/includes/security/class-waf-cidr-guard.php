<?php
/**
 * WafCidrGuard — shared CIDR safety helpers used by both ServerConfigWriter
 * (render-time) and HardeningModule::syncWafDenyCidrs() (persist-time).
 *
 * Centralising these guards in one place ensures that the .htaccess writer and
 * the WAF option writer always apply the same filtering rules, eliminating the
 * risk of them diverging after a future edit.
 *
 * Rules applied before a CIDR is accepted as a deny entry:
 *   1. isBroadAddress() — drops 0.0.0.0/0 / ::/0 and any IPv4 prefix < /8 or
 *      IPv6 prefix < /16. Such a rule would deny every request, including the
 *      signed command that would undo the ban.
 *   2. overlapsProtected() — drops CIDRs that overlap private/loopback ranges
 *      (RFC-1918, ::1, fc00::/7, fe80::/10, link-local) or any of the
 *      operator-configured allow_cidrs. The runtime WAF also enforces the
 *      private bypass, but the agent must not persist a dangerous CIDR even when
 *      the runtime guard would catch it (defence-in-depth).
 *
 * @package WPMgr\Agent\Security
 */

declare(strict_types=1);

namespace WPMgr\Agent\Security;

/**
 * Pure-static helpers for CIDR safety validation.
 */
final class WafCidrGuard
{
    /**
     * Private IPv4 ranges that must never appear in a deny rule.
     * Covers loopback, private LAN, link-local, and shared-address space.
     *
     * @var array<int,string>
     */
    private const PRIVATE_IPV4_RANGES = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        '100.64.0.0/10',
    ];

    /**
     * Private/loopback IPv6 ranges that must never appear in a deny rule.
     *
     * @var array<int,string>
     */
    private const PRIVATE_IPV6_RANGES = [
        '::1/128',
        'fc00::/7',
        'fe80::/10',
        '::ffff:0:0/96',
    ];

    /**
     * Return true when a CIDR is so broad that emitting it as a deny rule would
     * lock out every request (including the control plane).
     *
     * Drops:
     *   - 0.0.0.0/0 and ::/0 (all-address catch-alls)
     *   - IPv4 prefix < /8  (covering ≥ 16 M addresses)
     *   - IPv6 prefix < /16 (covering an enormous range)
     *
     * @param string $cidr CIDR notation, already trimmed.
     * @return bool
     */
    public static function isBroadAddress(string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }
        $base      = trim($parts[0]);
        $prefixStr = trim($parts[1]);
        if (!ctype_digit($prefixStr)) {
            return false;
        }
        $prefix = (int) $prefixStr;

        $isIpv4 = filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isIpv6 = filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if ($isIpv4) {
            // /0 through /7 covers ≥ 16 M addresses; /0 is the all-address wildcard.
            return $prefix < 8;
        }

        if ($isIpv6) {
            // /0 through /15 covers a vast range; /0 is the all-address wildcard.
            return $prefix < 16;
        }

        return false;
    }

    /**
     * Return true when a CIDR overlaps any private/loopback range or any of the
     * operator-configured allow_cidrs. Such a deny rule could lock out the
     * operator's own LAN or the control-plane egress IP.
     *
     * The check is conservative: if $cidr's base address falls inside a protected
     * range, or a protected range's base address falls inside $cidr, we skip the
     * deny rule. Full subnet-overlap arithmetic is not needed here — a simple
     * "does either contain the other's base address" heuristic is sufficient and
     * safe (false positives mean we skip a deny rule, not that we emit a wrong one).
     *
     * @param string            $cidr       The candidate deny CIDR.
     * @param array<int,string> $allowCidrs Operator-configured allow list.
     * @return bool
     */
    public static function overlapsProtected(string $cidr, array $allowCidrs): bool
    {
        $protected = array_merge(self::PRIVATE_IPV4_RANGES, self::PRIVATE_IPV6_RANGES, $allowCidrs);
        foreach ($protected as $guard) {
            if (self::cidrsOverlap($cidr, $guard)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return true when a CIDR should be dropped before being stored or emitted as
     * a deny rule. Combines the broad-address and overlaps-protected checks.
     *
     * @param string            $cidr       Candidate CIDR, already trimmed.
     * @param array<int,string> $allowCidrs Operator allow-list (from waf config).
     * @return bool True → drop this CIDR; false → safe to use.
     */
    public static function isUnsafe(string $cidr, array $allowCidrs = []): bool
    {
        if ($cidr === '') {
            return true;
        }
        if (self::isBroadAddress($cidr)) {
            return true;
        }
        if (self::overlapsProtected($cidr, $allowCidrs)) {
            return true;
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Private overlap helpers
    // -------------------------------------------------------------------------

    /**
     * Conservative CIDR overlap check. Returns true when either network's base
     * address falls within the other network.
     *
     * @param string $a First CIDR.
     * @param string $b Second CIDR.
     * @return bool
     */
    private static function cidrsOverlap(string $a, string $b): bool
    {
        return self::cidrContainsBase($a, $b) || self::cidrContainsBase($b, $a);
    }

    /**
     * Return true when $cidr contains the base address of $other.
     *
     * @param string $cidr  The network to test against.
     * @param string $other The network whose base address is the probe.
     * @return bool
     */
    private static function cidrContainsBase(string $cidr, string $other): bool
    {
        $parts = explode('/', $other, 2);
        if (count($parts) < 1) {
            return false;
        }
        $baseIp = trim($parts[0]);
        return self::cidrMatchesIp($cidr, $baseIp);
    }

    /**
     * Return true when $ip falls within $cidr. Pure PHP bit-comparison,
     * no external dependencies.
     *
     * @param string $cidr CIDR notation.
     * @param string $ip   IP address string (IPv4 or IPv6).
     * @return bool
     */
    private static function cidrMatchesIp(string $cidr, string $ip): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$base, $prefixStr] = $parts;

        if (!ctype_digit(trim($prefixStr))) {
            return false;
        }
        $prefix = (int) $prefixStr;

        $ipBin   = @inet_pton(trim($ip));
        $baseBin = @inet_pton(trim($base));

        if ($ipBin === false || $baseBin === false || strlen($ipBin) !== strlen($baseBin)) {
            return false;
        }

        $addrLen   = strlen($ipBin);
        $maxPrefix = $addrLen * 8;

        if ($prefix < 0 || $prefix > $maxPrefix) {
            return false;
        }

        if ($prefix === 0) {
            return true; // /0 matches everything
        }

        $fullBytes = intdiv($prefix, 8);
        $remainder = $prefix % 8;

        for ($i = 0; $i < $fullBytes; $i++) {
            if (ord($ipBin[$i]) !== ord($baseBin[$i])) {
                return false;
            }
        }

        if ($remainder > 0 && $fullBytes < $addrLen) {
            $mask = 0xFF & (0xFF << (8 - $remainder));
            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($baseBin[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
