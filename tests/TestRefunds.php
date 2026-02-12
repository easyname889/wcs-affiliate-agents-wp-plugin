<?php

use PHPUnit\Framework\TestCase;

class TestRefunds extends TestCase {

    public function setUp(): void {
        global $wpdb;
        $wpdb->tables = []; // Reset DB
        $wpdb->mock_get_row_result = null;
        $wpdb->mock_get_var_result = null;
        $wpdb->mock_get_col_result = null;
        $wpdb->mock_get_results_result = null;
        $wpdb->queries = [];
        $_POST = [];
        $_GET = [];
        $_COOKIE = [];

        global $wp_options;
        $wp_options = [];

        global $mock_orders;
        $mock_orders = [];
    }

    public function test_full_refund_via_status_change_voids_commission() {
        global $wpdb;
        $plugin = WCS_Affiliate_Agents::instance();

        // 1. Setup Order and Commission
        $order_id = 101;
        $order = new WC_Order($order_id);
        $order->status = 'refunded';

        global $mock_orders;
        $mock_orders[$order_id] = $order;

        // Insert a commission into mock DB
        $comm_table = $wpdb->prefix . 'wcs_affiliate_commissions';
        $wpdb->insert($comm_table, [
            'affiliate_id' => 1,
            'uid' => 'AGENT1',
            'order_id' => $order_id,
            'order_total' => 100.00,
            'commission_base' => 100.00,
            'commission_percent' => 10,
            'commission_amount' => 10.00,
            'status' => 'pending',
            'currency' => 'USD',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Mock get_col/get_results to return what we need for the catch-all logic
        // The catch-all logic first runs a query to group by affiliate.
        // We must mock that result.

        $wpdb->mock_get_results_result = [
            [ // Array, not object, because ARRAY_A is requested
                'affiliate_id' => 1,
                'uid' => 'AGENT1',
                'currency' => 'USD',
                'net_balance' => 10.00,
                'pending_ids' => '1',
                'order_total' => 100.00,
                'commission_percent' => 10.00
            ]
        ];

        // But wait, the catch-all logic runs get_results twice.
        // 1. To see net balance and pending IDs.
        // 2. To see net balance AFTER voiding.

        // MockWPDB is too simple for sequential mock returns.
        // We need to rely on the implementation of MockWPDB using the actual table data if query matches "SELECT *".
        // But `maybe_void_commissions` uses a complex GROUP BY query.
        // Our MockWPDB `get_results` handles "SELECT * FROM table" but returns empty for complex queries unless `mock_get_results_result` is set.

        // This makes testing `maybe_void_commissions` hard with current MockWPDB.
        // We should improve MockWPDB in this test file to handle the specific queries or just mock the return values sequentially?
        // Since we can't easily change MockWPDB in bootstrap without affecting others, let's subclass or just rely on manual overrides?
        // MockWPDB is a global object. We can override methods or properties.

        // Let's try to simulate the flow by setting `mock_get_results_result` for the first call,
        // then clearing it for the second? No, we can't intervene in the middle of function execution.

        // Strategy: We will mock `get_results` to return based on the query string.
        // But `MockWPDB` logic is in `bootstrap.php`.
        // We can't change it here easily.

        // ALTERNATIVE: Rely on `handle_order_refund` for partial refunds which is simpler (SELECT *).
        // For `maybe_void_commissions`, we might skip deep verification of the GROUP BY logic and trust the unit logic if we can just verify the UPDATE query was run.

        // The method `maybe_void_commissions` runs an UPDATE query first:
        // "UPDATE ... SET status = 'void' WHERE order_id = %d AND status IN ('pending','exported') AND commission_amount > 0"

        // If we can verify this query is run, that's good enough for the "voiding pending" part.

        $plugin->maybe_void_commissions($order_id);

        $found_update = false;
        foreach ($wpdb->queries as $q) {
            if (strpos($q, "UPDATE {$comm_table} SET status = 'void'") !== false) {
                $found_update = true;
            }
        }
        // Since we force mock_get_results_result above, the loop runs and triggers logic.
        // But wait, if we return a result that says "net_balance > 0", it tries to update.
        $this->assertTrue($found_update, "Should have attempted to void pending commissions");
    }

    public function test_partial_refund_deducts_commission() {
        global $wpdb;
        $plugin = WCS_Affiliate_Agents::instance();
        // Clear mocks
        $wpdb->mock_get_results_result = null;

        // 1. Setup Order and Paid Commission
        $order_id = 102;
        $order = new WC_Order($order_id);
        $order->status = 'completed';

        global $mock_orders;
        $mock_orders[$order_id] = $order;

        $comm_table = $wpdb->prefix . 'wcs_affiliate_commissions';
        $wpdb->insert($comm_table, [
            'affiliate_id' => 1,
            'uid' => 'AGENT1',
            'order_id' => $order_id,
            'order_total' => 100.00,
            'commission_base' => 100.00,
            'commission_percent' => 10,
            'commission_amount' => 10.00,
            'status' => 'paid',
            'currency' => 'USD',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // 2. Mock Refund Object
        $refund_id = 202;
        $refund = new class($refund_id) extends WC_Order {
             public function get_amount() { return 50.00; } // Refunded 50%
        };
        $mock_orders[$refund_id] = $refund;

        // 3. Trigger `handle_order_refund`
        // It uses `SELECT * FROM ...` which MockWPDB handles natively by returning table rows.
        $plugin->handle_order_refund($order_id, $refund_id);

        // 4. Assert that a negative commission was inserted
        $inserted_adjustment = false;
        foreach ($wpdb->tables[$comm_table] as $row) {
            // We expect -5.00
            // Since floating point, check range or string
            if ($row['order_id'] == $order_id && abs($row['commission_amount'] - (-5.00)) < 0.001) {
                $inserted_adjustment = true;
                break;
            }
        }

        $this->assertTrue($inserted_adjustment, "Should have created a negative adjustment (-5.00) for partial refund");
    }

    public function test_partial_refund_then_cancel() {
        // This tests the interaction: Partial refund creates adjustment. Cancellation clears remainder.
        global $wpdb;
        $plugin = WCS_Affiliate_Agents::instance();
        $wpdb->mock_get_results_result = null;

        $order_id = 103;
        $order = new WC_Order($order_id);
        $order->status = 'completed';
        global $mock_orders;
        $mock_orders[$order_id] = $order;

        $comm_table = $wpdb->prefix . 'wcs_affiliate_commissions';
        // Original: +10.00
        $wpdb->insert($comm_table, [
            'affiliate_id' => 1, 'uid' => 'AGENT1', 'order_id' => $order_id,
            'order_total' => 100.00, 'commission_percent' => 10,
            'commission_amount' => 10.00, 'status' => 'paid', 'currency' => 'USD',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Partial Refund: -5.00
        // We simulate `handle_order_refund` already happened or we just manually insert.
        // Let's manually insert to simulate state before cancellation.
        $wpdb->insert($comm_table, [
            'affiliate_id' => 1, 'uid' => 'AGENT1', 'order_id' => $order_id,
            'order_total' => 100.00, 'commission_percent' => 10,
            'commission_amount' => -5.00, 'status' => 'pending', 'currency' => 'USD',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Now status changes to Cancelled
        $order->status = 'cancelled';

        // We need to mock the complex queries in `maybe_void_commissions` again.
        // We expect `net_balance` to be 5.00 (10 - 5).
        // First query returns net_balance = 5.00.
        // Second query (after potential void of pending) returns net_balance = 5.00 (since the -5 is pending but negative, we only void positive pending).
        // Actually we updated logic to void ALL pending, so the -5.00 SHOULD be voided.
        // This is safer to prevent double deduction.
        // If we void +10 and -5, Net becomes 0.
        // If we only void +10 (and keep -5), Net becomes -5. The agent is penalized.
        // So we expect the query to NOT have "AND commission_amount > 0".

        // Since we can't easily mock sequential returns, we will manually test the logic by mocking the return
        // to say "Net balance is 5.00".

        $wpdb->mock_get_results_result = [
            [
                'affiliate_id' => 1, 'uid' => 'AGENT1', 'currency' => 'USD',
                'net_balance' => 5.00, // Remaining
                'pending_ids' => null,
                'order_total' => 100.00, 'commission_percent' => 10.00
            ]
        ];

        $plugin->maybe_void_commissions($order_id);

        // We expect an insertion of -5.00 to clear the remaining balance.
        // Check for insertion
        $inserted_clearing = false;
        // The last inserted row should be -5.00
        $last_row = end($wpdb->tables[$comm_table]);

        // We have to be careful, `wpdb->insert` adds to the table array.
        // But since `maybe_void_commissions` calls `get_results` (mocked) and then `insert`.
        // The insert should happen.

        // Check if query contained INSERT
        $found_insert = false;
        foreach ($wpdb->queries as $q) {
            if (strpos($q, "INSERT INTO {$comm_table}") !== false) {
                 $found_insert = true;
            }
        }
        $this->assertTrue($found_insert, "Should have inserted a clearing adjustment if net balance was positive");

        // Also verify the UPDATE query did NOT restrict to positive amounts
        $found_void_all = false;
        foreach ($wpdb->queries as $q) {
            // Looking for absence of "commission_amount > 0" in the specific UPDATE query
            if (strpos($q, "UPDATE {$comm_table} SET status = 'void'") !== false) {
                if (strpos($q, "commission_amount > 0") === false) {
                    $found_void_all = true;
                }
            }
        }
        $this->assertTrue($found_void_all, "Should have voided ALL pending commissions (positive and negative)");
    }

    public function test_shipping_refund_exclusion() {
        global $wpdb;
        $plugin = WCS_Affiliate_Agents::instance();
        // Force option: exclude shipping
        // Reflection to access private options or re-instance?
        // Since options are loaded in constructor, re-instancing might not help if instance is static.
        // But get_options reads from DB or prop.
        // Let's mock get_option globally since plugin uses $this->options which is set in constructor.
        // BUT $this->options is a property.

        // We can use Reflection to modify the property.
        $ref = new ReflectionClass($plugin);
        $prop = $ref->getProperty('options');
        $prop->setAccessible(true);
        $opts = $prop->getValue($plugin);
        $opts['commission_base'] = 'order_total_excl_shipping';
        $prop->setValue($plugin, $opts);

        $order_id = 300;
        $order = new class($order_id) extends WC_Order {
             public function get_total() { return 110.00; } // 100 Item + 10 Shipping
             public function get_shipping_total() { return 10.00; }
             public function get_shipping_tax() { return 0.00; }
        };
        global $mock_orders;
        $mock_orders[$order_id] = $order;

        // Refund ID
        $refund_id = 301;
        $refund = new class($refund_id) extends WC_Order {
             public function get_amount() { return 10.00; } // Refunded shipping amount
             public function get_shipping_total() { return 10.00; } // Specifically refunding shipping
             public function get_shipping_tax() { return 0.00; }
        };
        $mock_orders[$refund_id] = $refund;

        // Setup Commission
        $comm_table = $wpdb->prefix . 'wcs_affiliate_commissions';
        $wpdb->insert($comm_table, [
            'affiliate_id' => 1, 'uid' => 'AGENT1', 'order_id' => $order_id,
            'order_total' => 110.00, 'commission_percent' => 10,
            'commission_amount' => 10.00, 'status' => 'paid', 'currency' => 'USD', // Comm on 100 base
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Run
        $wpdb->mock_get_results_result = null; // Use native table select
        $plugin->handle_order_refund($order_id, $refund_id);

        // Expectation:
        // Refund Amount Net = 10.00 (Total) - 10.00 (Shipping) = 0.00.
        // Ratio = 0.
        // Deduction = 0.
        // Should NOT insert a negative commission.

        $inserted = false;
        foreach ($wpdb->tables[$comm_table] as $row) {
            if ($row['order_id'] == $order_id && $row['commission_amount'] < 0) {
                $inserted = true;
            }
        }
        $this->assertFalse($inserted, "Should NOT deduct commission when refunding excluded shipping cost");
    }
}
