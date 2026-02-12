<?php
/**
 * Plugin Name: WCS Affiliate Agents
 * Description: Affiliate agent management for WooCommerce. Tracks sales per agent via short URLs like https://example.com/?UID, calculates commissions, provides an affiliate dashboard, and lets admin download QR codes and export payouts.
* Version: 0.1.7
 * Author: ChatGPT
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCS_Affiliate_Agents {

    const OPTION_KEY = 'wcs_affiliate_agents_options';
    const COOKIE_NAME = 'wcs_aff_uid';

    private static $instance = null;
    private $affiliates_table;
    private $commissions_table;
    private $options;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->affiliates_table   = $wpdb->prefix . 'wcs_affiliates';
        $this->commissions_table  = $wpdb->prefix . 'wcs_affiliate_commissions';
        $this->options            = $this->get_options();

        add_action('init', [$this, 'maybe_capture_affiliate_uid'], 1);
        add_action('init', [$this, 'register_shortcodes']);

        // Attach affiliate to order on checkout
        add_action('woocommerce_checkout_create_order', [$this, 'attach_order_affiliate'], 10, 2);

        // Create commission when order is completed
        add_action('woocommerce_order_status_completed', [$this, 'maybe_create_commission']);

        // Handle partial or full refunds
        add_action('woocommerce_order_refunded', [$this, 'handle_order_refund'], 10, 2);

        // Handle full cancellation or status changes as a catch-all
        add_action('woocommerce_order_status_refunded', [$this, 'maybe_void_commissions']);
        add_action('woocommerce_order_status_cancelled', [$this, 'maybe_void_commissions']);
        add_action('woocommerce_order_status_failed', [$this, 'maybe_void_commissions']);

        // Admin UI
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_admin_actions']);
        add_action('admin_init', [$this, 'handle_save_affiliate']);
        add_action('admin_init', [$this, 'handle_bulk_actions']);
        add_action('admin_init', [$this, 'maybe_update_schema']);

        // AJAX: download QR
        add_action('wp_ajax_wcs_download_affiliate_qr', [$this, 'ajax_download_affiliate_qr']);
        add_action('wp_ajax_wcs_download_affiliate_qr_zip', [$this, 'ajax_download_affiliate_qr_zip']);
    }

    public static function activate() {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $aff_table = $wpdb->prefix . 'wcs_affiliates';
        $sql1 = "CREATE TABLE {$aff_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            uid VARCHAR(64) NOT NULL,
            name VARCHAR(191) NOT NULL,
            email VARCHAR(191) NOT NULL,
            phone VARCHAR(64) DEFAULT '' ,
            nequi_phone VARCHAR(64) DEFAULT '' ,
            bank_name VARCHAR(191) DEFAULT '' ,
            bank_account_type VARCHAR(64) DEFAULT '' ,
            bank_account_number VARCHAR(191) DEFAULT '' ,
            commission_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            dashboard_mode VARCHAR(16) NOT NULL DEFAULT 'default',
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uid (uid),
            KEY user_id (user_id),
            KEY status (status)
        ) {$charset_collate};";

        $comm_table = $wpdb->prefix . 'wcs_affiliate_commissions';
        $sql2 = "CREATE TABLE {$comm_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            affiliate_id BIGINT UNSIGNED NOT NULL,
            uid VARCHAR(64) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            order_total DECIMAL(20,6) NOT NULL DEFAULT 0,
            commission_base DECIMAL(20,6) NOT NULL DEFAULT 0,
            commission_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
            commission_amount DECIMAL(20,6) NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            batch_id VARCHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            paid_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_aff (order_id, affiliate_id),
            KEY affiliate_id (affiliate_id),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta($sql1);
        dbDelta($sql2);

        // Default options
        $defaults = [
            'default_commission_percent' => 15,
            'cookie_days'                => 30,
            'commission_base'            => 'line_subtotal',
            'default_dashboard_mode'     => 'simple', // simple|advanced
            'allow_edit_payout'          => 1,
            'link_prefix'                => '',
            'enable_utm'                 => 1,
            'utm_source'                 => 'affiliate',
            'utm_medium'                 => 'qr',
            'utm_campaign'               => 'affiliate',
            'utm_include_uid_content'    => 0,
        ];
        $existing = get_option(self::OPTION_KEY);
        if (!is_array($existing)) {
            add_option(self::OPTION_KEY, $defaults, '', 'no');
        } else {
            $merged = array_merge($defaults, $existing);
            update_option(self::OPTION_KEY, $merged);
        }

        // Affiliate role
        add_role(
            'affiliate_agent',
            'Affiliate Agent',
            [
                'read' => true,
            ]
        );
    }

    private function get_options() {
        $defaults = [
            'default_commission_percent' => 15,
            'cookie_days'                => 30,
            'commission_base'            => 'line_subtotal',
            'default_dashboard_mode'     => 'simple',
            'allow_edit_payout'          => 1,
            'link_prefix'                => '',
            'enable_utm'                 => 1,
            'utm_source'                 => 'affiliate',
            'utm_medium'                 => 'qr',
            'utm_campaign'               => 'affiliate',
            'utm_include_uid_content'    => 0,
        ];
        $opts = get_option(self::OPTION_KEY, []);
        if (!is_array($opts)) {
            $opts = [];
        }
        return array_merge($defaults, $opts);
    }

    public function register_shortcodes() {
        add_shortcode('wcs_affiliate_dashboard', [$this, 'shortcode_affiliate_dashboard']);
    }

private function build_affiliate_query_key($uid) {
    $uid = trim((string) $uid);
    $prefix = isset($this->options['link_prefix']) ? trim((string) $this->options['link_prefix']) : '';
    if ($prefix === '') {
        return $uid;
    }
    return $prefix . '-' . $uid;
}

    private function build_referral_url($uid) {
        $ref_key = $this->build_affiliate_query_key($uid);
        // Keep the UID as a query-key (no "=") to stay as short as possible.
        $url = home_url('/?' . rawurlencode($ref_key));

        $o = $this->options ?: $this->get_options();
        if (!empty($o['enable_utm'])) {
            $utm = [];
            $src = trim((string) ($o['utm_source'] ?? ''));
            $med = trim((string) ($o['utm_medium'] ?? ''));
            $cam = trim((string) ($o['utm_campaign'] ?? ''));
            if ($src !== '') { $utm['utm_source'] = $src; }
            if ($med !== '') { $utm['utm_medium'] = $med; }
            if ($cam !== '') { $utm['utm_campaign'] = $cam; }
            if (!empty($o['utm_include_uid_content'])) {
                $utm['utm_content'] = $uid;
            }
            if (!empty($utm)) {
                $url = add_query_arg($utm, $url);
            }
        }

        return $url;
    }




    /**
     * Detect affiliate UID in query string (?UID) and set cookie.
     */
    public function maybe_capture_affiliate_uid() {
        if (is_admin()) {
            return;
        }
        if (headers_sent()) {
            return;
        }
        if (empty($_GET)) {
            return;
        }

        global $wpdb;

        $prefix = isset($this->options['link_prefix']) ? trim((string) $this->options['link_prefix']) : '';

        foreach (array_keys($_GET) as $key) {
            $skip_keys = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','utm_id','gclid','fbclid','msclkid','ttclid','gbraid','wbraid'];
            $key = sanitize_text_field($key);
            if ($key === '' || in_array($key, $skip_keys, true) || strpos($key, 'utm_') === 0) {
                continue;
            }
            if ($key === '' || strlen($key) < 4 || strlen($key) > 128) {
                continue;
            }

            $uid_candidate = $key;
            if ($prefix !== '') {
                $prefix_with_dash = $prefix . '-';
                if (strpos($key, $prefix_with_dash) === 0) {
                    $uid_candidate = substr($key, strlen($prefix_with_dash));
                    if ($uid_candidate === '') {
                        continue;
                    }
                }
            }

            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, uid, status FROM {$this->affiliates_table} WHERE uid = %s LIMIT 1",
                    $uid_candidate
                ),
                ARRAY_A
            );
            if (!$row || $row['status'] !== 'active') {
                continue;
            }

            $days   = max(1, (int) $this->options['cookie_days']);
            $expire = time() + $days * DAY_IN_SECONDS;

            setcookie(self::COOKIE_NAME, $row['uid'], $expire, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true);
            if (COOKIEPATH !== SITECOOKIEPATH) {
                setcookie(self::COOKIE_NAME, $row['uid'], $expire, SITECOOKIEPATH, COOKIE_DOMAIN ?: '', is_ssl(), true);
            }
            // Once a valid UID is found and cookie set, stop.
            return;
        }
    }

