<?php
/**
 * Test C-2: Standalone Mode Order Confirmation
 * An order confirmed in Standalone mode:
 * - NO outbox row created in anoofy_outbox
 * - engaz_handover_at remains NULL
 * - Standard operational buttons/lifecycle remain active
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Mode\OperatingMode;
use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_c2(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test C-2: Standalone Mode Confirmation ===\n";

global $wpdb;

// Ensure Standalone mode
Services::engazConnection()->disconnect();
assert_c2(Services::mode() === OperatingMode::Standalone, "Mode is Standalone");

// Create a test order
$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$product = wc_get_product($productId);

$order = wc_create_order();
$order->add_product($product, 1);
$order->set_billing_first_name('Karim');
$order->set_billing_last_name('Nader');
$order->set_billing_phone('01012345678');
$order->calculate_totals();
$order->save();
$orderId = $order->get_id();

$adminUser = get_user_by('login', 'admin');
$adminId = $adminUser ? $adminUser->ID : 1;

// Log call first (required for confirmation)
Services::orderOps()->logCall($orderId, $adminId, CallOutcome::Answered, 'Customer confirmed');

// Confirm order
$confirmResult = Services::orderOps()->runEvent($orderId, OrderEvent::Confirm, $adminId);
assert_c2($confirmResult->ok, "Order confirmed in Standalone mode");

$ops = Services::repository()->find($orderId);
assert_c2($ops !== null && $ops->status === OrderStatus::Confirmed, "Ops status is Confirmed");
assert_c2($ops !== null && !$ops->isHandedOver(), "engaz_handover_at is NULL in Standalone mode");

// Check outbox table: no row should exist for this order
$outboxTable = $wpdb->prefix . 'anoofy_outbox';
$outboxCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$outboxTable} WHERE wc_order_id = %d", $orderId));
assert_c2($outboxCount === 0, "No outbox row created for standalone order", "count: {$outboxCount}");

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
