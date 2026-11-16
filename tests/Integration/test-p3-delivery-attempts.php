<?php
/**
 * Integration test P3-1: Delivery attempts, max retry limit (3), MarkDelivered, ReceiveReturned & HandedOver guard.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\DeliveryOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_p3(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test P3-1: Delivery Attempts, Retry Limit & RTO ===\n";

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$admin = get_user_by('login', 'admin');
$adminId = $admin ? $admin->ID : 1;
wp_set_current_user($adminId);

$service = Services::orderOps();
Services::engazConnection()->disconnect();

$createShippedOrder = static function (string $trk) use ($productId, $service, $adminId): \WC_Order {
    $product = wc_get_product($productId);
    $product->set_stock_quantity(10);
    $product->save();

    $order = wc_create_order();
    $itemId = $order->add_product($product, 1);
    $order->set_payment_method('cod');
    $order->set_status('processing');
    $order->calculate_totals();
    $order->save();

    $id = $order->get_id();
    $service->logCall($id, $adminId, CallOutcome::Answered, 'OK');
    $service->runEvent($id, OrderEvent::Confirm, $adminId);
    $service->assign($id, $adminId, $adminId);
    $service->runEvent($id, OrderEvent::StartPreparing, $adminId);
    $service->savePreparedItems($id, $adminId, [$itemId]);
    $service->runEvent($id, OrderEvent::FinishPreparing, $adminId);
    $service->saveShipmentDetails($id, $adminId, 'Bosta', $trk);
    $service->runEvent($id, OrderEvent::Ship, $adminId);

    return $order;
};

// 1. Fail 3 times -> blocked at 3 -> ReceiveReturned
$order1 = $createShippedOrder('P3-TRK-01');
$id1 = $order1->get_id();

$rFailNoReason = $service->runEvent($id1, OrderEvent::MarkDeliveryFailed, $adminId, '');
assert_p3(!$rFailNoReason->ok && $rFailNoReason->code === 'REASON_REQUIRED', "MarkDeliveryFailed requires reason");

assert_p3($service->runEvent($id1, OrderEvent::MarkDeliveryFailed, $adminId, 'Attempt 1 failed')->ok, "1st MarkDeliveryFailed succeeds");
$ops1 = Services::repository()->find($id1);
assert_p3($ops1?->status === OrderStatus::DeliveryFailed && $ops1->deliveryAttempts === 1, "Attempt 1 increments delivery_attempts to 1");

assert_p3($service->runEvent($id1, OrderEvent::RetryDelivery, $adminId)->ok, "RetryDelivery #1 succeeds");
assert_p3($service->runEvent($id1, OrderEvent::MarkDeliveryFailed, $adminId, 'Attempt 2 failed')->ok, "2nd MarkDeliveryFailed succeeds");
assert_p3($service->runEvent($id1, OrderEvent::RetryDelivery, $adminId)->ok, "RetryDelivery #2 succeeds");
assert_p3($service->runEvent($id1, OrderEvent::MarkDeliveryFailed, $adminId, 'Attempt 3 failed')->ok, "3rd MarkDeliveryFailed succeeds");

$ops1At3 = Services::repository()->find($id1);
assert_p3($ops1At3?->deliveryAttempts === 3, "delivery_attempts reached 3", "actual: " . ($ops1At3?->deliveryAttempts ?? -1));

$rRetryBlocked = $service->runEvent($id1, OrderEvent::RetryDelivery, $adminId);
assert_p3(!$rRetryBlocked->ok && $rRetryBlocked->code === 'MAX_DELIVERY_ATTEMPTS_REACHED', "RetryDelivery blocked at max 3 attempts", "code: {$rRetryBlocked->code}");

$deliveryRows1 = Services::deliveryLog()->forOrder($id1);
assert_p3(count($deliveryRows1) === 3 && $deliveryRows1[0]['attempt_no'] === 3 && $deliveryRows1[0]['outcome'] === DeliveryOutcome::Failed, "DeliveryAttemptLog recorded 3 failed attempts");

assert_p3($service->runEvent($id1, OrderEvent::ReceiveReturned, $adminId)->ok, "ReceiveReturned succeeds after failed attempts");
assert_p3(Services::repository()->find($id1)?->status === OrderStatus::ReturnedToOrigin, "Order moved to RETURNED_TO_ORIGIN");

// 2. Fail once -> Retry -> MarkDelivered
$order2 = $createShippedOrder('P3-TRK-02');
$id2 = $order2->get_id();

assert_p3($service->runEvent($id2, OrderEvent::MarkDeliveryFailed, $adminId, 'Postponed by customer')->ok, "Order 2: 1st fail succeeds");
assert_p3($service->runEvent($id2, OrderEvent::RetryDelivery, $adminId)->ok, "Order 2: Retry succeeds");
assert_p3($service->runEvent($id2, OrderEvent::MarkDelivered, $adminId)->ok, "Order 2: MarkDelivered succeeds");
assert_p3(Services::repository()->find($id2)?->status === OrderStatus::Delivered, "Order 2 moved to DELIVERED");

$deliveryRows2 = Services::deliveryLog()->forOrder($id2);
assert_p3(
    count($deliveryRows2) === 2
    && $deliveryRows2[0]['outcome'] === DeliveryOutcome::Delivered
    && $deliveryRows2[0]['attempt_no'] === 2
    && $deliveryRows2[1]['outcome'] === DeliveryOutcome::Failed,
    "Order 2 delivery log recorded FAILED attempt #1 and DELIVERED attempt #2"
);

// 3. HandedOver guard blocks Phase 3 events
$handed = wc_create_order();
$handed->set_payment_method('cod');
$handed->set_status('processing');
$handed->save();
$hid = $handed->get_id();
$service->logCall($hid, $adminId, CallOutcome::Answered, 'OK');
$service->runEvent($hid, OrderEvent::Confirm, $adminId);
Services::repository()->recordHandover($hid, gmdate('Y-m-d\TH:i:s\Z'));

$rHandedDel = $service->runEvent($hid, OrderEvent::MarkDelivered, $adminId);
assert_p3(!$rHandedDel->ok && $rHandedDel->code === 'FORBIDDEN', "HandedOver order blocks Phase 3 events");

// Cleanup
$order1->delete(true);
$order2->delete(true);
$handed->delete(true);
$p = wc_get_product($productId);
$p->set_stock_quantity(10);
$p->save();

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
