<?php
/**
 * Integration test P3-2: Phase 3 role boundaries (shipping vs warehouse), OrderBox delivery UI,
 * delivery history rendering, and OrdersTable shipment column.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Admin\OrderBox;
use Anoofy\Woo\Admin\OrdersTable;
use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_p3u(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test P3-2: Phase 3 Roles, OrderBox Delivery UI & OrdersTable ===\n";

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$admin = get_user_by('login', 'admin');
$adminId = $admin ? $admin->ID : 1;

$shName = 'anoofy_sh_p3_' . wp_generate_password(5, false);
$shId = wp_create_user($shName, wp_generate_password(12), $shName . '@example.com');
(new WP_User($shId))->set_role('anoofy_shipping');

$whName = 'anoofy_wh_p3_' . wp_generate_password(5, false);
$whId = wp_create_user($whName, wp_generate_password(12), $whName . '@example.com');
(new WP_User($whId))->set_role('anoofy_warehouse');

$service = Services::orderOps();
Services::engazConnection()->disconnect();

$order = wc_create_order();
$itemId = $order->add_product(wc_get_product($productId), 1);
$order->set_payment_method('cod');
$order->set_status('processing');
$order->calculate_totals();
$order->save();
$orderId = $order->get_id();

$service->logCall($orderId, $adminId, CallOutcome::Answered, 'OK');
$service->runEvent($orderId, OrderEvent::Confirm, $adminId);
$service->assign($orderId, $adminId, $adminId);
$service->runEvent($orderId, OrderEvent::StartPreparing, $adminId);
$service->savePreparedItems($orderId, $adminId, [$itemId]);
$service->runEvent($orderId, OrderEvent::FinishPreparing, $adminId);
$service->saveShipmentDetails($orderId, $adminId, 'Aramex', 'ARX-P3');
$service->runEvent($orderId, OrderEvent::Ship, $adminId);

// 1. Shipping user sees MARK_DELIVERED / MARK_DELIVERY_FAILED when SHIPPED
wp_set_current_user($shId);
ob_start();
OrderBox::renderOpsBox(wc_get_order($orderId), 'ops');
$shShippedHtml = (string) ob_get_clean();
assert_p3u(str_contains($shShippedHtml, 'MARK_DELIVERED') && str_contains($shShippedHtml, 'MARK_DELIVERY_FAILED'), "Shipping user sees MarkDelivered and MarkDeliveryFailed buttons when SHIPPED");

// Warehouse user cannot record delivery
assert_p3u(!$service->runEvent($orderId, OrderEvent::MarkDeliveryFailed, $whId, 'test')->ok, "Warehouse blocked from MarkDeliveryFailed");

// Fail 3 times as Shipping user
$service->runEvent($orderId, OrderEvent::MarkDeliveryFailed, $shId, 'Reason 1');

ob_start();
OrderBox::renderOpsBox(wc_get_order($orderId), 'ops');
$shFailed1Html = (string) ob_get_clean();
assert_p3u(str_contains($shFailed1Html, 'RETRY_DELIVERY') && !str_contains($shFailed1Html, 'RECEIVE_RETURNED'), "Shipping user sees RETRY_DELIVERY and NOT RECEIVE_RETURNED after 1 failure");
assert_p3u(str_contains($shFailed1Html, 'Reason 1'), "OrderBox delivery attempts table renders failure reason");

// Verify OrdersTable shipment column shows failed attempts count
$table = new OrdersTable(['DELIVERY_FAILED'], null, [$orderId]);
$table->prepare_items();
$colShipment = $table->items !== [] ? $table->column_shipment($table->items[0]) : '';
assert_p3u(str_contains($colShipment, 'Failed attempts: 1') && str_contains($colShipment, 'ARX-P3'), "OrdersTable shipment column shows tracking and failed attempts count");

$service->runEvent($orderId, OrderEvent::RetryDelivery, $shId);
$service->runEvent($orderId, OrderEvent::MarkDeliveryFailed, $shId, 'Reason 2');
$service->runEvent($orderId, OrderEvent::RetryDelivery, $shId);
$service->runEvent($orderId, OrderEvent::MarkDeliveryFailed, $shId, 'Reason 3');

// At 3/3 failures: Shipping sees max attempts warning & no RETRY_DELIVERY; Warehouse sees RECEIVE_RETURNED
ob_start();
OrderBox::renderOpsBox(wc_get_order($orderId), 'ops');
$shMaxHtml = (string) ob_get_clean();
assert_p3u(!str_contains($shMaxHtml, 'RETRY_DELIVERY') && str_contains($shMaxHtml, '3 / 3'), "RETRY_DELIVERY hidden when max 3 attempts reached");

wp_set_current_user($whId);
ob_start();
OrderBox::renderOpsBox(wc_get_order($orderId), 'ops');
$whRtoHtml = (string) ob_get_clean();
assert_p3u(str_contains($whRtoHtml, 'RECEIVE_RETURNED'), "Warehouse user sees RECEIVE_RETURNED button");

assert_p3u($service->runEvent($orderId, OrderEvent::ReceiveReturned, $whId)->ok, "Warehouse executes ReceiveReturned");

// Cleanup
wp_set_current_user($adminId);
$order->delete(true);
wp_delete_user($shId);
wp_delete_user($whId);

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
