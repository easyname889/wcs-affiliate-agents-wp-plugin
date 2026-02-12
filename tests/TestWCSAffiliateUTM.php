<?php

use PHPUnit\Framework\TestCase;

class TestWCSAffiliateUTM extends TestCase {

    public function setUp(): void {
        global $wpdb;
        $wpdb->tables = []; // Reset DB
        $wpdb->mock_get_row_result = null;
        $wpdb->mock_get_var_result = null;
        $_POST = [];
        $_GET = [];
        $_COOKIE = [];

        // Reset options global
        global $wp_options;
        $wp_options = [];

        // Mock global options with defaults + some UTM settings
        $wp_options['wcs_affiliate_agents_options'] = [
            'enable_utm' => 1,
            'utm_source' => 'global_source',
            'utm_medium' => 'global_medium',
            'utm_campaign' => 'global_campaign',
        ];
    }

    public function test_save_affiliate_stores_utm() {
        $plugin = WCS_Affiliate_Agents::instance();

        // Simulate POST request to create new affiliate with custom UTM
        $_GET['action'] = 'new';
        $_POST['wcs_aff_save'] = 1;
        $_POST['name'] = 'UTM Agent';
        $_POST['email'] = 'utm@example.com';
        $_POST['commission_percent'] = 10;
        $_POST['utm_source'] = 'agent_source';
        $_POST['utm_medium'] = 'agent_medium';
        $_POST['utm_campaign'] = 'agent_campaign';

        // Mock Admin
        $GLOBALS['is_admin'] = true;

        try {
            $plugin->handle_save_affiliate();
            $this->fail("Should have redirected");
        } catch (Exception $e) {
            $this->assertStringContainsString('WP_REDIRECT', $e->getMessage());
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wcs_affiliates';
        $rows = $wpdb->tables[$table] ?? [];
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertEquals('agent_source', $row['utm_source']);
        $this->assertEquals('agent_medium', $row['utm_medium']);
        $this->assertEquals('agent_campaign', $row['utm_campaign']);
    }

    public function test_referral_url_backward_compatibility() {
        global $wpdb;
        $plugin = WCS_Affiliate_Agents::instance();

        // 1. Create Old-Style Affiliate (Empty UTMs)
        $aff_id = 1;
        $user_id = 101;
        $uid = 'OLDAGENT';

        $aff_data = [
            'id' => $aff_id,
            'user_id' => $user_id,
            'uid' => $uid,
            'name' => 'Old Agent',
            'email' => 'old@example.com',
            'status' => 'active',
            'dashboard_mode' => 'simple',
            'commission_percent' => 10,
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '', // Empty means fallback to global
            // Optional fields
            'nequi_phone' => '',
            'bank_name' => '',
            'bank_account_type' => '',
            'bank_account_number' => '',
            // Add dummy sums because MockWPDB returns this same row for the 2nd get_row call (sums)
            'balance' => 0,
            'total_paid' => 0,
            'total_earned' => 0,
        ];

        // Mock DB table for get_results/etc if needed, though shortcode uses get_row
        $table = $wpdb->prefix . 'wcs_affiliates';
        $wpdb->tables[$table] = [$aff_data];

        // Mock get_row for shortcode
        // We need to support dynamic get_row because it looks up by user_id
        // Our MockWPDB::get_row just returns static $mock_get_row_result if set.
        // But let's check shortcode implementation:
        // $aff = $wpdb->get_row("SELECT * ... WHERE user_id = %d", ARRAY_A);
        // So we set the result:
        $wpdb->mock_get_row_result = $aff_data;

        // Mock Login
        $GLOBALS['current_user_id'] = $user_id;

        // Mock Options (Global)
        global $wp_options;
        $new_options = [
            'enable_utm' => 1,
            'utm_source' => 'global_src',
            'utm_medium' => 'global_med',
            'utm_campaign' => 'global_cam',
        ];
        $wp_options['wcs_affiliate_agents_options'] = $new_options;

        // Force update options on singleton
        $ref = new ReflectionClass($plugin);
        $prop = $ref->getProperty('options');
        $prop->setAccessible(true);
        $prop->setValue($plugin, $new_options);

        // Render Dashboard
        $output = $plugin->shortcode_affiliate_dashboard([]);

        // Verify URL contains GLOBAL parameters
        // The URL should be home_url/?UID&utm_source=global_src...
        $this->assertStringContainsString('utm_source=global_src', $output, "Should use global source for empty agent UTM");
        $this->assertStringContainsString('utm_medium=global_med', $output);
        $this->assertStringContainsString('utm_campaign=global_cam', $output);

        // 2. Update Affiliate with Custom UTMs
        $aff_data['utm_source'] = 'custom_src';
        $aff_data['utm_medium'] = 'custom_med';
        $aff_data['utm_campaign'] = 'custom_cam';
        $wpdb->mock_get_row_result = $aff_data; // Update mock return

        // Render Dashboard again
        $output = $plugin->shortcode_affiliate_dashboard([]);

        // Verify URL contains CUSTOM parameters
        $this->assertStringContainsString('utm_source=custom_src', $output, "Should use custom source when set");
        $this->assertStringContainsString('utm_medium=custom_med', $output);
        $this->assertStringContainsString('utm_campaign=custom_cam', $output);

        // Verify it DOES NOT contain global params overrides (assuming add_query_arg replaces or we constructed it right)
        $this->assertStringNotContainsString('utm_source=global_src', $output);
    }
}

// Mock helper for login
function get_current_user_id() {
    return isset($GLOBALS['current_user_id']) ? $GLOBALS['current_user_id'] : 0;
}
function is_user_logged_in() {
    return get_current_user_id() > 0;
}
