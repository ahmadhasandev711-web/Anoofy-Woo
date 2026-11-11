<?php
/**
 * Test C-6: Orders Confirmed Pre-Connection Remain Standalone
 * Orders confirmed before the merchant connects to EngazPRO:
 * - Retain engaz_handover_at = NULL after connection is established
 * - No retroactive outbox rows created
 * - Only orders confirmed AFTER connection is active undergo handover
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Mode\OperatingMode;
use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_c6(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test C-6: Pre-connection Orders Stay Standalone ===\n";

global $wpdb;

// 1. Ensure Standalone mode
Services::engazConnection()->disconnect();
assert_c6(Services::mode() === OperatingMode::Standalone, "Initial mode is Standalone");

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$product = wc_get_product($productId);

// Create order 1 in Standalone mode
$order1 = wc_create_order();
$order1->add_product($product, 1);
$order1->set_billing_first_name('Pre');
$order1->set_billing_last_name('Connect');
$order1->calculate_totals();
$order1->save();
$order1Id = $order1->get_id();

$adminUser = get_user_by('login', 'admin');
$adminId = $adminUser ? $adminUser->ID : 1;

Services::orderOps()->logCall($order1Id, $adminId, CallOutcome::Answered, 'Answered');
Services::orderOps()->runEvent($order1Id, OrderEvent::Confirm, $adminId);

$ops1Before = Services::repository()->find($order1Id);
assert_c6($ops1Before !== null && $ops1Before->status === OrderStatus::Confirmed, "Order 1 confirmed in Standalone");
assert_c6(!$ops1Before->isHandedOver(), "Order 1 is not handed over");

// 2. Now Connect to EngazPRO
update_option('anoofy_engaz_connection', [
    'api' => 'https://engazpro.example.com/api/webhooks/anoofy/events',
    'kid' => '01JCTESTKID12345678901234AB',
    'secret' => 'f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a1f0e9d8c7b6a5f4e3d2c1b0a9f8e7',
    'connected_at' => gmdate('Y-m-d\TH:i:s\Z'),
], false);
assert_c6(Services::mode() === OperatingMode::Connected, "Plugin is now Connected");

// Check Order 1: MUST remain NOT handed over
$ops1After = Services::repository()->find($order1Id);
assert_c6(!$ops1After->isHandedOver(), "Pre-connection Order 1 remains NOT handed over");

$outboxTable = $wpdb->prefix . 'anoofy_outbox';
$outboxCount1 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$outboxTable} WHERE wc_order_id = %d", $order1Id));
assert_c6($outboxCount1 === 0, "No outbox row was retroactively created for Order 1");

// 3. Create Order 2 and confirm in Connected mode -> MUST be handed over!
$order2 = wc_create_order();
$order2->add_product($product, 1);
$order2->set_billing_first_name('Post');
$order2->set_billing_last_name('Connect');
$order2->calculate_totals();
$order2->save();
$order2Id = $order2->get_id();

Services::orderOps()->logCall($order2Id, $adminId, CallOutcome::Answered, 'Answered');
Services::orderOps()->runEvent($order2Id, OrderEvent::Confirm, $adminId);

$ops2 = Services::repository()->find($order2Id);
assert_c6($ops2 !== null && $ops2->isHandedOver(), "Post-connection Order 2 is handed over");

$outboxCount2 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$outboxTable} WHERE wc_order_id = %d", $order2Id));
assert_c6($outboxCount2 === 1, "Outbox row created for Order 2");

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
