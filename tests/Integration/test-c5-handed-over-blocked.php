<?php
/**
 * Test C-5: Handed-over Orders Block All Operational Events
 * Once an order is handed over to EngazPRO:
 * - ModePolicy::allows returns false for all events
 * - runEvent returns an error (FORBIDDEN)
 * - WooCommerce order status is NOT altered
 * - No effects or transitions occur in the plugin
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Services\ActionResult;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_c5(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test C-5: Handed-over Orders Blocked ===\n";

global $wpdb;

// Create test order
$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$product = wc_get_product($productId);

$order = wc_create_order();
$order->add_product($product, 1);
$order->set_billing_first_name('Sami');
$order->set_billing_last_name('Youssef');
$order->set_billing_phone('01055554444');
$order->calculate_totals();
$order->save();
$orderId = $order->get_id();

$adminUser = get_user_by('login', 'admin');
$adminId = $adminUser ? $adminUser->ID : 1;

// Simulate handover on this order
$opsRepo = Services::repository();
$ops = $opsRepo->ensure($orderId);
$opsRepo->recordHandover($orderId, gmdate('Y-m-d H:i:s'));

$freshOps = $opsRepo->find($orderId);
assert_c5($freshOps !== null && $freshOps->isHandedOver(), "Order is confirmed as handed over");

// Set operational status to Confirmed (the state when handover occurs)
$opsRepo->compareAndSetStatus($orderId, $freshOps->status, \Anoofy\Woo\Core\Order\OrderStatus::Confirmed);
$freshOps = $opsRepo->find($orderId);

$wooStatusBefore = $order->get_status();

// Try various operational events: all must be blocked!
$eventsToTest = [
    OrderEvent::Reject,
    OrderEvent::CancelDirect,
    OrderEvent::Confirm,
    OrderEvent::StartPreparing,
    OrderEvent::Ship,
];

foreach ($eventsToTest as $event) {
    $result = Services::orderOps()->runEvent($orderId, $event, $adminId, 'Attempt on handed over order');
    assert_c5(!$result->ok, "Event {$event->value} was blocked on handed-over order");
    assert_c5($result->code === ActionResult::FORBIDDEN, "Error code is FORBIDDEN for {$event->value}", "actual: {$result->code}");
}

// Try assign: must also be blocked on handed-over order!
$assignResult = Services::orderOps()->assign($orderId, $adminId, $adminId);
assert_c5(!$assignResult->ok, "Assignment was blocked on handed-over order");
assert_c5($assignResult->code === ActionResult::FORBIDDEN, "Assignment error code is FORBIDDEN", "actual: {$assignResult->code}");

// Verify WooCommerce order status did not change from events
$freshOrder = wc_get_order($orderId);
$wooStatusAfter = $freshOrder ? $freshOrder->get_status() : '';
assert_c5($wooStatusBefore === $wooStatusAfter, "WooCommerce status remained unchanged from events", "status: {$wooStatusAfter}");

// Test: EngazPRO cancels order in WooCommerce via REST ($order->update_status('cancelled'))
// onWooCancelled must ignore handed-over orders:
// 1) Operational status in anoofy_order_ops does NOT change (stays CONFIRMED)
// 2) No note about "put back on the shelf" added
// 3) No audit restock / woo_cancelled logged
$order->update_status('cancelled', 'Cancelled by EngazPRO sync');

$opsAfterCancel = $opsRepo->find($orderId);
assert_c5($opsAfterCancel->status === \Anoofy\Woo\Core\Order\OrderStatus::Confirmed, "Operational status remained CONFIRMED after WooCommerce cancellation", "status: {$opsAfterCancel->status->value}");

$notes = wc_get_order_notes(['order_id' => $orderId]);
$hasShelfNote = false;
foreach ($notes as $note) {
    if (str_contains($note->content, 'shelf') || str_contains($note->content, 'الرف')) {
        $hasShelfNote = true;
        break;
    }
}
assert_c5(!$hasShelfNote, "No 'put back on the shelf' note added on handed-over order cancellation");

$auditTable = \Anoofy\Woo\Database\Schema::table($wpdb->prefix, 'audit_log');
$auditRows = $wpdb->get_results($wpdb->prepare(
    "SELECT action FROM {$auditTable} WHERE order_id = %d AND action IN ('order.woo_cancelled', 'order.woo_cancelled_needs_decision', 'order.stock_restocked')",
    $orderId
));
assert_c5(count($auditRows) === 0, "No cancellation/restock audit logged for handed-over order", "count: " . count($auditRows));

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
