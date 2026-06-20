<?php
/**
 * HardeningConfig — value object for the security-hardening config pushed by
 * the control plane via sync_security_hardening.
 *
 * Every toggle defaults to OFF (false / 'on' / 'default' / 'both') so that a
 * fresh push with missing fields never activates anything the operator did not
 * explicitly choose. Missing toggles are treated as "off" for forward-compat.
 *
 * Wire contract (CP -> agent):
 *   POST /wp-json/wpmgr/v1/command/sync_security_hardening
 *   Body: {
 *     "config": {
 *       "disable_file_editor":        bool,
 *       "xmlrpc_mode":                "on"|"off"|"limited",
 *       "restrict_rest_api":          "default"|"restricted",
 *       "restrict_login_identifier":  "username"|"email"|"both",
 *       "force_unique_nickname":      bool,
 *       "disable_author_archive_enum": bool,
 *       "force_ssl":                  bool,
 *       "disable_directory_browsing": bool,
 *       "disable_php_in_uploads":     bool,
 *       "protect_system_files":       bool
 *     },
 *     "bans": [
 *       {"id":"<uuid>","type":"ip","value":"1.2.3.4","comment":"..."},
 *       {"id":"<uuid>","type":"range","value":"10.0.0.0/8","comment":"..."},
 *       {"id":"<uuid>","type":"user_agent","value":"badbot/1.0"}
 *     ]
 *   }
 *
 * @package WPMgr\Agent\Security
 */

declare(strict_types=1);

namespace WPMgr\Agent\Security;

/**
 * Immutable, validated value object for hardening config + ban list.
 */
final class HardeningConfig
{
    public const XMLRPC_ON      = 'on';
    public const XMLRPC_OFF     = 'off';
    public const XMLRPC_LIMITED = 'limited';

    public const REST_DEFAULT    = 'default';
    public const REST_RESTRICTED = 'restricted';

    public const LOGIN_BOTH     = 'both';
    public const LOGIN_USERNAME = 'username';
    public const LOGIN_EMAIL    = 'email';

    public const BAN_TYPE_IP         = 'ip';
    public const BAN_TYPE_RANGE      = 'range';
    public const BAN_TYPE_USER_AGENT = 'user_agent';

    /** Toggle: add DISALLOW_FILE_EDIT to wp-config. */
    public readonly bool $disableFileEditor;

    /** One of: on|off|limited. */
    public readonly string $xmlrpcMode;

    /** One of: default|restricted. */
    public readonly string $restrictRestApi;

    /** One of: both|username|email. */
    public readonly string $restrictLoginIdentifier;

    /** Toggle: force nickname != user_login. */
    public readonly bool $forceUniqueNickname;

    /** Toggle: 404 ?author=N redirects + hide users from anon REST. */
    public readonly bool $disableAuthorArchiveEnum;

    /** Toggle: server-config http->https redirect + HSTS. */
    public readonly bool $forceSsl;

    /** Toggle: Options -Indexes in server config. */
    public readonly bool $disableDirectoryBrowsing;

    /** Toggle: block PHP execution in uploads dir. */
    public readonly bool $disablePhpInUploads;

    /** Toggle: block web access to wp-config.php, .htaccess, etc. */
    public readonly bool $protectSystemFiles;

    /**
     * Validated ban list. Each entry has:
     *   id:      string (uuid)
     *   type:    "ip"|"range"|"user_agent"
     *   value:   string
     *   comment: string (optional)
     *
     * @var array<int,array{id:string,type:string,value:string,comment:string}>
     */
    public readonly array $bans;

