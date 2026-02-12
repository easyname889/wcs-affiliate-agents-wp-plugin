<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

// Constants
define('DAY_IN_SECONDS', 86400);
define('COOKIEPATH', '/');
define('SITECOOKIEPATH', '/');
define('COOKIE_DOMAIN', '');
define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');
define('OBJECT', 'OBJECT');

// Global Hooks Registry
$GLOBALS['wp_hooks'] = [];
$GLOBALS['wp_actions'] = [];

// Mock Functions
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['wp_hooks'][$hook][] = [
        'callback' => $callback,
        'priority' => $priority,
        'accepted_args' => $accepted_args
    ];
}

function do_action($hook, ...$args) {
    $GLOBALS['wp_actions'][] = $hook;
    if (isset($GLOBALS['wp_hooks'][$hook])) {
        // Sort by priority if needed, but for now simple iteration
        foreach ($GLOBALS['wp_hooks'][$hook] as $cb) {
            call_user_func_array($cb['callback'], $args);
        }
    }
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    add_action($hook, $callback, $priority, $accepted_args);
}

function apply_filters($tag, $value, ...$args) {
    return $value;
}

function add_shortcode($tag, $func) {}

function is_admin() { return isset($GLOBALS['is_admin']) ? $GLOBALS['is_admin'] : false; }
// headers_sent is built-in
function is_ssl() { return false; }
function home_url($path = '') { return 'http://example.com' . $path; }
function admin_url($path = '') { return 'http://example.com/wp-admin/' . $path; }

function wp_unslash($val) { return $val; }
function sanitize_text_field($str) { return trim(strip_tags($str)); }
function sanitize_email($email) { return filter_var($email, FILTER_SANITIZE_EMAIL); }
function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_-]/', '', $key)); }
function sanitize_user($user) { return sanitize_key($user); }
function esc_attr($s) { return htmlspecialchars($s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES); }
function esc_html_e($s) { echo esc_html($s); }
function esc_html__($s, $d = '') { return htmlspecialchars($s, ENT_QUOTES); }
function esc_url($s) { return $s; }
function esc_js($s) { return json_encode($s); }
function __($s, $d) { return $s; }
function selected($selected, $current = true, $echo = true) {
    if ($selected === $current) {
        if ($echo) echo ' selected="selected"';
        return ' selected="selected"';
    }
    return '';
}
function checked($checked, $current = true, $echo = true) {
    if ($checked === $current) {
        if ($echo) echo ' checked="checked"';
        return ' checked="checked"';
    }
    return '';
}

function add_query_arg($args, $url) {
    $query = http_build_query($args);
    if (strpos($url, '?') !== false) {
        return $url . '&' . $query;
    }
    return $url . '?' . $query;
}
// rawurlencode is built-in

// Options
$GLOBALS['wp_options'] = [];
function get_option($key, $default = false) {
    return isset($GLOBALS['wp_options'][$key]) ? $GLOBALS['wp_options'][$key] : $default;
}
function add_option($key, $value) {
    $GLOBALS['wp_options'][$key] = $value;
}
function update_option($key, $value) {
    $GLOBALS['wp_options'][$key] = $value;
}
function register_setting() {}
function add_settings_section() {}
function add_settings_field() {}
function add_menu_page() {}
function add_submenu_page() {}

// Roles & Users
function add_role() {}
function get_user_by($field, $value) { return false; } // Default: user not found
function username_exists($user) { return false; }
function wp_generate_password() { return 'mockpass'; }
function wp_create_user($user, $pass, $email) { return 123; } // Mock ID
function wp_update_user($args) {}
function current_user_can($cap) { return true; }

