<?php
/**
 * Spike S-11: Reject and Cancel flows, stock restoration, and audit re-entrancy guard.
 *
 * Requirements:
 * 1. Stock decreases to 9 upon order placement, and restores to 10 (not 11, not 9) after cancel.
 * 2. Reject & Cancel from OrderOpsService sets Woo status to 'cancelled', ops status to REJECTED/CANCELLED,
 *    and records only 1 audit log entry for the action (onWooCancelled ignored via re-entrancy guard).
 * 3. Direct cancellation from WooCommerce of a NEW order moves ops status to CANCELLED with order.woo_cancelled in audit.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Identity\Role;
use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Services\Services;

global $wpdb;
$hasFailed = false;

function assert_s11(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Spike S-11: Reject, Cancel, Stock & Re-entrancy ===\n";

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$product = wc_get_product($productId);

$admin = get_user_by('login', 'admin');
$adminId = $admin ? $admin->ID : 1;
wp_set_current_user($adminId);

$service = Services::orderOps();
$auditTable = $wpdb->prefix . 'anoofy_audit_log';
$opsTable = $wpdb->prefix . 'anoofy_order_ops';

// Helper to create order with stock reduction
$createOrderWithStock = function () use ($product): \WC_Order {
    // Reset product stock to 10 first
    $product->set_stock_quantity(10);
    $product->save();

    $order = wc_create_order();
    $order->add_product($product, 1);
    $order->set_payment_method('cod');
    $order->set_status('processing');
    $order->calculate_totals();
    $order->save();
    wc_reduce_stock_levels($order->get_id());

    return $order;
};

// --------------------------------------------------------------------------
// Part 1: OrderOpsService CancelDirect (from NEW)
// --------------------------------------------------------------------------
echo "--- Part 1: CancelDirect via OrderOpsService ---\n";
$order1 = $createOrderWithStock();
$id1 = $order1->get_id();

$freshProduct = wc_get_product($productId);
$stockBeforeCancel = $freshProduct->get_stock_quantity();
assert_s11($stockBeforeCancel === 9, "S-11: Stock reduced to 9 after order creation", "stock: {$stockBeforeCancel}");

$cancelResult = $service->runEvent($id1, OrderEvent::CancelDirect, $adminId, 'Customer cancelled order');
assert_s11($cancelResult->ok, "S-11: OrderOpsService CancelDirect succeeded");

$freshOrder1 = wc_get_order($id1);
assert_s11($freshOrder1->get_status() === 'cancelled', "S-11: WooCommerce order status is 'cancelled'", "status: {$freshOrder1->get_status()}");

$freshProductAfter = wc_get_product($productId);
$stockAfterCancel = $freshProductAfter->get_stock_quantity();
assert_s11($stockAfterCancel === 10, "S-11: Stock restored exactly to 10 (not 11 or 9)", "stock: {$stockAfterCancel}");

$ops1 = Services::repository()->find($id1);
assert_s11($ops1 !== null && $ops1->status === OrderStatus::Cancelled, "S-11: Ops status is CANCELLED", "status: " . ($ops1?->status?->value ?? 'null'));

$auditRows1 = $wpdb->get_results($wpdb->prepare("SELECT action FROM {$auditTable} WHERE order_id = %d AND action LIKE 'order.%'", $id1));
$cancelAuditActions = array_map(static fn($r) => $r->action, $auditRows1);
$hasCancelDirect = in_array('order.cancel_direct', $cancelAuditActions, true);
$hasWooCancelled = in_array('order.woo_cancelled', $cancelAuditActions, true);
assert_s11($hasCancelDirect && !$hasWooCancelled, "S-11: Audit log has order.cancel_direct and onWooCancelled was ignored", "actions: " . implode(', ', $cancelAuditActions));

// --------------------------------------------------------------------------
// Part 2: OrderOpsService Reject (from UNDER_REVIEW)
// --------------------------------------------------------------------------
echo "--- Part 2: Reject via OrderOpsService ---\n";
$order2 = $createOrderWithStock();
$id2 = $order2->get_id();

$freshProduct2 = wc_get_product($productId);
$stockBeforeReject = $freshProduct2->get_stock_quantity();
assert_s11($stockBeforeReject === 9, "S-11: Stock reduced to 9 before reject", "stock: {$stockBeforeReject}");

// Log a call to move from NEW to UNDER_REVIEW
$service->logCall($id2, $adminId, CallOutcome::NoAnswer, 'Customer unreachable');
$ops2_review = Services::repository()->find($id2);
assert_s11($ops2_review !== null && $ops2_review->status === OrderStatus::UnderReview, "S-11: Order moved to UNDER_REVIEW after call");

$rejectResult = $service->runEvent($id2, OrderEvent::Reject, $adminId, 'Customer rejected');
assert_s11($rejectResult->ok, "S-11: OrderOpsService Reject succeeded");

$freshOrder2 = wc_get_order($id2);
assert_s11($freshOrder2->get_status() === 'cancelled', "S-11: WooCommerce order status is 'cancelled'", "status: {$freshOrder2->get_status()}");

$freshProduct2After = wc_get_product($productId);
$stockAfterReject = $freshProduct2After->get_stock_quantity();
assert_s11($stockAfterReject === 10, "S-11: Stock restored exactly to 10 after reject", "stock: {$stockAfterReject}");

$ops2 = Services::repository()->find($id2);
assert_s11($ops2 !== null && $ops2->status === OrderStatus::Rejected, "S-11: Ops status is REJECTED", "status: " . ($ops2?->status?->value ?? 'null'));

$auditRows2 = $wpdb->get_results($wpdb->prepare("SELECT action FROM {$auditTable} WHERE order_id = %d AND action LIKE 'order.%'", $id2));
$rejectAuditActions = array_map(static fn($r) => $r->action, $auditRows2);
$hasReject = in_array('order.reject', $rejectAuditActions, true);
$hasWooCancelled2 = in_array('order.woo_cancelled', $rejectAuditActions, true);
assert_s11($hasReject && !$hasWooCancelled2, "S-11: Audit log has order.reject and onWooCancelled was ignored", "actions: " . implode(', ', $rejectAuditActions));

// --------------------------------------------------------------------------
// Part 3: Direct cancellation from WooCommerce (order in NEW)
// --------------------------------------------------------------------------
echo "--- Part 3: Direct WooCommerce Cancellation ---\n";
$order3 = wc_create_order();
$order3->set_payment_method('cod');
$order3->set_status('processing');
$order3->save();
$id3 = $order3->get_id();

$ops3_initial = Services::repository()->find($id3);
assert_s11($ops3_initial !== null && $ops3_initial->status === OrderStatus::New, "S-11: Initial ops status is NEW");

// Cancel directly via WooCommerce API
$order3->update_status('cancelled', 'Direct cancel from WooCommerce');

$ops3_final = Services::repository()->find($id3);
assert_s11($ops3_final !== null && $ops3_final->status === OrderStatus::Cancelled, "S-11: Direct cancel moves ops status to CANCELLED", "status: " . ($ops3_final?->status?->value ?? 'null'));

$auditRows3 = $wpdb->get_results($wpdb->prepare("SELECT action FROM {$auditTable} WHERE order_id = %d AND action = 'order.woo_cancelled'", $id3));
assert_s11(count($auditRows3) === 1, "S-11: Audit log recorded order.woo_cancelled for direct cancel", "count: " . count($auditRows3));

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