public function attach_order_affiliate($order, $data) {
        if (!isset($_COOKIE[self::COOKIE_NAME])) {
            return;
        }
        $uid = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME]));
        if ($uid === '') {
            return;
        }

        global $wpdb;
        $aff = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, uid, status FROM {$this->affiliates_table} WHERE uid = %s LIMIT 1",
                $uid
            ),
            ARRAY_A
        );
        if (!$aff || $aff['status'] !== 'active') {
            return;
        }

        $order->update_meta_data('_wcs_affiliate_uid', $aff['uid']);
        $order->update_meta_data('_wcs_affiliate_id', (int) $aff['id']);
    }

    public function maybe_create_commission($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $uid = $order->get_meta('_wcs_affiliate_uid');
        $aff_id = (int) $order->get_meta('_wcs_affiliate_id');
        if (!$uid || !$aff_id) {
            return;
        }

        global $wpdb;
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->commissions_table} WHERE order_id = %d AND affiliate_id = %d",
                $order_id,
                $aff_id
            )
        );
        if ($existing) {
            return;
        }

        $aff = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, commission_percent, status FROM {$this->affiliates_table} WHERE id = %d LIMIT 1",
                $aff_id
            ),
            ARRAY_A
        );
        if (!$aff || $aff['status'] !== 'active') {
            return;
        }

        $commission_percent = (float) $aff['commission_percent'];
        if ($commission_percent <= 0) {
            $commission_percent = (float) $this->options['default_commission_percent'];
        }

        $base = 0;
        $commission_base_mode = $this->options['commission_base'];

        if ($commission_base_mode === 'order_total_excl_shipping') {
            $total   = (float) $order->get_total();
            $shipping = (float) $order->get_shipping_total();
            $shipping_tax = (float) $order->get_shipping_tax();
            $base = max(0, $total - $shipping - $shipping_tax);
        } else {
            foreach ($order->get_items('line_item') as $item) {
                $base += (float) $item->get_subtotal();
            }
        }

        $base = round($base, 6);
        $commission_amount = round($base * $commission_percent / 100, 6);
        if ($commission_amount <= 0) {
            return;
        }

        $currency = $order->get_currency();
        $now = current_time('mysql');

        $inserted = $wpdb->insert($this->commissions_table,
            [
                'affiliate_id'       => $aff_id,
                'uid'                => $uid,
                'order_id'           => $order_id,
                'order_total'        => (float) $order->get_total(),
                'commission_base'    => $base,
                'commission_percent' => $commission_percent,
                'commission_amount'  => $commission_amount,
                'currency'           => $currency,
                'status'             => 'pending',
                'batch_id'           => null,
                'created_at'         => $now,
                'paid_at'            => null,
            ],
            [
                '%d','%s','%d','%f','%f','%f','%f','%s','%s','%s','%s','%s',
            ]
        );

        if ($inserted !== false) {
             $order->add_order_note(
                 sprintf(
                     /* translators: 1: amount, 2: currency, 3: uid */
                     __('Affiliate commission recorded: %s %s for agent %s.', 'wcs-affiliates'),
                     number_format($commission_amount, 2),
                     $currency,
                     $uid
                 ),
                 0, // is_customer_note
                 true // added_by_user (false=system)
             );
        }
    }

    
    public function handle_order_refund($order_id, $refund_id) {
        $order = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);

        if (!$order || !$refund) {
            return;
        }

        global $wpdb;

        // Get original commissions for this order.
        // We might have multiple affiliates if logic changed, but usually one.
        // We will loop through them.
        $original_commissions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->commissions_table}
                 WHERE order_id = %d AND commission_amount > 0 AND status != 'void'",
                $order_id
            ),
            ARRAY_A
        );

        if (empty($original_commissions)) {
            return;
        }

        // Calculate relevant totals based on commission base setting
        $commission_base_mode = $this->options['commission_base'] ?? 'line_subtotal';
        $refund_amount = (float) $refund->get_amount();
        $order_total   = (float) $order->get_total();

        // Adjust for shipping if excluded
        if ($commission_base_mode === 'order_total_excl_shipping' || $commission_base_mode === 'line_subtotal') {
            $refund_amount -= (float) $refund->get_shipping_total();
            $refund_amount -= (float) $refund->get_shipping_tax();

            $order_total   -= (float) $order->get_shipping_total();
            $order_total   -= (float) $order->get_shipping_tax();
        }

        if ($refund_amount < 0) {
             $refund_amount *= -1;
        }

        // If refund amount (net of shipping) is zero or less, no commission to deduct
        if ($refund_amount <= 0.000001) {
            return;
        }

        // Avoid division by zero
        if ($order_total <= 0) {
             $ratio = 1.0;
        } else {
             $ratio = $refund_amount / $order_total;
        }
        if ($ratio > 1.0) $ratio = 1.0;

        $now = current_time('mysql');

        foreach ($original_commissions as $comm) {
            // Deduct proportional amount
            $original_amount = (float) $comm['commission_amount'];
            $deduction = round($original_amount * $ratio, 6);

            if ($deduction <= 0) {
                continue;
            }

            $deduction_signed = -1 * $deduction;

            // Insert negative record
            $wpdb->insert($this->commissions_table,
                [
                    'affiliate_id'       => $comm['affiliate_id'],
                    'uid'                => $comm['uid'],
                    'order_id'           => $comm['order_id'],
                    'order_total'        => (float) $comm['order_total'], // Keep original context
                    'commission_base'    => 0, // Not strictly relevant for adjustment
                    'commission_percent' => (float) $comm['commission_percent'],
                    'commission_amount'  => $deduction_signed,
                    'currency'           => $comm['currency'],
                    'status'             => 'pending', // Pending deduction/adjustment
                    'batch_id'           => null,
                    'created_at'         => $now,
                    'paid_at'            => null,
                ],
                [
                    '%d','%s','%d','%f','%f','%f','%f','%s','%s','%s','%s','%s',
                ]
            );

            $order->add_order_note(
                sprintf(
                    __('Affiliate commission refunded: %s %s for agent %s (Refund #%d).', 'wcs-affiliates'),
                    number_format($deduction_signed, 2),
                    $comm['currency'],
                    $comm['uid'],
                    $refund_id
                ),
                0,
                true
            );
        }
    }

    public function maybe_void_commissions($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Only act on final negative outcomes
        if (!$order->has_status(['refunded', 'cancelled', 'failed'])) {
            return;
        }

        $this->void_commissions_for_order($order, 'order_' . $order->get_status());
    }

    private function void_commissions_for_order($order, $reason = '') {
        if (!($order instanceof WC_Order)) {
            return;
        }

        global $wpdb;
        $order_id = (int) $order->get_id();

        // 1. Get all commissions (positive and negative) for this order, grouped by affiliate
        // We want to see the NET balance.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT affiliate_id, uid, currency,
                        SUM(commission_amount) as net_balance,
                        GROUP_CONCAT(CASE WHEN status IN ('pending','exported') THEN id ELSE NULL END) as pending_ids
                 FROM {$this->commissions_table}
                 WHERE order_id = %d AND status != 'void'
                 GROUP BY affiliate_id, uid, currency",
                $order_id
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return;
        }

        $now = current_time('mysql');

        foreach ($rows as $row) {
            $net = (float) $row['net_balance'];
            if ($net <= 0.000001) {
                // Already zeroed out or negative
                continue;
            }

            // We have a positive balance to wipe out.
            // First, prefer to void pending commissions if possible.
            if (!empty($row['pending_ids'])) {
                $p_ids = array_map('intval', explode(',', $row['pending_ids']));
                if (!empty($p_ids)) {
                     // Check how much pending we have
                     $pending_sum = $wpdb->get_var(
                         $wpdb->prepare(
                             "SELECT SUM(commission_amount) FROM {$this->commissions_table} WHERE id IN (" . implode(',', $p_ids) . ")"
                         )
                     );

                     // If pending covers the net (or is the net), just void them.
                     // Actually, easiest is to just void all pending, and then re-check net.
                     // But simpler logic: Just void all pending for this order first.
                }
            }
        }

        // Simplified Logic:
        // 1. Void all pending/exported (unpaid) commissions (both positive AND negative).
        // This ensures we don't leave a negative "refund adjustment" pending if we are voiding the original positive commission.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->commissions_table} SET status = 'void'
                 WHERE order_id = %d AND status IN ('pending','exported')",
                $order_id
            )
        );

        // 2. Re-calculate net balance after voiding
        $rows_after = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT affiliate_id, uid, currency, order_total, commission_percent, SUM(commission_amount) as net_balance
                 FROM {$this->commissions_table}
                 WHERE order_id = %d AND status != 'void'
                 GROUP BY affiliate_id, uid, currency",
                $order_id
            ),
            ARRAY_A
        );

        foreach ($rows_after as $row) {
            $net = (float) $row['net_balance'];
            if ($net > 0.000001) {
                // Still positive (meaning some were 'paid'). Create negative adjustment.
                $refund_amount = -1 * $net;

                $wpdb->insert($this->commissions_table,
                    [
                        'affiliate_id'       => $row['affiliate_id'],
                        'uid'                => $row['uid'],
                        'order_id'           => $order_id,
                        'order_total'        => (float) $row['order_total'],
                        'commission_base'    => 0,
                        'commission_percent' => (float) $row['commission_percent'],
                        'commission_amount'  => $refund_amount,
                        'currency'           => $row['currency'],
                        'status'             => 'pending',
                        'batch_id'           => null,
                        'created_at'         => $now,
                        'paid_at'            => null,
                    ],
                    [
                        '%d','%s','%d','%f','%f','%f','%f','%s','%s','%s','%s','%s',
                    ]
                );

                $order->add_order_note(
                    sprintf(
                        __('Affiliate commission balance cleared (%s): %s %s for agent %s.', 'wcs-affiliates'),
                        'order_' . $order->get_status(),
                        number_format($refund_amount, 2),
                        $row['currency'],
                        $row['uid']
                    ),
                    0,
                    true
                );
            } else {
                 // Even if we just voided pending, verify we should log it
                 // If we voided something, the net dropped.
                 // We can rely on the fact that we ran the UPDATE above.
            }
        }

        // Add note if we voided pending but didn't need adjustment?
        // The UPDATE returns rows affected?
        // MockWPDB might not support rowCount.
        // Let's just log "Voided pending" if we find any voided rows?
        // Nah, the "balance cleared" message covers the paid case.
        // For the pending case, we might want a note.
        // But for now, this logic ensures the final balance is 0.
    }

    public function maybe_update_schema() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $db_version = get_option('wcs_affiliate_agents_db_version', '1.0');
        if (version_compare($db_version, '1.1', '<')) {
            global $wpdb;
            $table = $this->commissions_table;

            // Check if index exists
            $indices = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = 'order_aff'");
            if (!empty($indices)) {
                $wpdb->query("ALTER TABLE {$table} DROP INDEX order_aff");
                // Add a regular index on order_id for performance if not exists
                $order_idx = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = 'order_id'");
                if (empty($order_idx)) {
                     $wpdb->query("ALTER TABLE {$table} ADD INDEX order_id (order_id)");
                }
            }
            update_option('wcs_affiliate_agents_db_version', '1.1');
        }
    }

