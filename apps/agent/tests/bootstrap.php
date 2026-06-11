<?php
/**
 * PHPUnit bootstrap: load Composer autoload (which classmaps the plugin source
 * and pulls in Brain Monkey + Yoast Polyfills) and define the minimal set of
 * WordPress runtime classes the autologin tests rely on.
 *
 * @package WPMgr\Agent\Tests
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// Constants needed by the object-cache drop-in and engine files.
// ---------------------------------------------------------------------------
if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/wpmgr_wp_abspath/');
}
if (!defined('WPMGR_AGENT_DIR')) {
    define('WPMGR_AGENT_DIR', dirname(__DIR__));
}

// Bootstrap the object-cache engine class (global namespace, loaded via
// require_once; must come after ABSPATH is defined).
if (!class_exists('WPMgr_Object_Cache')) {
    require_once dirname(__DIR__) . '/includes/object-cache/class-object-cache-config.php';
    require_once dirname(__DIR__) . '/includes/object-cache/class-redis-connection.php';
    require_once dirname(__DIR__) . '/includes/object-cache/class-object-cache-engine.php';
}

// WP $wpdb result-format constants (used by PreloadQueue SELECTs). Real WP
// defines these in wp-db.php; declare them for the in-memory $wpdb doubles.
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

// ---------------------------------------------------------------------------
// Minimal WP runtime class doubles used by AutologinCommandTest.
//
// WordPress ships these as real classes, but at unit-test time we only need
// a tiny surface. Brain Monkey stubs FUNCTIONS, not classes, so we declare
// what we need here. Keep these intentionally small and dumb.
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Minimal WP global function stubs for tests that do NOT use Brain Monkey.
//
// Brain Monkey-based tests can override these via Functions\when() in setUp;
// when they do not, the real stub below is called. Non-Brain-Monkey tests
// (e.g. CacheWriterTest) rely on these directly.
// ---------------------------------------------------------------------------

if (!function_exists('wp_parse_url')) {
    /**
     * Thin wrapper around parse_url() — mirrors the real WP implementation.
     *
     * @param string   $url       The URL to parse.
     * @param int      $component Optional PHP_URL_* constant (-1 for full array).
     * @return mixed
     */
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('wp_mkdir_p')) {
    /**
     * Recursively creates directories — mirrors the real WP implementation.
     *
     * @param string $target Directory path to create.
     * @return bool True on success or if the directory already exists.
     */
    function wp_mkdir_p(string $target): bool
    {
        if (is_dir($target)) {
            return true;
        }
        return mkdir($target, 0755, true);
    }
}

if (!function_exists('wp_rand')) {
    /**
     * Returns a random integer — mirrors the real WP implementation.
     *
     * @param int $min Lower bound (inclusive).
     * @param int $max Upper bound (inclusive).
     * @return int
     */
    function wp_rand(int $min = 0, int $max = 0): int
    {
        if ($min === 0 && $max === 0) {
            $max = PHP_INT_MAX;
        }
        return random_int($min, $max);
    }
}

if (!function_exists('wp_unslash')) {
    /**
     * Removes slashes from a value — mirrors the real WP implementation
     * (stripslashes_deep) closely enough for the tests.
     *
     * @param mixed $value Value to unslash.
     * @return mixed
     */
    function wp_unslash(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('sanitize_text_field')) {
    /**
     * Sanitizes a string from user input — approximates the real WP
     * implementation (strip tags, collapse whitespace, trim).
     *
     * @param string $str Value to sanitize.
     * @return string
     */
    function sanitize_text_field(string $str): string
    {
        $filtered = strip_tags($str);
        $filtered = preg_replace('/[\r\n\t ]+/', ' ', $filtered) ?? '';
        return trim($filtered);
    }
}





if (!function_exists('sanitize_email')) {
    /**
     * Strips out all characters not allowed in an email address — mirrors the
     * real WP implementation closely enough for unit tests (real WP uses a
     * regex allow-list; here we validate with PHP's native filter, which is
     * sufficient for the addresses used in tests).
     *
     * @param string $email Candidate email address.
     * @return string The sanitized address, or '' if invalid.
     */
    function sanitize_email(string $email): string
    {
        $email = trim($email);
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }
}


if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string,string> */
        public array $errors = [];

        /** @var array<string,mixed> */
        public array $error_data = [];

        /**
         * @param string              $code    Error code.
         * @param string              $message Human message.
         * @param array<string,mixed> $data    Error data (status, etc).
         */
        public function __construct(string $code = '', string $message = '', array $data = [])
        {
            if ($code !== '') {
                $this->errors[$code] = $message;
                $this->error_data[$code] = $data;
            }
        }

        public function get_error_code(): string
        {
            $codes = array_keys($this->errors);
            return $codes === [] ? '' : (string) $codes[0];
        }

        public function get_error_message(?string $code = null): string
        {
            $code = $code ?? $this->get_error_code();
            return $this->errors[$code] ?? '';
        }

        /**
         * @return array<string,mixed>
         */
        public function get_error_data(?string $code = null): array
        {
            $code = $code ?? $this->get_error_code();
            $data = $this->error_data[$code] ?? [];
            return is_array($data) ? $data : [];
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string,mixed> */
        private array $params = [];

        /** @var array<string,string> */
        private array $headers = [];

        /**
         * @param array<string,mixed> $params Initial params.
         */
        public function __construct(array $params = [])
        {
            $this->params = $params;
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        public function set_param(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }

        public function get_header(string $key): string
        {
            return $this->headers[strtolower($key)] ?? '';
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /** @var mixed */
        public $data;

        public int $status;

        /** @var array<string,string> */
        public array $headers;

        /**
         * @param mixed                 $data    Response body.
         * @param int                   $status  HTTP status code.
         * @param array<string,string>  $headers Response headers.
         */
        public function __construct($data = null, int $status = 200, array $headers = [])
        {
            $this->data    = $data;
            $this->status  = $status;
            $this->headers = $headers;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        /**
         * @return array<string,string>
         */
        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}

if (!class_exists('WP_User')) {
    class WP_User
    {
        public int $ID = 0;

        public string $user_login = '';

        /** @var array<int,string> */
        public array $roles = [];
    }
}
