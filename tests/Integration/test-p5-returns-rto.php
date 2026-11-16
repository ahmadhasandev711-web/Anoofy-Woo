<?php
/**
 * Integration test P5-1: RTO automatic opening, per-item inspection, idempotent restock approval,
 * customer returns on delivered orders, role enforcement, and HandedOver guard.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Core\Returns\ReturnItemCondition;
use Anoofy\Woo\Core\Returns\ReturnSource;
use Anoofy\Woo\Core\Returns\ReturnStatus;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_p5(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test P5-1: Returns, RTO Effects, Inspection & Idempotent Stock Restock ===\n";

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$admin = get_user_by('login', 'admin');
$adminId = $admin ? $admin->ID : 1;

$mkUser = static function (string $prefix, string $role): int {
    $login = $prefix . '_' . wp_generate_password(5, false);
    $uid = wp_create_user($login, wp_generate_password(12), $login . '@example.com');
    (new WP_User($uid))->set_role($role);

    return (int) $uid;
};

$whId = $mkUser('p5_wh', 'anoofy_warehouse');
$omId = $mkUser('p5_om', 'anoofy_order_manager');
$csId = $mkUser('p5_cs', 'anoofy_customer_service');

wp_set_current_user($adminId);
$opsService = Services::orderOps();
$returnService = Services::returnService();
Services::engazConnection()->disconnect();

$createShippedOrder = static function (int $qty, string $trk) use ($productId, $opsService, $adminId): array {
    $product = wc_get_product($productId);
    $product->set_manage_stock(true);
    $product->set_stock_quantity(10);
    $product->save();

    $order = wc_create_order();
    $itemId = $order->add_product($product, $qty);
    $order->set_payment_method('cod');
    $order->set_status('processing');
    $order->calculate_totals();
    $order->save();
    wc_reduce_stock_levels($order->get_id());

    $id = $order->get_id();
    $opsService->logCall($id, $adminId, CallOutcome::Answered, 'OK');
    $opsService->runEvent($id, OrderEvent::Confirm, $adminId);
    $opsService->assign($id, $adminId, $adminId);
    $opsService->runEvent($id, OrderEvent::StartPreparing, $adminId);
    $opsService->savePreparedItems($id, $adminId, [$itemId]);
    $opsService->runEvent($id, OrderEvent::FinishPreparing, $adminId);
    $opsService->saveShipmentDetails($id, $adminId, 'Bosta', $trk);
    $opsService->runEvent($id, OrderEvent::Ship, $adminId);

    return [$order, $itemId];
};

// 1. RTO cycle: qty=4 -> stock=6 -> delivery fail -> warehouse ReceiveReturned
[$order1, $item1Id] = $createShippedOrder(4, 'P5-RTO-01');
$id1 = $order1->get_id();
assert_p5(wc_get_product($productId)->get_stock_quantity() === 6, "Order 1 reduced stock from 10 to 6");

$opsService->runEvent($id1, OrderEvent::MarkDeliveryFailed, $adminId, 'Customer unreachable');
assert_p5($opsService->runEvent($id1, OrderEvent::ReceiveReturned, $whId)->ok, "Warehouse marks DeliveryFailed -> ReturnedToOrigin");
assert_p5(Services::repository()->find($id1)?->status === OrderStatus::ReturnedToOrigin, "Order 1 status is RETURNED_TO_ORIGIN");

$freshOrder1 = wc_get_order($id1);
assert_p5($freshOrder1->get_meta('_anoofy_rto', true) === 'yes', "AddRtoTag set _anoofy_rto meta on Woo order");

$order1Returns = Services::returns()->forOrder($id1);
assert_p5(count($order1Returns) === 1, "OpenRtoReturn created 1 RTO return record automatically");
$rtoId = (int) $order1Returns[0]['id'];
assert_p5(
    $order1Returns[0]['source'] === ReturnSource::Rto
    && $order1Returns[0]['status'] === ReturnStatus::Open
    && (int) $order1Returns[0]['items'][0]['qty_returned'] === 4
    && $order1Returns[0]['items'][0]['condition_status'] === ReturnItemCondition::Uninspected,
    "RTO return opened with qty=4 and uninspected item"
);
assert_p5(wc_get_product($productId)->get_stock_quantity() === 6, "Stock remains 6 until inspection + approval");

// Approve before inspection fails
$rApproveEarly = $returnService->approveReturn($rtoId, $omId);
assert_p5(!$rApproveEarly->ok && $rApproveEarly->code === 'INSPECTION_REQUIRED', "Approval blocked before inspection", "code={$rApproveEarly->code}");

// CS blocked from inspecting
$rCsInspect = $returnService->saveInspection($rtoId, $csId, [$item1Id => ['restock_qty' => 4, 'damaged_qty' => 0]]);
assert_p5(!$rCsInspect->ok && $rCsInspect->code === 'FORBIDDEN', "CustomerService blocked from inspecting");

// Incomplete inspection (only 2 of 4 accounted for) cannot be approved
assert_p5($returnService->saveInspection($rtoId, $whId, [$item1Id => ['restock_qty' => 2, 'damaged_qty' => 0]])->ok, "Warehouse saves partial inspection (2 of 4)");
$rIncomplete = $returnService->approveReturn($rtoId, $omId);
assert_p5(!$rIncomplete->ok && $rIncomplete->code === 'INSPECTION_INCOMPLETE', "Approval blocked when inspection is incomplete", "code={$rIncomplete->code}");

// Complete inspection: 3 restock + 1 damaged
assert_p5(
    $returnService->saveInspection($rtoId, $whId, [$item1Id => ['restock_qty' => 3, 'damaged_qty' => 1, 'note' => '1 box crushed']])->ok,
    "Warehouse completes inspection (3 restock, 1 damaged)"
);

// Warehouse cannot approve
$rWhApprove = $returnService->approveReturn($rtoId, $whId);
assert_p5(!$rWhApprove->ok && $rWhApprove->code === 'FORBIDDEN', "Warehouse blocked from approving return");

// OrderManager approves -> restores ONLY 3 (6 -> 9)
$rApprove = $returnService->approveReturn($rtoId, $omId);
assert_p5($rApprove->ok, "OrderManager approves inspected RTO return", "code={$rApprove->code}");
$stockAfterRto = wc_get_product($productId)->get_stock_quantity();
assert_p5($stockAfterRto === 9, "Stock increased by ONLY 3 healthy units (6 -> 9, 1 damaged excluded)", "stock={$stockAfterRto}");

$movements = Services::stockMovements()->forOrder($id1);
assert_p5(
    count($movements) === 2,
    "StockMovementRepository logged both restock(3) and damaged(1) movements",
    "count=" . count($movements)
);

// Re-approval blocked + idempotent (no double restock)
$rDouble = $returnService->approveReturn($rtoId, $omId);
assert_p5(!$rDouble->ok && $rDouble->code === 'ALREADY_APPROVED', "Double approval blocked");
assert_p5(wc_get_product($productId)->get_stock_quantity() === 9, "Stock stays 9 after re-approve attempt");

// 2. Customer return on Delivered order: qty=2 -> stock=8 -> CS opens return qty=1 -> inspect -> approve -> stock=9
[$order2, $item2Id] = $createShippedOrder(2, 'P5-CUST-02');
$id2 = $order2->get_id();
$opsService->runEvent($id2, OrderEvent::MarkDelivered, $adminId);

$rOver = $returnService->openCustomerReturn($id2, $csId, [$item2Id => 3], 'Too many');
assert_p5(!$rOver->ok && $rOver->code === 'INVALID_QUANTITY', "Customer return blocked when qty > ordered");

assert_p5($returnService->openCustomerReturn($id2, $csId, [$item2Id => 1], 'Wrong size')->ok, "CS opens customer return for 1 of 2 items");

// Remaining returnable qty is now 1 (cannot request 2)
$rOver2 = $returnService->openCustomerReturn($id2, $csId, [$item2Id => 2], 'Another return');
assert_p5(!$rOver2->ok && $rOver2->code === 'INVALID_QUANTITY', "Second customer return cannot exceed remaining qty (1)");

$custReturns = Services::returns()->forOrder($id2);
$custRetId = (int) $custReturns[0]['id'];
assert_p5($custReturns[0]['source'] === ReturnSource::Customer, "Customer return recorded with source=customer");
assert_p5($returnService->saveInspection($custRetId, $whId, [$item2Id => ['restock_qty' => 1, 'damaged_qty' => 0]])->ok, "Warehouse inspects customer return (1 restock)");
assert_p5($returnService->approveReturn($custRetId, $omId)->ok, "OrderManager approves customer return");
assert_p5(wc_get_product($productId)->get_stock_quantity() === 9, "Customer return restored 1 unit (8 -> 9)");

// 3. HandedOver order blocked from opening customer return
[$order3, $item3Id] = $createShippedOrder(1, 'P5-ENGAZ-03');
$id3 = $order3->get_id();
$opsService->runEvent($id3, OrderEvent::MarkDelivered, $adminId);
Services::repository()->recordHandover($id3, gmdate('Y-m-d\TH:i:s\Z'));
$rEngaz = $returnService->openCustomerReturn($id3, $adminId, [$item3Id => 1], 'Blocked');
assert_p5(!$rEngaz->ok && $rEngaz->code === 'HANDED_OVER_TO_ENGAZ', "HandedOver order blocks opening customer return", "code={$rEngaz->code}");

// Cleanup
wp_delete_user($whId);
wp_delete_user($omId);
wp_delete_user($csId);
$order1->delete(true);
$order2->delete(true);
$order3->delete(true);
$p = wc_get_product($productId);
$p->set_stock_quantity(10);
$p->save();

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