public function admin_menu() {
        add_menu_page(
            __('Affiliate Agents', 'wcs-affiliates'),
            __('Affiliate Agents', 'wcs-affiliates'),
            'manage_woocommerce',
            'wcs_affiliates',
            [$this, 'render_affiliates_page'],
            'dashicons-groups',
            56
        );

        add_submenu_page(
            'wcs_affiliates',
            __('Affiliate Agents', 'wcs-affiliates'),
            __('Agents', 'wcs-affiliates'),
            'manage_woocommerce',
            'wcs_affiliates',
            [$this, 'render_affiliates_page']
        );

        add_submenu_page(
            'wcs_affiliates',
            __('Settings', 'wcs-affiliates'),
            __('Settings', 'wcs-affiliates'),
            'manage_woocommerce',
            'wcs_affiliates_settings',
            [$this, 'render_settings_page']
        );
    }

    public function handle_admin_actions() {
        if (!is_admin() || !isset($_GET['page']) || $_GET['page'] !== 'wcs_affiliates') {
            return;
        }
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        if (!$action) {
            return;
        }

        if ($action === 'delete_agent') {
            $this->handle_delete_agent();
        }
        if ($action === 'deactivate_agent') {
            $this->handle_deactivate_agent();
        }
        if ($action === 'void_unpaid') {
            $this->handle_void_unpaid();
        }
        // Maintain legacy action for backward compatibility if any links exist
        if ($action === 'clear_commissions') {
            $this->handle_void_unpaid();
        }
    }

    private function handle_deactivate_agent() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized', 403);
        }
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('wcs_aff_deactivate_' . $id);

        global $wpdb;
        $wpdb->update($this->affiliates_table, ['status' => 'inactive'], ['id' => $id]);

        wp_redirect(add_query_arg(['page' => 'wcs_affiliates', 'msg' => 'deactivated'], admin_url('admin.php')));
        exit;
    }

    private function handle_delete_agent() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized', 403);
        }
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('wcs_aff_delete_' . $id);

        global $wpdb;
        // Delete commissions
        $wpdb->delete($this->commissions_table, ['affiliate_id' => $id], ['%d']);
        // Delete affiliate
        $wpdb->delete($this->affiliates_table, ['id' => $id], ['%d']);

        wp_redirect(add_query_arg(['page' => 'wcs_affiliates', 'msg' => 'deleted'], admin_url('admin.php')));
        exit;
    }

    private function handle_void_unpaid() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized', 403);
        }
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        // Check for either new or legacy nonce
        if (!wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'wcs_aff_void_' . $id) && !wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'wcs_aff_clear_' . $id)) {
            wp_die('Invalid nonce', 403);
        }

        global $wpdb;
        // Void pending and exported commissions, preserve paid
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->commissions_table} SET status = 'void' WHERE affiliate_id = %d AND status IN ('pending', 'exported')",
            $id
        ));

        $redirect = add_query_arg(['page' => 'wcs_affiliates', 'msg' => 'voided'], admin_url('admin.php'));
        if (isset($_GET['ref']) && $_GET['ref'] === 'edit') {
             $redirect = add_query_arg(['page' => 'wcs_affiliates', 'action' => 'edit', 'id' => $id, 'msg' => 'voided'], admin_url('admin.php'));
        }

        wp_redirect($redirect);
        exit;
    }

    public function handle_save_affiliate() {
        if (!is_admin() || empty($_POST['wcs_aff_save'])) {
            return;
        }
        check_admin_referer('wcs_aff_edit');
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized', 403);
        }

        global $wpdb;
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $is_new = ($_GET['action'] ?? '') === 'new';

        $name  = sanitize_text_field($_POST['name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $nequi = sanitize_text_field($_POST['nequi_phone'] ?? '');
        $bank_name = sanitize_text_field($_POST['bank_name'] ?? '');
        $bank_account_type = sanitize_text_field($_POST['bank_account_type'] ?? '');
        $bank_account_number = sanitize_text_field($_POST['bank_account_number'] ?? '');
        $commission = isset($_POST['commission_percent']) ? floatval($_POST['commission_percent']) : 0;
        $status_input = $_POST['status'] ?? 'active';
        $status = in_array($status_input, ['active','inactive'], true) ? $status_input : 'active';
        $dashboard_mode = $_POST['dashboard_mode'] ?? 'default';
        if (!in_array($dashboard_mode, ['default','simple','advanced'], true)) {
            $dashboard_mode = 'default';
        }

        $now = current_time('mysql');

        if ($is_new) {
            $uid = $this->generate_unique_uid();

            if ($email === '') {
                $host = parse_url(home_url(), PHP_URL_HOST);
                if (!$host) {
                    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'example.com';
                }
                $email = 'affiliate_' . strtolower($uid) . '@' . $host;
            }

            $user_id = $this->ensure_user_for_affiliate($email, $name);

            $inserted = $wpdb->insert(
                $this->affiliates_table,
                [
                    'user_id'            => $user_id,
                    'uid'                => $uid,
                    'name'               => $name,
                    'email'              => $email,
                    'phone'              => $phone,
                    'nequi_phone'        => $nequi,
                    'bank_name'          => $bank_name,
                    'bank_account_type'  => $bank_account_type,
                    'bank_account_number'=> $bank_account_number,
                    'commission_percent' => $commission,
                    'dashboard_mode'     => $dashboard_mode,
                    'status'             => $status,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ],
                [
                    '%d','%s','%s','%s','%s','%s','%s','%s','%s','%f','%s','%s','%s','%s',
                ]
            );

            if ($inserted !== false) {
                $new_id = (int) $wpdb->insert_id;
                wp_redirect(add_query_arg(['page' => 'wcs_affiliates', 'action' => 'edit', 'id' => $new_id, 'msg' => 'saved'], admin_url('admin.php')));
                exit;
            } else {
                 wp_die('Database insert failed: ' . esc_html($wpdb->last_error));
            }

        } else {
            $row = $wpdb->get_row($wpdb->prepare("SELECT user_id FROM {$this->affiliates_table} WHERE id = %d", $id), ARRAY_A);
            if (!$row) {
                wp_die('Affiliate not found.');
            }
            $user_id = $row['user_id'];
            if (!$user_id) {
                $user_id = $this->ensure_user_for_affiliate($email, $name);
            }
            $updated = $wpdb->update(
                $this->affiliates_table,
                [
                    'user_id'            => $user_id,
                    'name'               => $name,
                    'email'              => $email,
                    'phone'              => $phone,
                    'nequi_phone'        => $nequi,
                    'bank_name'          => $bank_name,
                    'bank_account_type'  => $bank_account_type,
                    'bank_account_number'=> $bank_account_number,
                    'commission_percent' => $commission,
                    'dashboard_mode'     => $dashboard_mode,
                    'status'             => $status,
                    'updated_at'         => $now,
                ],
                ['id' => $id],
                ['%d','%s','%s','%s','%s','%s','%s','%s','%f','%s','%s','%d'],
                ['%d']
            );

            wp_redirect(add_query_arg(['page' => 'wcs_affiliates', 'action' => 'edit', 'id' => $id, 'msg' => 'saved'], admin_url('admin.php')));
            exit;
        }
    }

    public function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [$this, 'sanitize_options']);

        // General
        add_settings_section(
            'wcs_aff_general',
            __('General', 'wcs-affiliates'),
            function () {
                echo '<p>Global defaults for affiliate commissions and dashboard behaviour.</p>';
            },
            self::OPTION_KEY
        );

        add_settings_field(
            'default_commission_percent',
            __('Default commission %', 'wcs-affiliates'),
            function () {
                $o = $this->options;
                printf(
                    '<input type="number" step="0.01" min="0" name="%s[default_commission_percent]" value="%s" class="small-text" />',
                    esc_attr(self::OPTION_KEY),
                    esc_attr($o['default_commission_percent'])
                );
            },
            self::OPTION_KEY,
            'wcs_aff_general'
        );

        add_settings_field(
            'cookie_days',
            __('Cookie lifetime (days)', 'wcs-affiliates'),
            function () {
                $o = $this->options;
                printf(
                    '<input type="number" min="1" name="%s[cookie_days]" value="%s" class="small-text" />',
                    esc_attr(self::OPTION_KEY),
                    esc_attr($o['cookie_days'])
                );
            },
            self::OPTION_KEY,
            'wcs_aff_general'
        );

        add_settings_field(
            'link_prefix',
            __('Link prefix before UID', 'wcs-affiliates'),
            function () {
                $o = $this->options;
                printf(
                    '<input type="text" name="%s[link_prefix]" value="%s" class="regular-text" />',
                    esc_attr(self::OPTION_KEY),
                    esc_attr($o['link_prefix'])
                );
                echo '<p class="description">' . esc_html__('Optional. Example: taxi1 → links look like ?taxi1-UID', 'wcs-affiliates') . '</p>';
            },
            self::OPTION_KEY,
            'wcs_aff_general'
        );

        add_settings_field(
            'commission_base',
            __('Commission base', 'wcs-affiliates'),
            function () {
                $o = $this->options;
                $val = $o['commission_base'];
                ?>
                <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[commission_base]">
                    <option value="line_subtotal" <?php selected($val, 'line_subtotal'); ?>>
                        <?php esc_html_e('Line item subtotal (excl. tax & shipping)', 'wcs-affiliates'); ?>
                    </option>
                    <option value="order_total_excl_shipping" <?php selected($val, 'order_total_excl_shipping'); ?>>
                        <?php esc_html_e('Order total minus shipping & shipping tax', 'wcs-affiliates'); ?>
                    </option>
                </select>
                <?php
            },
            self::OPTION_KEY,
            'wcs_aff_general'
        );

        add_settings_field(
            'default_dashboard_mode',
            __('Default dashboard mode', 'wcs-affiliates'),
            function () {
                $o = $this->options;
                $val = $o['default_dashboard_mode'];
                ?>
                <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_dashboard_mode]">
                    <option value="simple" <?php selected($val, 'simple'); ?>><?php esc_html_e('Simple', 'wcs-affiliates'); ?></option>
                    <option value="advanced" <?php selected($val, 'advanced'); ?>><?php esc_html_e('Advanced', 'wcs-affiliates'); ?></option>
                </select>
                <?php
            },
            self::OPTION_KEY,
            'wcs_aff_general'
        );

        add_settings_field(
            'allow_edit_payout',
            __('Affiliates can edit payout details', 'wcs-affiliates'),
            function () {
                $o = $this->options;
                printf(
                    '<label><input type="checkbox" name="%s[allow_edit_payout]" value="1" %s /> %s</label>',
                    esc_attr(self::OPTION_KEY),
                    checked(!empty($o['allow_edit_payout']), true, false),
                    esc_html__('Allow affiliates to update their Nequi / bank details in the dashboard', 'wcs-affiliates')
                );
            },
            self::OPTION_KEY,
            'wcs_aff_general'
        );

        // Tracking (UTM)
        add_settings_section(
            'wcs_aff_tracking',
            __('Tracking (UTM)', 'wcs-affiliates'),
            function () {
                echo '<p>Optional UTM parameters appended to referral links for Google Analytics attribution.</p>';
            },
            self::OPTION_KEY
        );

        add_settings_field(
            'enable_utm',
            __('Append UTM parameters to links', 'wcs-affiliates'),
            function () {
                $o = $this->options;
                printf(
                    '<label><input type="checkbox" name="%s[enable_utm]" value="1" %s /> %s</label>',
                    esc_attr(self::OPTION_KEY),
                    checked(!empty($o['enable_utm']), true, false),
                    esc_html__('Enable UTM on generated links/QRs', 'wcs-affiliates')
                );
            },
            self::OPTION_KEY,
            'wcs_aff_tracking'
        );

        add_settings_field(
            'utm_source',
            __('utm_source', 'wcs-affiliates'),
            function () {
                $o = $this->options;
                printf(
                    '<input type="text" name="%s[utm_source]" value="%s" class="regular-text" />',
                    esc_attr(self::OPTION_KEY),
                    esc_attr($o['utm_source'] ?? '')
                );
                echo '<p class="description">' . esc_html__('Required by Google. Example: affiliate', 'wcs-affiliates') . '</p>';
            },
            self::OPTION_KEY,
            'wcs_aff_tracking'
        );

        add_settings_field(
            'utm_medium',
            __('utm_medium', 'wcs-affiliates'),
            function () {
                $o = $this->options;
                printf(
                    '<input type="text" name="%s[utm_medium]" value="%s" class="regular-text" />',
                    esc_attr(self::OPTION_KEY),
                    esc_attr($o['utm_medium'] ?? '')
                );
                echo '<p class="description">' . esc_html__('Required by Google. Example: qr', 'wcs-affiliates') . '</p>';
            },
            self::OPTION_KEY,
            'wcs_aff_tracking'
        );

        add_settings_field(
            'utm_campaign',
            __('utm_campaign', 'wcs-affiliates'),
            function () {
                $o = $this->options;
                printf(
                    '<input type="text" name="%s[utm_campaign]" value="%s" class="regular-text" />',
                    esc_attr(self::OPTION_KEY),
                    esc_attr($o['utm_campaign'] ?? '')
                );
                echo '<p class="description">' . esc_html__('Required by Google. Example: affiliate', 'wcs-affiliates') . '</p>';
            },
            self::OPTION_KEY,
            'wcs_aff_tracking'
        );

        add_settings_field(
            'utm_include_uid_content',
            __('Include UID as utm_content', 'wcs-affiliates'),
            function () {
                $o = $this->options;
                printf(
                    '<label><input type="checkbox" name="%s[utm_include_uid_content]" value="1" %s /> %s</label>',
                    esc_attr(self::OPTION_KEY),
                    checked(!empty($o['utm_include_uid_content']), true, false),
                    esc_html__('Adds utm_content=UID (useful for GA breakdowns, slightly longer links)', 'wcs-affiliates')
                );
            },
            self::OPTION_KEY,
            'wcs_aff_tracking'
        );
    }


    public function sanitize_options($in) {
        $out = $this->options;
        $out['default_commission_percent'] = isset($in['default_commission_percent']) ? floatval($in['default_commission_percent']) : 0;
        $out['cookie_days']                = isset($in['cookie_days']) ? max(1, intval($in['cookie_days'])) : 30;
        $allowed_bases = ['line_subtotal', 'order_total_excl_shipping'];
        $out['commission_base']            = in_array($in['commission_base'] ?? '', $allowed_bases, true) ? $in['commission_base'] : 'line_subtotal';
        $out['default_dashboard_mode']     = ($in['default_dashboard_mode'] ?? '') === 'advanced' ? 'advanced' : 'simple';
        $out['allow_edit_payout']          = !empty($in['allow_edit_payout']) ? 1 : 0;
        $prefix = isset($in['link_prefix']) ? sanitize_text_field($in['link_prefix']) : '';
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', $prefix);
        $out['link_prefix'] = $prefix;

        $out['enable_utm'] = !empty($in['enable_utm']) ? 1 : 0;
        $out['utm_source'] = isset($in['utm_source']) ? sanitize_text_field($in['utm_source']) : 'affiliate';
        $out['utm_medium'] = isset($in['utm_medium']) ? sanitize_text_field($in['utm_medium']) : 'qr';
        $out['utm_campaign'] = isset($in['utm_campaign']) ? sanitize_text_field($in['utm_campaign']) : 'affiliate';
        $out['utm_include_uid_content'] = !empty($in['utm_include_uid_content']) ? 1 : 0;
        foreach (['utm_source','utm_medium','utm_campaign'] as $k) {
            $out[$k] = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $out[$k]);
        }

        return $out;
    }

    public function render_settings_page() {
        $this->options = $this->get_options();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Affiliate Agents – Settings', 'wcs-affiliates'); ?></h1>

            

            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_KEY);
                do_settings_sections(self::OPTION_KEY);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_affiliates_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'wcs-affiliates'));
        }

        // Show notices
        if (isset($_GET['msg'])) {
            $m = sanitize_key($_GET['msg']);
            if ($m === 'deleted') {
                echo '<div class="notice notice-success"><p>' . esc_html__('Affiliate deleted.', 'wcs-affiliates') . '</p></div>';
            } elseif ($m === 'deactivated') {
                echo '<div class="notice notice-success"><p>' . esc_html__('Affiliate deactivated.', 'wcs-affiliates') . '</p></div>';
            } elseif ($m === 'voided') {
                echo '<div class="notice notice-success"><p>' . esc_html__('Pending/Unpaid commissions voided. Paid history preserved.', 'wcs-affiliates') . '</p></div>';
            } elseif ($m === 'cleared') { // Legacy
                echo '<div class="notice notice-success"><p>' . esc_html__('Commissions cleared.', 'wcs-affiliates') . '</p></div>';
            }
        }

        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        if ($action === 'edit' || $action === 'new') {
            $this->render_affiliate_edit_page();
            return;
        }
        if ($action === 'export_csv') {
            $this->handle_export_csv();
            return;
        }

        $this->render_affiliate_list_page();
    }

    private function render_affiliate_list_page() {
        global $wpdb;


        // Bulk generate generic affiliates (UIDs only)
        if (!empty($_POST['wcs_bulk_generate'])) {
            if (!current_user_can('manage_woocommerce')) {
                wp_die(__('Unauthorized', 'wcs-affiliates'), 403);
            }
            check_admin_referer('wcs_aff_bulk_generate');

            $count = isset($_POST['bulk_count']) ? max(1, min(500, (int) $_POST['bulk_count'])) : 1;
            $status = in_array($_POST['bulk_status'] ?? 'active', ['active','inactive'], true) ? $_POST['bulk_status'] : 'active';
            $commission = isset($_POST['bulk_commission_percent']) && $_POST['bulk_commission_percent'] !== '' ? floatval($_POST['bulk_commission_percent']) : 0;

            $now = current_time('mysql');
            $created = 0;

            for ($i = 0; $i < $count; $i++) {
                $uid = $this->generate_unique_uid();
                $result = $wpdb->insert(
                    $this->affiliates_table,
                    [
                        'user_id'            => null,
                        'uid'                => $uid,
                        'name'               => '',
                        'email'              => '',
                        'phone'              => '',
                        'nequi_phone'        => '',
                        'bank_name'          => '',
                        'bank_account_type'  => '',
                        'bank_account_number'=> '',
                        'commission_percent' => $commission,
                        'dashboard_mode'     => 'default',
                        'status'             => $status,
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ]
                );

                if ($result !== false) {
                    $created++;
                }
            }

            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(__('Generated %d affiliate(s).', 'wcs-affiliates'), $created)) . '</p></div>';
        }



        // Search & Pagination
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        $paged = isset($_REQUEST['paged']) ? max(1, (int) $_REQUEST['paged']) : 1;
        $per_page = 50;
        $offset = ($paged - 1) * $per_page;

        $where = "1=1";
        $args = [];
        if ($search) {
            $where .= " AND (name LIKE %s OR email LIKE %s OR uid LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }

        // Count total
        if (!empty($args)) {
            $count_sql = $wpdb->prepare("SELECT COUNT(*) FROM {$this->affiliates_table} WHERE $where", ...$args);
        } else {
            $count_sql = "SELECT COUNT(*) FROM {$this->affiliates_table} WHERE $where";
        }
        $total_items = (int) $wpdb->get_var($count_sql);
        $total_pages = ceil($total_items / $per_page);

        // Fetch Data
        $query = "SELECT * FROM {$this->affiliates_table} WHERE $where ORDER BY created_at DESC LIMIT %d, %d";
        $args[] = $offset;
        $args[] = $per_page;
        $rows = $wpdb->get_results($wpdb->prepare($query, ...$args), ARRAY_A);

        // Fetch Commission Totals (for visible rows only)
        $totals = [];
        if (!empty($rows)) {
            $ids = wp_list_pluck($rows, 'id');
            $id_str = implode(',', array_map('intval', $ids));
            if ($id_str) {
                $comm_rows = $wpdb->get_results(
                    "SELECT affiliate_id,
                            SUM(CASE WHEN status = 'pending' THEN commission_amount ELSE 0 END) AS unexported,
                            SUM(CASE WHEN status = 'exported' THEN commission_amount ELSE 0 END) AS exported,
                            SUM(CASE WHEN status IN ('pending','exported') THEN commission_amount ELSE 0 END) AS unpaid,
                            SUM(CASE WHEN status != 'void' THEN commission_amount ELSE 0 END) AS total,
                            MIN(currency) AS currency
                     FROM {$this->commissions_table}
                     WHERE affiliate_id IN ($id_str)
                     GROUP BY affiliate_id",
                    ARRAY_A
                );
                foreach ($comm_rows as $r) {
                    $totals[(int) $r['affiliate_id']] = [
                        'unexported' => (float) $r['unexported'],
                        'exported'   => (float) $r['exported'],
                        'unpaid'     => (float) $r['unpaid'],
                        'total'      => (float) $r['total'],
                        'currency'   => (string) $r['currency'],
                    ];
                }
            }
        }

        // Global rollups for "unexported" (pending) summary
        $global = $wpdb->get_row(
            "SELECT 
                SUM(CASE WHEN status = 'pending' THEN commission_amount ELSE 0 END) AS unexported_amount,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) AS unexported_rows,
                COUNT(DISTINCT CASE WHEN status = 'pending' THEN affiliate_id END) AS affiliates_with_unexported,
                MIN(CASE WHEN status = 'pending' THEN created_at ELSE NULL END) AS oldest_unexported,
                SUM(CASE WHEN status = 'exported' THEN commission_amount ELSE 0 END) AS exported_amount,
                SUM(CASE WHEN status IN ('pending','exported') THEN commission_amount ELSE 0 END) AS unpaid_amount
             FROM {$this->commissions_table}",
            ARRAY_A
        );
        $g_unexported_amount = $global && $global['unexported_amount'] !== null ? (float) $global['unexported_amount'] : 0;
        $g_unexported_rows   = $global && $global['unexported_rows'] !== null ? (int) $global['unexported_rows'] : 0;
        $g_aff_with_unexp    = $global && $global['affiliates_with_unexported'] !== null ? (int) $global['affiliates_with_unexported'] : 0;
        $g_oldest_unexp_raw  = $global['oldest_unexported'] ?? null;
        $g_exported_amount   = $global && $global['exported_amount'] !== null ? (float) $global['exported_amount'] : 0;
        $g_unpaid_amount     = $global && $global['unpaid_amount'] !== null ? (float) $global['unpaid_amount'] : 0;