    /**
     * @param bool   $disableFileEditor
     * @param string $xmlrpcMode
     * @param string $restrictRestApi
     * @param string $restrictLoginIdentifier
     * @param bool   $forceUniqueNickname
     * @param bool   $disableAuthorArchiveEnum
     * @param bool   $forceSsl
     * @param bool   $disableDirectoryBrowsing
     * @param bool   $disablePhpInUploads
     * @param bool   $protectSystemFiles
     * @param array<int,array{id:string,type:string,value:string,comment:string}> $bans
     */
    public function __construct(
        bool $disableFileEditor,
        string $xmlrpcMode,
        string $restrictRestApi,
        string $restrictLoginIdentifier,
        bool $forceUniqueNickname,
        bool $disableAuthorArchiveEnum,
        bool $forceSsl,
        bool $disableDirectoryBrowsing,
        bool $disablePhpInUploads,
        bool $protectSystemFiles,
        array $bans
    ) {
        $this->disableFileEditor        = $disableFileEditor;
        $this->xmlrpcMode               = $xmlrpcMode;
        $this->restrictRestApi          = $restrictRestApi;
        $this->restrictLoginIdentifier  = $restrictLoginIdentifier;
        $this->forceUniqueNickname      = $forceUniqueNickname;
        $this->disableAuthorArchiveEnum = $disableAuthorArchiveEnum;
        $this->forceSsl                 = $forceSsl;
        $this->disableDirectoryBrowsing = $disableDirectoryBrowsing;
        $this->disablePhpInUploads      = $disablePhpInUploads;
        $this->protectSystemFiles       = $protectSystemFiles;
        $this->bans                     = $bans;
    }

    /**
     * Build and validate a HardeningConfig from a raw decoded JSON payload
     * (the 'config' + 'bans' keys of the wire contract). Missing/invalid fields
     * fall back to safe off-defaults rather than throwing, so a malformed push
     * can never brick the agent.
     *
     * @param array<string,mixed> $raw Top-level decoded JSON body.
     * @return self
     */
    public static function fromArray(array $raw): self
    {
        $cfg  = isset($raw['config']) && is_array($raw['config']) ? $raw['config'] : [];
        $bans = isset($raw['bans'])   && is_array($raw['bans'])   ? $raw['bans']   : [];

        $xmlrpcMode = self::coerceEnum(
            $cfg['xmlrpc_mode'] ?? 'on',
            [self::XMLRPC_ON, self::XMLRPC_OFF, self::XMLRPC_LIMITED],
            self::XMLRPC_ON
        );

        $restrictRestApi = self::coerceEnum(
            $cfg['restrict_rest_api'] ?? 'default',
            [self::REST_DEFAULT, self::REST_RESTRICTED],
            self::REST_DEFAULT
        );

        $restrictLogin = self::coerceEnum(
            $cfg['restrict_login_identifier'] ?? 'both',
            [self::LOGIN_BOTH, self::LOGIN_USERNAME, self::LOGIN_EMAIL],
            self::LOGIN_BOTH
        );

        $validatedBans = self::validateBans($bans);

        return new self(
            (bool) ($cfg['disable_file_editor']         ?? false),
            $xmlrpcMode,
            $restrictRestApi,
            $restrictLogin,
            (bool) ($cfg['force_unique_nickname']        ?? false),
            (bool) ($cfg['disable_author_archive_enum']  ?? false),
            (bool) ($cfg['force_ssl']                    ?? false),
            (bool) ($cfg['disable_directory_browsing']   ?? false),
            (bool) ($cfg['disable_php_in_uploads']       ?? false),
            (bool) ($cfg['protect_system_files']         ?? false),
            $validatedBans
        );
    }

    /**
     * Serialize to a compact array for wp-options storage.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'config' => [
                'disable_file_editor'        => $this->disableFileEditor,
                'xmlrpc_mode'                => $this->xmlrpcMode,
                'restrict_rest_api'          => $this->restrictRestApi,
                'restrict_login_identifier'  => $this->restrictLoginIdentifier,
                'force_unique_nickname'      => $this->forceUniqueNickname,
                'disable_author_archive_enum' => $this->disableAuthorArchiveEnum,
                'force_ssl'                  => $this->forceSsl,
                'disable_directory_browsing' => $this->disableDirectoryBrowsing,
                'disable_php_in_uploads'     => $this->disablePhpInUploads,
                'protect_system_files'       => $this->protectSystemFiles,
            ],
            'bans' => $this->bans,
        ];
    }

    /**
     * Load the stored config from wp-options, falling back to defaults if
     * the option is absent or malformed.
     *
     * @return self
     */
    public static function load(): self
    {
        if (!function_exists('get_option')) {
            return self::defaults();
        }

        $raw = get_option(HardeningModule::OPTION_CONFIG, '');
        if (!is_string($raw) || $raw === '') {
            return self::defaults();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::defaults();
        }

        return self::fromArray($decoded);
    }

