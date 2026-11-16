<?php
/**
 * Integration test P5-2: Returns & RTO UI (OrderBox inspection/approve/open forms, ReturnsPage tabs/table, OrdersTable badges).
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Admin\OrderBox;
use Anoofy\Woo\Admin\OrdersTable;
use Anoofy\Woo\Admin\ReturnsPage;
use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_p5u(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test P5-2: Returns & RTO UI, ReturnsPage & Badges ===\n";

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$admin = get_user_by('login', 'admin');
$adminId = $admin ? $admin->ID : 1;

$mkUser = static function (string $prefix, string $role): int {
    $login = $prefix . '_' . wp_generate_password(5, false);
    $uid = wp_create_user($login, wp_generate_password(12), $login . '@example.com');
    (new WP_User($uid))->set_role($role);

    return (int) $uid;
};

$whId = $mkUser('p5u_wh', 'anoofy_warehouse');
$omId = $mkUser('p5u_om', 'anoofy_order_manager');
$csId = $mkUser('p5u_cs', 'anoofy_customer_service');

wp_set_current_user($adminId);
$opsService = Services::orderOps();
$returnService = Services::returnService();
Services::engazConnection()->disconnect();

$product = wc_get_product($productId);
$product->set_manage_stock(true);
$product->set_stock_quantity(10);
$product->save();

$order = wc_create_order();
$itemId = $order->add_product($product, 2);
$order->set_payment_method('cod');
$order->set_status('processing');
$order->calculate_totals();
$order->save();
wc_reduce_stock_levels($order->get_id());
$orderId = $order->get_id();

$opsService->logCall($orderId, $adminId, CallOutcome::Answered, 'OK');
$opsService->runEvent($orderId, OrderEvent::Confirm, $adminId);
$opsService->assign($orderId, $adminId, $adminId);
$opsService->runEvent($orderId, OrderEvent::StartPreparing, $adminId);
$opsService->savePreparedItems($orderId, $adminId, [$itemId]);
$opsService->runEvent($orderId, OrderEvent::FinishPreparing, $adminId);
$opsService->saveShipmentDetails($orderId, $adminId, 'Aramex', 'ARX-RTO-UI');
$opsService->runEvent($orderId, OrderEvent::Ship, $adminId);
$opsService->runEvent($orderId, OrderEvent::MarkDeliveryFailed, $adminId, 'Refused by customer');
$opsService->runEvent($orderId, OrderEvent::ReceiveReturned, $whId, 'RTO parcel returned');

$returns = Services::returns()->forOrder($orderId);
$retId = (int) $returns[0]['id'];

// 1. Warehouse renders OrderBox: sees inspection inputs, no approve button
wp_set_current_user($whId);
ob_start();
OrderBox::renderOpsBox($order, 'ops');
$whHtml = (string) ob_get_clean();
assert_p5u(str_contains($whHtml, 'anoofy_inspect_return'), "Warehouse sees inspection form on OrderBox");
assert_p5u(str_contains($whHtml, 'inspect[' . $itemId . '][restock_qty]'), "Warehouse sees per-item restock input");
assert_p5u(!str_contains($whHtml, 'anoofy_approve_return'), "Warehouse does NOT see approve button");

// 2. ReturnsPage lists the RTO return
ob_start();
ReturnsPage::render();
$pageHtml = (string) ob_get_clean();
assert_p5u(str_contains($pageHtml, 'Returns &amp; RTO') || str_contains($pageHtml, 'Returns & RTO'), "ReturnsPage renders heading");
assert_p5u(str_contains($pageHtml, '#' . $orderId), "ReturnsPage links to order #" . $orderId);

// 3. Warehouse inspects -> OrderManager sees approve button
$returnService->saveInspection($retId, $whId, [$itemId => ['restock_qty' => 1, 'damaged_qty' => 1, 'note' => 'Half damaged']]);

wp_set_current_user($omId);
ob_start();
OrderBox::renderOpsBox($order);
$omHtml = (string) ob_get_clean();
assert_p5u(str_contains($omHtml, 'anoofy_approve_return'), "OrderManager sees approve button once return is inspected");

// 4. Approve -> OrderBox displays stock movements & OrdersTable renders Approved badge
$returnService->approveReturn($retId, $omId);

ob_start();
OrderBox::renderOpsBox($order);
$approvedHtml = (string) ob_get_clean();
assert_p5u(str_contains($approvedHtml, 'Stock movements') && str_contains($approvedHtml, 'Restocked'), "Approved OrderBox displays Stock movements section");

$table = new OrdersTable(['RETURNED_TO_ORIGIN'], null, null);
$table->prepare_items();
$foundOps = null;
foreach ($table->items as $row) {
    if ($row['ops']->orderId === $orderId) {
        $foundOps = $row;
        break;
    }
}
$statusCell = $foundOps !== null ? $table->column_ops_status($foundOps) : '';
assert_p5u(str_contains($statusCell, 'anoofy-badge--return-approved'), "OrdersTable status column renders Approved return badge");

// 5. Delivered order displays Customer Return form for CustomerService
$deliveredOrder = wc_create_order();
$delItemId = $deliveredOrder->add_product(wc_get_product($productId), 1);
$deliveredOrder->set_payment_method('cod');
$deliveredOrder->set_status('processing');
$deliveredOrder->calculate_totals();
$deliveredOrder->save();
$delId = $deliveredOrder->get_id();

wp_set_current_user($adminId);
$opsService->logCall($delId, $adminId, CallOutcome::Answered, 'OK');
$opsService->runEvent($delId, OrderEvent::Confirm, $adminId);
$opsService->assign($delId, $adminId, $adminId);
$opsService->runEvent($delId, OrderEvent::StartPreparing, $adminId);
$opsService->savePreparedItems($delId, $adminId, [$delItemId]);
$opsService->runEvent($delId, OrderEvent::FinishPreparing, $adminId);
$opsService->saveShipmentDetails($delId, $adminId, 'Bosta', 'BST-DEL-UI');
$opsService->runEvent($delId, OrderEvent::Ship, $adminId);
$opsService->runEvent($delId, OrderEvent::MarkDelivered, $adminId);

wp_set_current_user($csId);
ob_start();
OrderBox::renderOpsBox($deliveredOrder);
$csHtml = (string) ob_get_clean();
assert_p5u(str_contains($csHtml, 'anoofy_open_return') && str_contains($csHtml, 'return_qty[' . $delItemId . ']'), "CustomerService sees Open customer return form on Delivered order");

// Cleanup
wp_set_current_user($adminId);
wp_delete_user($whId);
wp_delete_user($omId);
wp_delete_user($csId);
$order->delete(true);
$deliveredOrder->delete(true);
$p = wc_get_product($productId);
$p->set_stock_quantity(10);
$p->save();

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