$export_url = wp_nonce_url(
            add_query_arg(['page' => 'wcs_affiliates', 'action' => 'export_csv'], admin_url('admin.php')),
            'wcs_aff_export'
        );
        $new_url    = add_query_arg(['page' => 'wcs_affiliates', 'action' => 'new'], admin_url('admin.php'));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Affiliate Agents', 'wcs-affiliates'); ?></h1>
            <p>
                <a href="<?php echo esc_url($new_url); ?>" class="button button-primary"><?php esc_html_e('Add New Affiliate', 'wcs-affiliates'); ?></a>
                <a href="<?php echo esc_url($export_url); ?>" class="button"><?php esc_html_e('Export pending commissions (CSV)', 'wcs-affiliates'); ?></a>
            </p>

            <style>
                .wcs-aff-summary { display:flex; gap:12px; flex-wrap:wrap; margin: 12px 0 14px; }
                .wcs-aff-summary__card { background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:12px 14px; min-width:220px; flex:1; box-shadow: 0 1px 0 rgba(0,0,0,.02); }
                .wcs-aff-summary__label { font-size:12px; opacity:.8; margin-bottom:6px; }
                .wcs-aff-summary__value { font-size:18px; font-weight:600; line-height:1.2; }
                .wcs-aff-summary__meta { font-size:12px; opacity:.75; margin-top:6px; }
                .wcs-aff-ready { font-weight:600; }
            </style>
            <?php
                $currency_code = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
                $currency_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol($currency_code) : '';
                $oldest_label = '';
                if (!empty($g_oldest_unexp_raw)) {
                    $ts = strtotime($g_oldest_unexp_raw);
                    if ($ts) {
                        $oldest_label = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts);
                    }
                }
                $money_suffix = trim((string) ($currency_symbol ?: $currency_code));
            ?>
            <div class="wcs-aff-summary">
                <div class="wcs-aff-summary__card">
                    <div class="wcs-aff-summary__label"><?php esc_html_e('Unexported total', 'wcs-affiliates'); ?></div>
                    <div class="wcs-aff-summary__value"><?php echo esc_html(number_format($g_unexported_amount, 2)); ?> <?php echo esc_html($money_suffix); ?></div>
                    <div class="wcs-aff-summary__meta"><?php echo esc_html(sprintf(__('%d rows · %d affiliates', 'wcs-affiliates'), $g_unexported_rows, $g_aff_with_unexp)); ?></div>
                </div>
                <div class="wcs-aff-summary__card">
                    <div class="wcs-aff-summary__label"><?php esc_html_e('Oldest unexported', 'wcs-affiliates'); ?></div>
                    <div class="wcs-aff-summary__value"><?php echo $oldest_label ? esc_html($oldest_label) : '—'; ?></div>
                    <div class="wcs-aff-summary__meta"><?php esc_html_e('Helps you spot payout backlog', 'wcs-affiliates'); ?></div>
                </div>
                <div class="wcs-aff-summary__card">
                    <div class="wcs-aff-summary__label"><?php esc_html_e('Exported (awaiting payout)', 'wcs-affiliates'); ?></div>
                    <div class="wcs-aff-summary__value"><?php echo esc_html(number_format($g_exported_amount, 2)); ?> <?php echo esc_html($money_suffix); ?></div>
                    <div class="wcs-aff-summary__meta"><?php esc_html_e('Already in CSV batch', 'wcs-affiliates'); ?></div>
                </div>
                <div class="wcs-aff-summary__card">
                    <div class="wcs-aff-summary__label"><?php esc_html_e('Total unpaid kickbacks', 'wcs-affiliates'); ?></div>
                    <div class="wcs-aff-summary__value"><?php echo esc_html(number_format($g_unpaid_amount, 2)); ?> <?php echo esc_html($money_suffix); ?></div>
                    <div class="wcs-aff-summary__meta"><?php esc_html_e('Unexported + exported', 'wcs-affiliates'); ?></div>
                </div>
            </div>
            <!-- Search Box -->
            <form method="get" style="float:right; margin-bottom:10px;">
                <input type="hidden" name="page" value="wcs_affiliates" />
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search agent...', 'wcs-affiliates'); ?>" />
                <button type="submit" class="button"><?php esc_html_e('Search', 'wcs-affiliates'); ?></button>
            </form>
            <div style="clear:both;"></div>

            <form method="post" style="margin:12px 0;">
                <?php wp_nonce_field('wcs_aff_bulk_generate'); ?>
                <input type="hidden" name="wcs_bulk_generate" value="1" />
                <label>
                    <?php esc_html_e('Bulk generate', 'wcs-affiliates'); ?>
                    <input type="number" min="1" max="500" name="bulk_count" value="10" style="width:90px;" />
                </label>
                <label style="margin-left:10px;">
                    <?php esc_html_e('Status', 'wcs-affiliates'); ?>
                    <select name="bulk_status">
                        <option value="active"><?php esc_html_e('Active', 'wcs-affiliates'); ?></option>
                        <option value="inactive"><?php esc_html_e('Inactive', 'wcs-affiliates'); ?></option>
                    </select>
                </label>
                <label style="margin-left:10px;">
                    <?php esc_html_e('Commission %', 'wcs-affiliates'); ?>
                    <input type="number" step="0.01" min="0" name="bulk_commission_percent" value="" placeholder="0" style="width:90px;" />
                </label>
                <button type="submit" class="button"><?php esc_html_e('Generate', 'wcs-affiliates'); ?></button>
            </form>

            <form method="post" style="margin:12px 0;" id="wcs-aff-bulk-form">
                <?php wp_nonce_field('wcs_aff_bulk_action'); ?>

                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action" id="bulk-action-selector-top">
                            <option value="-1"><?php esc_html_e('Bulk actions', 'wcs-affiliates'); ?></option>
                            <option value="delete"><?php esc_html_e('Delete', 'wcs-affiliates'); ?></option>
                            <option value="download_qr_zip"><?php esc_html_e('Download selected QRs (ZIP)', 'wcs-affiliates'); ?></option>
                        </select>
                        <input type="submit" id="doaction" class="button action" value="<?php esc_attr_e('Apply', 'wcs-affiliates'); ?>">
                    </div>
                </div>

                <table class="widefat striped">
                <thead>
                <tr>
                    <th style="width:40px;"><input type="checkbox" id="wcs_aff_select_all" /></th>
                    <th style="width:50px;">#</th>
                    <th><?php esc_html_e('Name', 'wcs-affiliates'); ?></th>
                    <th><?php esc_html_e('Email', 'wcs-affiliates'); ?></th>
                    <th><?php esc_html_e('UID', 'wcs-affiliates'); ?></th>
                    <th><?php esc_html_e('Commission %', 'wcs-affiliates'); ?></th>
                    <th><?php esc_html_e('Unexported', 'wcs-affiliates'); ?></th>
                    <th><?php esc_html_e('Exported', 'wcs-affiliates'); ?></th>
                    <th><?php esc_html_e('Unpaid kickback', 'wcs-affiliates'); ?></th>
                    <th><?php esc_html_e('Total earned', 'wcs-affiliates'); ?></th>
                    <th><?php esc_html_e('Payout ready', 'wcs-affiliates'); ?></th>
                    <th><?php esc_html_e('Status', 'wcs-affiliates'); ?></th>
                    <th><?php esc_html_e('QR', 'wcs-affiliates'); ?></th>
                    <th><?php esc_html_e('Actions', 'wcs-affiliates'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="14"><?php esc_html_e('No affiliates yet.', 'wcs-affiliates'); ?></td></tr>
                <?php else : ?>
                    <?php
                    $row_index = 0;
                    foreach ($rows as $row) :
                        $row_index++;
                        $current_number = $offset + $row_index;
                        $aff_id = (int) $row['id'];
                        $t = $totals[$aff_id] ?? ['unexported' => 0, 'exported' => 0, 'unpaid' => 0, 'total' => 0, 'currency' => ''];
                        $has_nequi = !empty($row['nequi_phone']);
                        $has_bank = !empty($row['bank_name']) && !empty($row['bank_account_number']);
                        $payout_ready = ($has_nequi || $has_bank);
                        $edit_url = add_query_arg(
                            ['page' => 'wcs_affiliates', 'action' => 'edit', 'id' => $aff_id],
                            admin_url('admin.php')
                        );
                        $qr_url = wp_nonce_url(
                            add_query_arg(
                                ['action' => 'wcs_download_affiliate_qr', 'affiliate_id' => $aff_id],
                                admin_url('admin-ajax.php')
                            ),
                            'wcs_download_affiliate_qr_' . $aff_id
                        );
                        $delete_url = wp_nonce_url(
                            add_query_arg(
                                ['page' => 'wcs_affiliates', 'action' => 'delete_agent', 'id' => $aff_id],
                                admin_url('admin.php')
                            ),
                            'wcs_aff_delete_' . $aff_id
                        );
                        $clear_url = wp_nonce_url(
                            add_query_arg(
                                ['page' => 'wcs_affiliates', 'action' => 'clear_commissions', 'id' => $aff_id],
                                admin_url('admin.php')
                            ),
                            'wcs_aff_clear_' . $aff_id
                        );
                        ?>
                        <tr>
                            <td><input type="checkbox" name="affiliate_ids[]" value="<?php echo esc_attr($aff_id); ?>" /></td>
                            <td><?php echo (int) $current_number; ?></td>
                            <td><?php echo esc_html(!empty($row['name']) ? $row['name'] : '—'); ?></td>
                            <td><?php if (!empty($row['email'])) : ?><a href="mailto:<?php echo esc_attr($row['email']); ?>"><?php echo esc_html($row['email']); ?></a><?php else : ?>—<?php endif; ?></td>
                            <td><code><?php echo esc_html($row['uid']); ?></code></td>
                            <td><?php echo esc_html(number_format((float) $row['commission_percent'], 2)); ?>%</td>
                            <td><?php echo esc_html(number_format($t['unexported'], 2)); ?></td>
                            <td><?php echo esc_html(number_format($t['exported'], 2)); ?></td>
                            <td><strong><?php echo esc_html(number_format($t['unpaid'], 2)); ?></strong></td>
                            <td><?php echo esc_html(number_format($t['total'], 2)); ?></td>
                            <td><?php echo $payout_ready ? '✅' : '⚠️'; ?></td>
                            <td><?php echo esc_html(ucfirst($row['status'])); ?></td>
                            <td><a href="<?php echo esc_url($qr_url); ?>" class="button button-small"><?php esc_html_e('Download QR', 'wcs-affiliates'); ?></a></td>
                            <td>
                                <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'wcs-affiliates'); ?></a>
                                <?php
                                $void_url = wp_nonce_url(
                                    add_query_arg(['page' => 'wcs_affiliates', 'action' => 'void_unpaid', 'id' => $aff_id], admin_url('admin.php')),
                                    'wcs_aff_void_' . $aff_id
                                );
                                $deactivate_url = wp_nonce_url(
                                    add_query_arg(['page' => 'wcs_affiliates', 'action' => 'deactivate_agent', 'id' => $aff_id], admin_url('admin.php')),
                                    'wcs_aff_deactivate_' . $aff_id
                                );
                                ?>
                                | <a href="#" onclick="wcs_confirm_action('<?php echo esc_js($void_url); ?>', '<?php echo esc_js(__('Void (clear) all UNPAID commissions? Paid history will be kept.', 'wcs-affiliates')); ?>'); return false;" style="color:orange;"><?php esc_html_e('Void Unpaid', 'wcs-affiliates'); ?></a>
                                <?php if ($row['status'] === 'active') : ?>
                                    | <a href="#" onclick="wcs_confirm_action('<?php echo esc_js($deactivate_url); ?>', '<?php echo esc_js(__('Deactivate this agent?', 'wcs-affiliates')); ?>'); return false;" style="color:#d63638;"><?php esc_html_e('Deactivate', 'wcs-affiliates'); ?></a>
                                <?php else : ?>
                                    | <a href="#" onclick="wcs_confirm_delete('<?php echo esc_js($delete_url); ?>', '<?php echo esc_js(__('Permanently DELETE this agent and all data?', 'wcs-affiliates')); ?>'); return false;" style="color:red; font-weight:bold;"><?php esc_html_e('Delete Permanent', 'wcs-affiliates'); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php
            // Pagination
            if ($total_pages > 1) {
                echo '<div class="tablenav bottom"><div class="tablenav-pages">';
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total' => $total_pages,
                    'current' => $paged
                ]);
                echo '</div></div>';
            }
            ?>
            </form>
            <script>
            function wcs_confirm_action(url, msg) {
                if (confirm(msg)) {
                    window.location.href = url;
                }
            }
            function wcs_confirm_delete(url, msg) {
                var check = prompt(msg + "\n<?php echo esc_js(__('Type "delete" to confirm:', 'wcs-affiliates')); ?>");
                if (check === 'delete') {
                    window.location.href = url;
                }
            }
            (function(){
                var all = document.getElementById('wcs_aff_select_all');
                if (!all) return;
                all.addEventListener('change', function(){
                    var boxes = document.querySelectorAll('input[name="affiliate_ids[]"]');
                    for (var i=0;i<boxes.length;i++){ boxes[i].checked = all.checked; }
                });

                var form = document.getElementById('wcs-aff-bulk-form');
                if (form) {
                    form.addEventListener('submit', function(e){
                        var selector = document.getElementById('bulk-action-selector-top');
                        if (selector && selector.value === 'delete') {
                            if (!confirm('<?php echo esc_js(__('Are you sure you want to delete the selected affiliates?', 'wcs-affiliates')); ?>')) {
                                e.preventDefault();
                            }
                        }
                    });
                }
            })();
            </script>
        </div>
        <?php
    }

    private function render_affiliate_edit_page() {
        global $wpdb;

        $is_new = ($_GET['action'] ?? '') === 'new';
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $row = null;

        if (!$is_new) {
            if ($id <= 0) {
                echo '<div class="notice notice-error"><p>Invalid affiliate ID.</p></div>';
                return;
            }
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$this->affiliates_table} WHERE id = %d LIMIT 1", $id),
                ARRAY_A
            );
            if (!$row) {
                echo '<div class="notice notice-error"><p>Affiliate not found.</p></div>';
                return;
            }
        }

        if (isset($_GET['msg']) && $_GET['msg'] === 'saved') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Affiliate saved.', 'wcs-affiliates') . '</p></div>';
        }

        $title = $is_new ? __('Add New Affiliate', 'wcs-affiliates') : __('Edit Affiliate', 'wcs-affiliates');
        $back_url = add_query_arg(['page' => 'wcs_affiliates'], admin_url('admin.php'));

        $row = $row ?? []; // Ensure row is array-ish if null

        $uid = $row['uid'] ?? $this->generate_preview_uid();
        $ref_url = $this->build_referral_url($uid);
        $commission_percent = $row['commission_percent'] ?? $this->options['default_commission_percent'];
        $status_val = $row['status'] ?? 'active';
        $dashboard_mode_val = $row['dashboard_mode'] ?? 'default';

        $qr_url = '';
        $delete_url = '';
        $deactivate_url = '';
        $void_url = '';
        if (!$is_new && !empty($row['id'])) {
            $qr_url = wp_nonce_url(
                add_query_arg(
                    ['action' => 'wcs_download_affiliate_qr', 'affiliate_id' => (int) $row['id']],
                    admin_url('admin-ajax.php')
                ),
                'wcs_download_affiliate_qr_' . (int) $row['id']
            );
            $delete_url = wp_nonce_url(
                add_query_arg(
                    ['page' => 'wcs_affiliates', 'action' => 'delete_agent', 'id' => (int) $row['id']],
                    admin_url('admin.php')
                ),
                'wcs_aff_delete_' . (int) $row['id']
            );
            $deactivate_url = wp_nonce_url(
                add_query_arg(
                    ['page' => 'wcs_affiliates', 'action' => 'deactivate_agent', 'id' => (int) $row['id']],
                    admin_url('admin.php')
                ),
                'wcs_aff_deactivate_' . (int) $row['id']
            );
            $void_url = wp_nonce_url(
                add_query_arg(
                    ['page' => 'wcs_affiliates', 'action' => 'void_unpaid', 'id' => (int) $row['id'], 'ref' => 'edit'],
                    admin_url('admin.php')
                ),
                'wcs_aff_void_' . (int) $row['id']
            );
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($title); ?></h1>
            <p><a href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to list', 'wcs-affiliates'); ?></a></p>

            <form method="post">
                <?php wp_nonce_field('wcs_aff_edit'); ?>
                <input type="hidden" name="wcs_aff_save" value="1" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wcs_aff_name"><?php esc_html_e('Name', 'wcs-affiliates'); ?></label></th>
                        <td><input name="name" id="wcs_aff_name" type="text" class="regular-text" value="<?php echo isset($row['name']) ? esc_attr($row['name']) : ''; ?>" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcs_aff_email"><?php esc_html_e('Email', 'wcs-affiliates'); ?></label></th>
                        <td><input name="email" id="wcs_aff_email" type="email" class="regular-text" value="<?php echo isset($row['email']) ? esc_attr($row['email']) : ''; ?>" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcs_aff_phone"><?php esc_html_e('Phone', 'wcs-affiliates'); ?></label></th>
                        <td><input name="phone" id="wcs_aff_phone" type="text" class="regular-text" value="<?php echo isset($row['phone']) ? esc_attr($row['phone']) : ''; ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcs_aff_nequi"><?php esc_html_e('Nequi phone', 'wcs-affiliates'); ?></label></th>
                        <td><input name="nequi_phone" id="wcs_aff_nequi" type="text" class="regular-text" value="<?php echo isset($row['nequi_phone']) ? esc_attr($row['nequi_phone']) : ''; ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcs_aff_bank_name"><?php esc_html_e('Bank name', 'wcs-affiliates'); ?></label></th>
                        <td><input name="bank_name" id="wcs_aff_bank_name" type="text" class="regular-text" value="<?php echo isset($row['bank_name']) ? esc_attr($row['bank_name']) : ''; ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcs_aff_bank_type"><?php esc_html_e('Bank account type', 'wcs-affiliates'); ?></label></th>
                        <td><input name="bank_account_type" id="wcs_aff_bank_type" type="text" class="regular-text" value="<?php echo isset($row['bank_account_type']) ? esc_attr($row['bank_account_type']) : ''; ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcs_aff_bank_number"><?php esc_html_e('Bank account number', 'wcs-affiliates'); ?></label></th>
                        <td><input name="bank_account_number" id="wcs_aff_bank_number" type="text" class="regular-text" value="<?php echo isset($row['bank_account_number']) ? esc_attr($row['bank_account_number']) : ''; ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Commission %', 'wcs-affiliates'); ?></th>
                        <td>
                            <input name="commission_percent" type="number" step="0.01" min="0" class="small-text" value="<?php echo esc_attr($commission_percent); ?>" />
                            <p class="description"><?php esc_html_e('If empty or 0, the global default will be used.', 'wcs-affiliates'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Dashboard mode', 'wcs-affiliates'); ?></th>
                        <td>
                            <select name="dashboard_mode">
                                <option value="default" <?php selected($dashboard_mode_val, 'default'); ?>><?php esc_html_e('Use global default', 'wcs-affiliates'); ?></option>
                                <option value="simple" <?php selected($dashboard_mode_val, 'simple'); ?>><?php esc_html_e('Force Simple', 'wcs-affiliates'); ?></option>
                                <option value="advanced" <?php selected($dashboard_mode_val, 'advanced'); ?>><?php esc_html_e('Force Advanced', 'wcs-affiliates'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Status', 'wcs-affiliates'); ?></th>
                        <td>
                            <select name="status">
                                <option value="active" <?php selected($status_val, 'active'); ?>><?php esc_html_e('Active', 'wcs-affiliates'); ?></option>
                                <option value="inactive" <?php selected($status_val, 'inactive'); ?>><?php esc_html_e('Inactive', 'wcs-affiliates'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Referral link & QR', 'wcs-affiliates'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('UID', 'wcs-affiliates'); ?></th>
                        <td>
                            <code><?php echo esc_html($uid); ?></code>
                            <p class="description"><?php esc_html_e('UID is auto-generated and used in the short URL like ?UID.', 'wcs-affiliates'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Referral URL', 'wcs-affiliates'); ?></th>
                        <td>
                            <input type="text" class="regular-text" readonly value="<?php echo esc_attr($ref_url); ?>" onclick="this.select();" />
                        </td>
                    </tr>
                    <?php if ($qr_url) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('QR code', 'wcs-affiliates'); ?></th>
                        <td>
                            <a href="<?php echo esc_url($qr_url); ?>" class="button"><?php esc_html_e('Download QR as PNG', 'wcs-affiliates'); ?></a>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save Affiliate', 'wcs-affiliates'); ?></button>
                </p>
            </form>

            <?php if (!$is_new && $void_url) : ?>
            <hr style="margin: 30px 0;">
            <h2><?php esc_html_e('Actions', 'wcs-affiliates'); ?></h2>
            <p>
                <button type="button" class="button" onclick="wcs_confirm_action('<?php echo esc_js($void_url); ?>', '<?php echo esc_js(__('Void (clear) all UNPAID commissions? Paid history will be kept.', 'wcs-affiliates')); ?>')" style="color:orange; border-color:orange;"><?php esc_html_e('Void Unpaid Commissions', 'wcs-affiliates'); ?></button>

                <?php if ($status_val === 'active') : ?>
                    <button type="button" class="button" onclick="wcs_confirm_action('<?php echo esc_js($deactivate_url); ?>', '<?php echo esc_js(__('Deactivate this agent? They will not track new sales.', 'wcs-affiliates')); ?>')" style="color:#d63638; border-color:#d63638; margin-left:10px;"><?php esc_html_e('Deactivate Agent', 'wcs-affiliates'); ?></button>
                <?php else : ?>
                    <button type="button" class="button" onclick="wcs_confirm_delete('<?php echo esc_js($delete_url); ?>', '<?php echo esc_js(__('Permanently DELETE this agent and all data? This cannot be undone.', 'wcs-affiliates')); ?>')" style="color:red; border-color:red; margin-left:10px;"><?php esc_html_e('Delete Permanently', 'wcs-affiliates'); ?></button>
                <?php endif; ?>
            </p>
            <script>
            function wcs_confirm_action(url, msg) {
                if (confirm(msg)) {
                    window.location.href = url;
                }
            }
            function wcs_confirm_delete(url, msg) {
                var check = prompt(msg + "\n<?php echo esc_js(__('Type "delete" to confirm:', 'wcs-affiliates')); ?>");
                if (check === 'delete') {
                    window.location.href = url;
                }
            }
            </script>
            <?php endif; ?>
        </div>
        <?php
    }

    private function generate_unique_uid() {
        global $wpdb;
        $tries = 0;
        do {
            $tries++;
            $uid = strtoupper(wp_generate_password(6, false, false));
            $uid = preg_replace('/[^A-Z0-9]/', '', $uid);
            if (strlen($uid) < 4) {
                $uid .= 'X' . mt_rand(10, 99);
            }
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$this->affiliates_table} WHERE uid = %s", $uid)
            );
            if (!$exists) {
                return $uid;
            }
        } while ($tries < 10);
        return 'AFF' . time();
    }

    private function generate_preview_uid() {
        $uid = strtoupper(wp_generate_password(6, false, false));
        $uid = preg_replace('/[^A-Z0-9]/', '', $uid);
        if (strlen($uid) < 4) {
            $uid .= 'X';
        }
        return $uid;
    }

    private function ensure_user_for_affiliate($email, $name) {
        if (!$email) {
            return 0;
        }
        $user = get_user_by('email', $email);
        if ($user) {
            if (!in_array('affiliate_agent', (array) $user->roles, true)) {
                $user->add_role('affiliate_agent');
            }
            return (int) $user->ID;
        }

        $login = sanitize_user(current(explode('@', $email)));
        if (username_exists($login)) {
            $login .= '_' . wp_generate_password(4, false, false);
        }
        $password = wp_generate_password(12, false, false);
        $user_id = wp_create_user($login, $password, $email);
        if (is_wp_error($user_id)) {
            return 0;
        }
        wp_update_user([
            'ID'           => $user_id,
            'display_name' => $name,
            'role'         => 'affiliate_agent',
        ]);
        if (function_exists('wp_new_user_notification')) {
            @wp_new_user_notification($user_id, null, 'user');
        }
        return (int) $user_id;
    }

    private function handle_export_csv() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to export.', 'wcs-affiliates'));
        }
        check_admin_referer('wcs_aff_export');

        global $wpdb;

        // Filter out negative/zero balances by grouping by affiliate & currency
        $sql = "SELECT c.affiliate_id,
                       SUM(c.commission_amount) AS total_commission,
                       c.currency
                FROM {$this->commissions_table} c
                WHERE c.status = 'pending'
                GROUP BY c.affiliate_id, c.currency
                HAVING total_commission > 0";
        $rows = $wpdb->get_results($sql, ARRAY_A);

        if (empty($rows)) {
            wp_die(__('No pending commissions to export (only negative/zero balances found).', 'wcs-affiliates'));
        }

        $ids = array_map('intval', wp_list_pluck($rows, 'affiliate_id'));
        $ids = array_values(array_unique(array_filter($ids)));

        if (empty($ids)) {
            wp_die(__('No pending commissions to export.', 'wcs-affiliates'));
        }

        $id_list = implode(',', $ids);
        $aff_rows = $wpdb->get_results(
            "SELECT * FROM {$this->affiliates_table} WHERE id IN ($id_list)",
            ARRAY_A
        );
        $aff_by_id = [];
        foreach ($aff_rows as $a) {
            $aff_by_id[(int) $a['id']] = $a;
        }

        $filename = 'wcs-affiliate-commissions-' . gmdate('Ymd-His') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'affiliate_id',
            'uid',
            'name',
            'email',
            'nequi_phone',
            'bank_name',
            'bank_account_type',
            'bank_account_number',
            'total_commission',
            'currency',
        ]);

        // Keep track of which commissions we are effectively exporting to update their status later
        // We only want to update commissions for the (affiliate, currency) pairs that are being exported.
        $exported_pairs = [];

        foreach ($rows as $r) {
            $aid = (int) $r['affiliate_id'];
            if (empty($aff_by_id[$aid])) {
                continue;
            }
            $a = $aff_by_id[$aid];
            fputcsv($out, [
                $aid,
                $a['uid'],
                $a['name'],
                $a['email'],
                $a['nequi_phone'],
                $a['bank_name'],
                $a['bank_account_type'],
                $a['bank_account_number'],
                number_format((float) $r['total_commission'], 2, '.', ''),
                $r['currency'],
            ]);
            $exported_pairs[] = [
                'affiliate_id' => $aid,
                'currency'     => $r['currency']
            ];
        }
        fclose($out);

        $batch_id = 'batch-' . gmdate('Ymd-His');

        // Update status for pending commissions that belong to the exported groups
        if (!empty($exported_pairs)) {
            // Because we can have multiple pairs, we might need multiple updates or a complex WHERE.
            // Simplest safe way: select IDs first.

            // Build WHERE clause
            $where_parts = [];
            foreach ($exported_pairs as $pair) {
                $where_parts[] = $wpdb->prepare("(affiliate_id = %d AND currency = %s)", $pair['affiliate_id'], $pair['currency']);
            }
            $where_sql = implode(' OR ', $where_parts);

            $pending_ids_to_update = $wpdb->get_col(
                "SELECT id FROM {$this->commissions_table}
                 WHERE status = 'pending' AND ($where_sql)"
            );

            if (!empty($pending_ids_to_update)) {
                foreach (array_chunk($pending_ids_to_update, 500) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                    $sql_update = "UPDATE {$this->commissions_table}
                                   SET status = 'exported', batch_id = %s
                                   WHERE id IN ($placeholders)";
                    $args = array_merge([$batch_id], $chunk);
                    $wpdb->query($wpdb->prepare($sql_update, ...$args));
                }
            }
        }

        exit;
    }

    public function ajax_download_affiliate_qr() {
        $aff_id = isset($_GET['affiliate_id']) ? (int) $_GET['affiliate_id'] : 0;
        if ($aff_id <= 0) {
            wp_die('Invalid affiliate ID', 400);
        }

        global $wpdb;

        // Permission check: Admin or the Affiliate themselves
        $is_admin = current_user_can('manage_woocommerce');
        $is_owner = false;

        if (!$is_admin && is_user_logged_in()) {
            $user_id = get_current_user_id();
            $owner_id = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$this->affiliates_table} WHERE id = %d", $aff_id));
            if ($owner_id && (int)$owner_id === $user_id) {
                $is_owner = true;
            }
        }

        if (!$is_admin && !$is_owner) {
            wp_die('Unauthorized', 403);
        }

        // Verify nonce
        if (!wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'wcs_download_affiliate_qr_' . $aff_id)) {
            wp_die('Invalid nonce', 403);
        }
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->affiliates_table} WHERE id = %d LIMIT 1", $aff_id),
            ARRAY_A
        );
        if (!$row) {
            wp_die('Affiliate not found', 404);
        }

        $uid = $row['uid'];
        $url = $this->build_referral_url($uid);

        $initials = $this->get_initials_from_name($row['name'], $uid);
        $filename = 'worldcitisim-affiliate-' . $uid . '-' . $initials . '.png';

        $qr_service = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=' . rawurlencode($url);

        $response = wp_remote_get($qr_service, ['timeout' => 20]);
        if (is_wp_error($response)) {
            wp_die('QR generation failed: ' . esc_html($response->get_error_message()), 500);
        }
        $body = wp_remote_retrieve_body($response);
        if (!$body) {
            wp_die('QR generation returned empty response', 500);
        }

        nocache_headers();
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $body;
        exit;
    }


    public function handle_bulk_actions() {
        if (!is_admin()) {
            return;
        }
        if (isset($_POST['bulk_action']) && $_POST['bulk_action'] !== '-1') {
            check_admin_referer('wcs_aff_bulk_action');
            if (!current_user_can('manage_woocommerce')) {
                wp_die('Unauthorized', 403);
            }

            $ids = $_POST['affiliate_ids'] ?? [];
            if (empty($ids) || !is_array($ids)) {
                return;
            }

            $ids = array_values(array_unique(array_map('intval', (array) $ids)));
            $ids = array_filter($ids, function ($v) { return $v > 0; });
            if (empty($ids)) return;

            if ($_POST['bulk_action'] === 'delete') {
                global $wpdb;
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));

                // Delete commissions
                $wpdb->query($wpdb->prepare("DELETE FROM {$this->commissions_table} WHERE affiliate_id IN ($placeholders)", ...$ids));

                // Delete affiliates
                $wpdb->query($wpdb->prepare("DELETE FROM {$this->affiliates_table} WHERE id IN ($placeholders)", ...$ids));

                wp_redirect(add_query_arg(['page' => 'wcs_affiliates', 'msg' => 'deleted'], admin_url('admin.php')));
                exit;

            } elseif ($_POST['bulk_action'] === 'download_qr_zip') {
                $this->generate_qr_zip_response($ids);
            }
        }
    }

    public function ajax_download_affiliate_qr_zip() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized', 403);
        }
        check_admin_referer('wcs_download_affiliate_qr_zip');

        $ids = $_POST['affiliate_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            wp_die('No affiliates selected', 400);
        }

        $ids = array_values(array_unique(array_map('intval', (array) $ids)));
        $ids = array_filter($ids, function ($v) { return $v > 0; });

        if (empty($ids)) {
            wp_die('No affiliates selected', 400);
        }

        $this->generate_qr_zip_response($ids);
    }

    private function generate_qr_zip_response($ids) {
        if (!class_exists('ZipArchive')) {
            wp_die('ZipArchive is not available on this server.', 500);
        }

        global $wpdb;

        $tmp = wp_tempnam('wcs-aff-qrs');
        if (!$tmp) {
            wp_die('Could not create temp file', 500);
        }
        @unlink($tmp);
        $zip_path = $tmp . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE) !== true) {
            wp_die('Could not open zip', 500);
        }

        foreach ($ids as $aff_id) {
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$this->affiliates_table} WHERE id = %d LIMIT 1", $aff_id),
                ARRAY_A
            );
            if (!$row) {
                continue;
            }

            $uid = (string) $row['uid'];
            $url = $this->build_referral_url($uid);
            $initials = $this->get_initials_from_name((string) $row['name'], $uid);
            $filename = 'worldcitisim-affiliate-' . $uid . '-' . $initials . '.png';

            $qr_service = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=' . rawurlencode($url);
            $response = wp_remote_get($qr_service, ['timeout' => 20]);

            if (is_wp_error($response)) {
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            if (!$body) {
                continue;
            }

            $zip->addFromString($filename, $body);
        }

        $zip->close();

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="worldcitisim-affiliate-qrs.zip"');
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path);
        @unlink($zip_path);
        exit;
    }



    private function get_initials_from_name($name, $uid) {
        $name = trim($name);
        if ($name === '') {
            return strtoupper(substr($uid, 0, 2));
        }
        $parts = preg_split('/\s+/', $name);
        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        }
        return strtoupper(mb_substr($parts[0], 0, 1));
    }

    public function shortcode_affiliate_dashboard($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to view your affiliate dashboard.', 'wcs-affiliates') . '</p>';
        }
        $user_id = get_current_user_id();

        global $wpdb;
        $aff = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->affiliates_table} WHERE user_id = %d LIMIT 1", $user_id),
            ARRAY_A
        );
        if (!$aff) {
            return '<p>' . esc_html__('You are not registered as an affiliate agent.', 'wcs-affiliates') . '</p>';
        }

        if (!empty($_POST['wcs_aff_payout_update'])) {
            if (!empty($this->options['allow_edit_payout'])) {
                check_admin_referer('wcs_aff_payout_update');
                $nequi = sanitize_text_field($_POST['nequi_phone'] ?? '');
                $bank_name = sanitize_text_field($_POST['bank_name'] ?? '');
                $bank_type = sanitize_text_field($_POST['bank_account_type'] ?? '');
                $bank_number = sanitize_text_field($_POST['bank_account_number'] ?? '');
                $now = current_time('mysql');
                $wpdb->update(
                    $this->affiliates_table,
                    [
                        'nequi_phone'        => $nequi,
                        'bank_name'          => $bank_name,
                        'bank_account_type'  => $bank_type,
                        'bank_account_number'=> $bank_number,
                        'updated_at'         => $now,
                    ],
                    ['id' => (int) $aff['id']],
                    ['%s','%s','%s','%s','%s'],
                    ['%d']
                );
                $aff = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM {$this->affiliates_table} WHERE id = %d LIMIT 1", (int) $aff['id']),
                    ARRAY_A
                );
            }
        }

        $mode = $aff['dashboard_mode'];
        if ($mode === 'default') {
            $mode = $this->options['default_dashboard_mode'];
        }
        if ($mode !== 'advanced') {
            $mode = 'simple';
        }

        $sums = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    SUM(CASE WHEN status = 'pending' OR status = 'exported' THEN commission_amount ELSE 0 END) AS balance,
                    SUM(CASE WHEN status = 'exported' OR status = 'paid' THEN commission_amount ELSE 0 END) AS total_paid,
                    SUM(CASE WHEN status != 'void' THEN commission_amount ELSE 0 END) AS total_earned
                 FROM {$this->commissions_table}
                 WHERE affiliate_id = %d",
                (int) $aff['id']
            ),
            ARRAY_A
        );
        $balance = $sums && $sums['balance'] !== null ? (float) $sums['balance'] : 0;
        $total_paid = $sums && $sums['total_paid'] !== null ? (float) $sums['total_paid'] : 0;
        $total_earned = $sums && $sums['total_earned'] !== null ? (float) $sums['total_earned'] : 0;

        $uid = $aff['uid'];
        $ref_url = $this->build_referral_url($uid);

        $qr_url = wp_nonce_url(
            add_query_arg(
                ['action' => 'wcs_download_affiliate_qr', 'affiliate_id' => (int) $aff['id']],
                admin_url('admin-ajax.php')
            ),
            'wcs_download_affiliate_qr_' . (int) $aff['id']
        );

        ob_start();
        ?>
        <div class="wcs-aff-dashboard wcs-aff-dashboard-<?php echo esc_attr($mode); ?>">
            <h2><?php esc_html_e('Affiliate dashboard', 'wcs-affiliates'); ?></h2>
            <p><strong><?php echo esc_html($aff['name']); ?></strong> (<?php echo esc_html($aff['email']); ?>)</p>

            <h3><?php esc_html_e('Your referral link', 'wcs-affiliates'); ?></h3>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:15px;">
                <input type="text" id="wcs_aff_ref_url_<?php echo (int)$aff['id']; ?>" readonly value="<?php echo esc_attr($ref_url); ?>" style="flex:1; min-width:250px; padding:8px;" onclick="this.select();" />
                <button type="button" class="button" onclick="wcs_aff_copy_link_<?php echo (int)$aff['id']; ?>()"><?php esc_html_e('Copy', 'wcs-affiliates'); ?></button>
                <a href="<?php echo esc_url($qr_url); ?>" class="button"><?php esc_html_e('Download QR', 'wcs-affiliates'); ?></a>
            </div>
            <script>
            function wcs_aff_copy_link_<?php echo (int)$aff['id']; ?>() {
                var copyText = document.getElementById("wcs_aff_ref_url_<?php echo (int)$aff['id']; ?>");
                copyText.select();
                copyText.setSelectionRange(0, 99999);
                navigator.clipboard.writeText(copyText.value).then(function() {
                    alert("<?php echo esc_js(__('Copied to clipboard!', 'wcs-affiliates')); ?>");
                }, function(err) {
                    console.error('Async: Could not copy text: ', err);
                });
            }
            </script>

            <h3><?php esc_html_e('Earnings overview', 'wcs-affiliates'); ?></h3>
            <ul>
                <li><?php printf(esc_html__('Pending / upcoming payouts: %s', 'wcs-affiliates'), '<strong>' . esc_html(number_format($balance, 2)) . '</strong>'); ?></li>
                <li><?php printf(esc_html__('Total paid so far: %s', 'wcs-affiliates'), '<strong>' . esc_html(number_format($total_paid, 2)) . '</strong>'); ?></li>
                <li><?php printf(esc_html__('Total earned (all time): %s', 'wcs-affiliates'), '<strong>' . esc_html(number_format($total_earned, 2)) . '</strong>'); ?></li>
            </ul>

            <?php if (!empty($this->options['allow_edit_payout'])) : ?>
                <h3><?php esc_html_e('Payout details', 'wcs-affiliates'); ?></h3>
                <form method="post">
                    <?php wp_nonce_field('wcs_aff_payout_update'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e('Nequi phone', 'wcs-affiliates'); ?></th>
                            <td><input type="text" name="nequi_phone" value="<?php echo esc_attr($aff['nequi_phone']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Bank name', 'wcs-affiliates'); ?></th>
                            <td><input type="text" name="bank_name" value="<?php echo esc_attr($aff['bank_name']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Bank account type', 'wcs-affiliates'); ?></th>
                            <td><input type="text" name="bank_account_type" value="<?php echo esc_attr($aff['bank_account_type']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Bank account number', 'wcs-affiliates'); ?></th>
                            <td><input type="text" name="bank_account_number" value="<?php echo esc_attr($aff['bank_account_number']); ?>" /></td>
                        </tr>
                    </table>
                    <p><button type="submit" name="wcs_aff_payout_update" class="button button-primary"><?php esc_html_e('Save payout details', 'wcs-affiliates'); ?></button></p>
                </form>
            <?php endif; ?>

            <?php if ($mode === 'advanced') : ?>
                <h3><?php esc_html_e('Recent orders', 'wcs-affiliates'); ?></h3>
                <?php
                $recent = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM {$this->commissions_table}
                         WHERE affiliate_id = %d
                         ORDER BY created_at DESC
                         LIMIT 25",
                        (int) $aff['id']
                    ),
                    ARRAY_A
                );
                if (empty($recent)) : ?>
                    <p><?php esc_html_e('No orders yet.', 'wcs-affiliates'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'wcs-affiliates'); ?></th>
                            <th><?php esc_html_e('Order', 'wcs-affiliates'); ?></th>
                            <th><?php esc_html_e('Order total', 'wcs-affiliates'); ?></th>
                            <th><?php esc_html_e('Commission', 'wcs-affiliates'); ?></th>
                            <th><?php esc_html_e('Status', 'wcs-affiliates'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent as $c) :
                            $order_link = get_edit_post_link($c['order_id']);
                            ?>
                            <tr>
                                <td><?php echo esc_html($c['created_at']); ?></td>
                                <td>
                                    <?php if ($order_link) : ?>
                                        <a href="<?php echo esc_url($order_link); ?>">#<?php echo (int) $c['order_id']; ?></a>
                                    <?php else : ?>
                                        #<?php echo (int) $c['order_id']; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(number_format((float) $c['order_total'], 2)); ?></td>
                                <td><?php echo esc_html(number_format((float) $c['commission_amount'], 2)); ?></td>
                                <td><?php echo esc_html($c['status']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

register_activation_hook(__FILE__, ['WCS_Affiliate_Agents', 'activate']);
add_action('plugins_loaded', ['WCS_Affiliate_Agents', 'instance']);