// DB
class MockWPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;

    public $tables = []; // 'table_name' => [rows]
    public $mock_get_row_result = null;
    public $mock_get_var_result = null;
    public $mock_get_col_result = null;
    public $mock_get_results_result = null;

    public $queries = [];

    public function prepare($query, ...$args) {
        // Simple replace for common placeholders
        foreach ($args as $arg) {
            $query = preg_replace('/%[sdf]/', "'$arg'", $query, 1);
        }
        return $query;
    }

    public function get_row($query, $output_type = OBJECT) {
        $this->queries[] = $query;
        if ($this->mock_get_row_result !== null) {
            return $this->mock_get_row_result;
        }
        return null;
    }

    public function get_var($query) {
        $this->queries[] = $query;
        if ($this->mock_get_var_result !== null) {
            return $this->mock_get_var_result;
        }
        return null;
    }

    public function get_col($query) {
        $this->queries[] = $query;
        if ($this->mock_get_col_result !== null) {
            return $this->mock_get_col_result;
        }
        return [];
    }

    public function query($query) {
        $this->queries[] = $query;
        return true;
    }

    public function insert($table, $data, $format = null) {
        $this->queries[] = "INSERT INTO $table ...";
        if (!isset($this->tables[$table])) {
            $this->tables[$table] = [];
        }
        $data['id'] = count($this->tables[$table]) + 1;
        $this->insert_id = $data['id'];
        $this->tables[$table][] = $data;
        return 1; // 1 row affected
    }

    public function update($table, $data, $where, $format = null, $where_format = null) {
        $this->queries[] = "UPDATE $table ...";
        // Simple in-memory update if possible
        if (isset($this->tables[$table])) {
            foreach ($this->tables[$table] as &$row) {
                // Check if row matches 'where'
                $match = true;
                foreach ($where as $wk => $wv) {
                    if (!isset($row[$wk]) || $row[$wk] != $wv) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    foreach ($data as $dk => $dv) {
                        $row[$dk] = $dv;
                    }
                }
            }
        }
        return true;
    }

    public function get_results($query, $type = OBJECT) {
        $this->queries[] = $query;
        if ($this->mock_get_results_result !== null) {
            return $this->mock_get_results_result;
        }
        // Return contents of table if query matches select *
        // Very basic parsing
        if (preg_match("/SELECT \* FROM ([\w_]+)/", $query, $matches)) {
            $table = $matches[1];
            $rows = $this->tables[$table] ?? [];

            // Basic WHERE filtering
            if (preg_match("/WHERE (.*)/", $query, $where_matches)) {
                $where_clause = $where_matches[1];
                // Simple parsing for AND conditions
                $conditions = explode(' AND ', $where_clause);
                $rows = array_filter($rows, function($row) use ($conditions) {
                    foreach ($conditions as $cond) {
                        // Handle order_id = 123
                        if (preg_match("/([\w_]+)\s*=\s*(\d+)/", $cond, $m)) {
                             if (($row[$m[1]] ?? '') != $m[2]) return false;
                        }
                        // Handle status = 'paid'
                        elseif (preg_match("/([\w_]+)\s*=\s*'([^']+)'/", $cond, $m)) {
                             if (($row[$m[1]] ?? '') != $m[2]) return false;
                        }
                        // Handle status IN ('pending','exported') - very crude
                         elseif (preg_match("/([\w_]+)\s*IN\s*\(([^)]+)\)/", $cond, $m)) {
                             $val = $row[$m[1]] ?? '';
                             $options = array_map(function($s){ return trim($s, "' "); }, explode(',', $m[2]));
                             if (!in_array($val, $options)) return false;
                         }
                    }
                    return true;
                });
            }
            return array_values($rows);
        }
        return [];
    }

    public function delete($table, $where, $where_format = null) {
         $this->queries[] = "DELETE FROM $table ...";
         return true;
    }

    public function get_charset_collate() { return ''; }

    public function esc_like($text) { return $text; }
}

global $wpdb;
$wpdb = new MockWPDB();

function dbDelta($sql) {}

function register_activation_hook($file, $cb) {}
function current_time($type) { return date('Y-m-d H:i:s'); }
function is_wp_error($thing) { return false; }
function check_admin_referer() { return true; }
function wp_nonce_field() {}
function wp_nonce_url($url) { return $url; }
function wp_die($msg = '', $title = '', $args = []) {
    throw new Exception("WP_DIE: " . $msg);
}
function wp_redirect($location, $status = 302) {
    throw new Exception("WP_REDIRECT: " . $location);
}

// WooCommerce Mocks
class WC_Order {
    public $id;
    public $meta = [];
    public $status = 'pending';

    public function __construct($id) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function update_meta_data($key, $val) { $this->meta[$key] = $val; }
    public function get_meta($key) { return $this->meta[$key] ?? null; }
    public function get_total() { return 100.00; }
    public function get_shipping_total() { return 0; }
    public function get_shipping_tax() { return 0; }
    public function get_items($type = '') {
        // return one item for commission base calc
        $item = new class {
            public function get_subtotal() { return 100.00; }
        };
        return [$item];
    }
    public function get_currency() { return 'USD'; }
    public function has_status($status) { return in_array($this->status, (array)$status); }
    public function get_status() { return $this->status; }
    public function add_order_note() {}
}

function wc_get_order($id) {
    global $mock_orders;
    return $mock_orders[$id] ?? false;
}

// Load plugin
require_once __DIR__ . '/../wcs-affiliate-agents.php';
