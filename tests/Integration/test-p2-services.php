<?php
/**
 * Integration test for Phase 2 Services & WooCommerce edit hooks (RED -> GREEN).
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Services\Services;

global $wpdb;
$hasFailed = false;

function assert_p2s(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test Phase 2 Services: Prep, Edit Guard, Cancel Restock & Ship ===\n";

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$product = wc_get_product($productId);

$admin = get_user_by('login', 'admin');
$adminId = $admin ? $admin->ID : 1;
wp_set_current_user($adminId);

$service = Services::orderOps();

// Ensure standalone mode for Phase 2 tests
Services::engazConnection()->disconnect();

// Helper to create confirmed, assigned order with reduced stock
$createConfirmedOrder = static function () use ($productId, $service, $adminId): array {
    $freshProduct = wc_get_product($productId);
    $freshProduct->set_stock_quantity(10);
    $freshProduct->save();

    $order = wc_create_order();
    $itemId = $order->add_product($freshProduct, 1);
    $order->set_payment_method('cod');
    $order->set_status('processing');
    $order->calculate_totals();
    $order->save();
    wc_reduce_stock_levels($order->get_id());

    $orderId = $order->get_id();
    $service->logCall($orderId, $adminId, CallOutcome::Answered, 'Confirmed');
    $service->runEvent($orderId, OrderEvent::Confirm, $adminId);
    $service->assign($orderId, $adminId, $adminId);

    return [$order, $itemId];
};

// 1. Preparation cycle & FinishPreparing guard
[$order1, $item1] = $createConfirmedOrder();
$id1 = $order1->get_id();

$rStart = $service->runEvent($id1, OrderEvent::StartPreparing, $adminId);
assert_p2s($rStart->ok, "StartPreparing succeeds on assigned CONFIRMED order");

$rFinishEarly = $service->runEvent($id1, OrderEvent::FinishPreparing, $adminId);
assert_p2s(!$rFinishEarly->ok && $rFinishEarly->code === 'LINES_NOT_PREPARED', "FinishPreparing blocked when lines not prepared", "code: {$rFinishEarly->code}");

$rPrep = $service->savePreparedItems($id1, $adminId, [$item1]);
assert_p2s($rPrep->ok, "savePreparedItems succeeds");

$rFinishOk = $service->runEvent($id1, OrderEvent::FinishPreparing, $adminId);
assert_p2s($rFinishOk->ok, "FinishPreparing succeeds once all lines are prepared");

$ops1 = Services::repository()->find($id1);
assert_p2s($ops1 !== null && $ops1->status === OrderStatus::ReadyToShip, "Order is now READY_TO_SHIP");

// 2. Edit order after READY_TO_SHIP -> reopens to PREPARING + edited_after_preparing = 1
$freshOrder1 = wc_get_order($id1);
$freshItem = $freshOrder1->get_item($item1);
if ($freshItem instanceof \WC_Order_Item_Product) {
    $freshItem->set_quantity(2);
    $freshItem->save();
}
$freshOrder1->calculate_totals();
$freshOrder1->save();

$ops1AfterEdit = Services::repository()->find($id1);
assert_p2s($ops1AfterEdit !== null && $ops1AfterEdit->status === OrderStatus::Preparing, "Woo item edit reopens READY_TO_SHIP back to PREPARING", "status: " . ($ops1AfterEdit?->status?->value ?? 'null'));
assert_p2s($ops1AfterEdit !== null && $ops1AfterEdit->editedAfterPreparing === true, "edited_after_preparing flag is set to true");
assert_p2s(Services::preparedItems()->forOrder($id1) === [], "Prepared items cleared after order edit");

// Re-mark item prepared, confirm repreparation, then finish
$service->savePreparedItems($id1, $adminId, [$item1]);
$rBlockedByEdit = $service->runEvent($id1, OrderEvent::FinishPreparing, $adminId);
assert_p2s(!$rBlockedByEdit->ok && $rBlockedByEdit->code === 'REPREPARATION_NOT_CONFIRMED', "FinishPreparing blocked until repreparation confirmed", "code: {$rBlockedByEdit->code}");

$rConfirmReprep = $service->confirmRepreparation($id1, $adminId);
assert_p2s($rConfirmReprep->ok, "confirmRepreparation succeeds");

$rFinishAfterReprep = $service->runEvent($id1, OrderEvent::FinishPreparing, $adminId);
assert_p2s($rFinishAfterReprep->ok, "FinishPreparing succeeds after repreparation confirmed");

// 3. Shipment details & Ship
$rShipNoDetails = $service->runEvent($id1, OrderEvent::Ship, $adminId);
assert_p2s(!$rShipNoDetails->ok && $rShipNoDetails->code === 'SHIPMENT_DETAILS_REQUIRED', "Ship blocked when courier/tracking missing", "code: {$rShipNoDetails->code}");

$rSaveShip = $service->saveShipmentDetails($id1, $adminId, 'Bosta', 'BOSTA-12345');
assert_p2s($rSaveShip->ok, "saveShipmentDetails succeeds");

$rShipOk = $service->runEvent($id1, OrderEvent::Ship, $adminId);
assert_p2s($rShipOk->ok, "Ship succeeds once courier and tracking saved");

$ops1Shipped = Services::repository()->find($id1);
assert_p2s($ops1Shipped !== null && $ops1Shipped->status === OrderStatus::Shipped && $ops1Shipped->courier === 'Bosta' && $ops1Shipped->trackingNumber === 'BOSTA-12345', "Order is SHIPPED with Bosta / BOSTA-12345");

// 4. RequestCancel while preparing -> CANCELLATION_PENDING -> ConfirmRestocked
[$order2, $item2] = $createConfirmedOrder();
$id2 = $order2->get_id();
$service->runEvent($id2, OrderEvent::StartPreparing, $adminId);

$rReqCancel = $service->runEvent($id2, OrderEvent::RequestCancel, $adminId, 'Customer changed mind during packing');
assert_p2s($rReqCancel->ok, "RequestCancel succeeds while PREPARING");

$stockDuringPending = wc_get_product($productId)->get_stock_quantity();
$wooStatusPending = wc_get_order($id2)->get_status();
assert_p2s($stockDuringPending === 9 && $wooStatusPending === 'processing', "Stock NOT restored and Woo still processing during CANCELLATION_PENDING", "stock: {$stockDuringPending}, status: {$wooStatusPending}");

$rRestocked = $service->runEvent($id2, OrderEvent::ConfirmRestocked, $adminId);
assert_p2s($rRestocked->ok, "ConfirmRestocked succeeds");

$stockAfterRestock = wc_get_product($productId)->get_stock_quantity();
$wooStatusAfterRestock = wc_get_order($id2)->get_status();
$ops2Final = Services::repository()->find($id2);
assert_p2s($stockAfterRestock === 10 && $wooStatusAfterRestock === 'cancelled' && $ops2Final?->status === OrderStatus::Cancelled, "ConfirmRestocked cancels Woo order and restores stock to 10", "stock: {$stockAfterRestock}, woo: {$wooStatusAfterRestock}");

// Cleanup
$order1->delete(true);
$order2->delete(true);
$product->set_stock_quantity(10);
$product->save();

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
