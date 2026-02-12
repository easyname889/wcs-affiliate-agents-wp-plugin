<?php

use PHPUnit\Framework\TestCase;

class TestAdminEditPageUTM extends TestCase {

    public function setUp(): void {
        global $wpdb;
        $wpdb->tables = []; // Reset DB
        $wpdb->mock_get_row_result = null;
        $_POST = [];
        $_GET = [];
        $_COOKIE = [];

        // Reset options global
        global $wp_options;
        $wp_options = [];

        // Mock global options with defaults
        $wp_options['wcs_affiliate_agents_options'] = [
            'enable_utm' => 1,
            'utm_source' => 'global_source',
            'utm_medium' => 'global_medium',
            'utm_campaign' => 'global_campaign',
        ];
    }

    public function test_admin_edit_page_shows_custom_utm_url() {
        global $wp_options;
        $plugin = WCS_Affiliate_Agents::instance();

        // 1. Setup Affiliate with Custom UTMs
        $aff_id = 123;
        $uid = 'TESTAGENT';
        $custom_campaign = 'custom_campaign_value';

        $aff_data = [
            'id' => $aff_id,
            'user_id' => 101,
            'uid' => $uid,
            'name' => 'Test Agent',
            'email' => 'test@example.com',
            'status' => 'active',
            'dashboard_mode' => 'simple',
            'commission_percent' => 10,
            'utm_source' => '', // Empty means fallback
            'utm_medium' => '', // Empty means fallback
            'utm_campaign' => $custom_campaign, // CUSTOM
            // Optional fields
            'phone' => '',
            'nequi_phone' => '',
            'bank_name' => '',
            'bank_account_type' => '',
            'bank_account_number' => '',
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00',
        ];

        // Mock DB table
        global $wpdb;
        $table = $wpdb->prefix . 'wcs_affiliates';
        $wpdb->tables[$table] = [$aff_data];
        $wpdb->mock_get_row_result = $aff_data;

        // Mock Admin Context
        $GLOBALS['is_admin'] = true;
        $GLOBALS['current_user_can_manage_woocommerce'] = true; // Helper mock needed
        $_GET['page'] = 'wcs_affiliates';
        $_GET['action'] = 'edit';
        $_GET['id'] = $aff_id;

        // Force update options on singleton
        $ref = new ReflectionClass($plugin);
        $prop = $ref->getProperty('options');
        $prop->setAccessible(true);
        $prop->setValue($plugin, $wp_options['wcs_affiliate_agents_options']);

        // Capture Output
        ob_start();
        $plugin->render_affiliates_page();
        $output = ob_get_clean();

        // Assertions
        // The form field value for utm_campaign should be the custom one
        $this->assertStringContainsString('value="' . $custom_campaign . '"', $output, "Form should show saved custom campaign");

        // The displayed Read-Only Referral URL should contain the custom campaign
        // Currently BROKEN: it will likely contain 'global_campaign' because of the bug
        $expected_param = 'utm_campaign=' . $custom_campaign;

        // This is the assertion that fails currently
        $this->assertStringContainsString($expected_param, $output, "Referral URL should contain custom campaign parameter");

        // It should NOT contain global campaign
        $this->assertStringNotContainsString('utm_campaign=global_campaign', $output, "Referral URL should NOT contain global campaign when custom is set");
    }
}

// Mock helpers

// Mock wp_nonce_field to avoid output
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = "_wpnonce", $referer = true, $echo = true) {}
}
if (!function_exists('settings_fields')) {
    function settings_fields($option_group) {}
}
if (!function_exists('do_settings_sections')) {
    function do_settings_sections($page) {}
}
if (!function_exists('submit_button')) {
    function submit_button($text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null) {}
}
