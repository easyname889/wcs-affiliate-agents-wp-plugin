<?php

use PHPUnit\Framework\TestCase;

class TestWCSAffiliateAgents extends TestCase {

    public function setUp(): void {
        global $wpdb;
        $wpdb->tables = []; // Reset DB
        $wpdb->mock_get_row_result = null; // Reset mock row
        $wpdb->mock_get_var_result = null; // Reset mock var
        $_POST = [];
        $_GET = [];
        $_COOKIE = [];

        // Reset options
        global $wp_options;
        $wp_options = [];
    }

    public function test_create_affiliate_succeeds_without_email() {
        $plugin = WCS_Affiliate_Agents::instance();

        // Simulate POST request to create new affiliate
        $_GET['action'] = 'new';
        $_POST['wcs_aff_save'] = 1;
        $_POST['name'] = 'Test Agent';
        $_POST['email'] = ''; // Missing email
        $_POST['commission_percent'] = 10;

        // Mock Admin
        $GLOBALS['is_admin'] = true;

        try {
            $plugin->handle_save_affiliate();
            $this->fail("Should have redirected");
        } catch (Exception $e) {
            $this->assertStringContainsString('WP_REDIRECT', $e->getMessage());
            $this->assertStringContainsString('action=edit', $e->getMessage());
            $this->assertStringContainsString('msg=saved', $e->getMessage());
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wcs_affiliates';

        // Check if inserted
        $rows = $wpdb->tables[$table] ?? [];
        $this->assertCount(1, $rows, "Should have inserted a row even if email is empty");
        $row = $rows[0];

        // We expect a generated email now
        $this->assertStringContainsString('affiliate_', $row['email']);
        $this->assertStringContainsString('@', $row['email']);
    }

    public function test_commission_flow() {
        global $wpdb;
        $plugin = WCS_Affiliate_Agents::instance();

        // 1. Setup Affiliate
        // We manually insert into the mock table because our MockWPDB uses simple array storage
        // but maybe_capture_affiliate_uid uses get_row which we mock separately.
        $aff_data = [
            'uid' => 'AGENT1',
            'status' => 'active',
            'commission_percent' => 10,
            'id' => 1
        ];

        // Mock get_row for capture_affiliate_uid
        $wpdb->mock_get_row_result = $aff_data;

        // 2. Capture UID from URL
        $_GET['AGENT1'] = ''; // ?AGENT1

        // Reset admin flag for frontend check
        $GLOBALS['is_admin'] = false;

        $plugin->maybe_capture_affiliate_uid();

        // Since we can't check setcookie, assume it worked if logic passed.
        // We simulate the cookie being set for the next step.
        $_COOKIE['wcs_aff_uid'] = 'AGENT1';

        // 3. Attach Order Affiliate
        $order = new WC_Order(100);
        $plugin->attach_order_affiliate($order, []);

        $this->assertEquals('AGENT1', $order->get_meta('_wcs_affiliate_uid'));
        $this->assertEquals(1, $order->get_meta('_wcs_affiliate_id'));

        // 4. Create Commission
        global $mock_orders;
        $mock_orders = [100 => $order];

        // We need wpdb->get_var to return false (no existing commission)
        $wpdb->mock_get_var_result = false;

        // We need get_row to return the affiliate when queried by ID in maybe_create_commission
        // MockWPDB returns same row for any get_row call if set, which works here.
        $wpdb->mock_get_row_result = $aff_data;

        $plugin->maybe_create_commission(100);

        // Assert Commission Inserted
        $comm_table = $wpdb->prefix . 'wcs_affiliate_commissions';
        $this->assertArrayHasKey($comm_table, $wpdb->tables);
        $this->assertCount(1, $wpdb->tables[$comm_table]);
        $comm = $wpdb->tables[$comm_table][0];

        $this->assertEquals(1, $comm['affiliate_id']);
        $this->assertEquals(10.0, $comm['commission_amount']); // 10% of 100
    }
}

// Helper to inject mocks into WC_Order
function wc_get_order($id) {
    global $mock_orders;
    return $mock_orders[$id] ?? false;
}