    /**
     * Return the all-off default config (no toggles active, no bans).
     *
     * @return self
     */
    public static function defaults(): self
    {
        return new self(
            false,
            self::XMLRPC_ON,
            self::REST_DEFAULT,
            self::LOGIN_BOTH,
            false,
            false,
            false,
            false,
            false,
            false,
            []
        );
    }

    /**
     * Return only the IP/range bans (used by WAF early-gate integration).
     *
     * @return array<int,string>
     */
    public function ipRangeBans(): array
    {
        $out = [];
        foreach ($this->bans as $ban) {
            if (in_array($ban['type'], [self::BAN_TYPE_IP, self::BAN_TYPE_RANGE], true)) {
                $out[] = $ban['value'];
            }
        }
        return $out;
    }

    /**
     * Return only user-agent ban patterns.
     *
     * @return array<int,string>
     */
    public function userAgentBans(): array
    {
        $out = [];
        foreach ($this->bans as $ban) {
            if ($ban['type'] === self::BAN_TYPE_USER_AGENT) {
                $out[] = $ban['value'];
            }
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Coerce a value to one of an allowed set; use $default when invalid.
     *
     * @param mixed          $value   Candidate value.
     * @param array<string>  $allowed Allowed strings.
     * @param string         $default Safe default.
     * @return string
     */
    private static function coerceEnum(mixed $value, array $allowed, string $default): string
    {
        if (is_string($value) && in_array($value, $allowed, true)) {
            return $value;
        }
        return $default;
    }

    /**
     * Validate the raw bans array. Each entry must have id, type, value as
     * non-empty strings. Unknown types, empty values, or non-string fields are
     * silently dropped rather than causing an error.
     *
     * @param array<mixed> $rawBans
     * @return array<int,array{id:string,type:string,value:string,comment:string}>
     */
    private static function validateBans(array $rawBans): array
    {
        $valid = [];
        foreach ($rawBans as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id      = is_string($entry['id'] ?? null)    ? trim($entry['id'])    : '';
            $type    = is_string($entry['type'] ?? null)   ? trim($entry['type'])   : '';
            $value   = is_string($entry['value'] ?? null)  ? trim($entry['value'])  : '';
            $comment = is_string($entry['comment'] ?? null) ? trim($entry['comment']) : '';

            if ($id === '' || $value === '') {
                continue;
            }
            $allowedTypes = [self::BAN_TYPE_IP, self::BAN_TYPE_RANGE, self::BAN_TYPE_USER_AGENT];
            if (!in_array($type, $allowedTypes, true)) {
                continue;
            }

            // IP/range: validate the value is something inet_pton can parse
            // (for ip), or has /prefix notation (for range). Silently skip junk.
            if ($type === self::BAN_TYPE_IP) {
                if (filter_var($value, FILTER_VALIDATE_IP) === false) {
                    continue;
                }
            } elseif ($type === self::BAN_TYPE_RANGE) {
                $parts = explode('/', $value, 2);
                if (count($parts) !== 2
                    || filter_var($parts[0], FILTER_VALIDATE_IP) === false
                    || !ctype_digit($parts[1])
                ) {
                    continue;
                }
            }

            // BLOCKER 2: Drop any ban value that contains control characters
            // (CR, LF, NUL, or any char < 0x20). Such values would allow
            // injection of arbitrary Apache directives into the managed .htaccess
            // block because preg_quote() does NOT neutralise newlines.
            // Reject and drop — do not sanitize-and-keep.
            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                continue;
            }

            // user_agent: no structural validation beyond non-empty + above control-char check.

            $valid[] = [
                'id'      => $id,
                'type'    => $type,
                'value'   => $value,
                'comment' => $comment,
            ];
        }
        return $valid;
    }
}
