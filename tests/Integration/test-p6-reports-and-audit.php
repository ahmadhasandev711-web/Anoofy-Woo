<?php
/**
 * Integration test P6-1: KPI Reports, Audit Log search/screen, Role isolation & CourierGateway adapters.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Admin\AuditLogPage;
use Anoofy\Woo\Admin\OrderBox;
use Anoofy\Woo\Admin\ReportsPage;
use Anoofy\Woo\Core\Connection\OrderHandoverItem;
use Anoofy\Woo\Core\Courier\AbstractLocalCourierGateway;
use Anoofy\Woo\Core\Courier\CourierRegistry;
use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_p6(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test P6-1: KPI Reports, Audit Log & CourierGateway ===\n";

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$admin = get_user_by('login', 'admin');
$adminId = $admin ? $admin->ID : 1;

$mkUser = static function (string $prefix, string $role): int {
    $login = $prefix . '_' . wp_generate_password(5, false);
    $uid = wp_create_user($login, wp_generate_password(12), $login . '@example.com');
    (new WP_User($uid))->set_role($role);

    return (int) $uid;
};

$omId = $mkUser('p6_om', 'anoofy_order_manager');
$csId = $mkUser('p6_cs', 'anoofy_customer_service');
$whId = $mkUser('p6_wh', 'anoofy_warehouse');

wp_set_current_user($adminId);
$opsService = Services::orderOps();
$reportService = Services::reportService();
Services::engazConnection()->disconnect();

// 1. Permission boundaries
assert_p6($reportService->buildDashboard($csId, 'all') === null, "CustomerService denied ReportService::buildDashboard()");
assert_p6($reportService->buildDashboard($whId, 'all') === null, "Warehouse denied ReportService::buildDashboard()");
assert_p6($reportService->buildDashboard($omId, 'all') !== null, "OrderManager allowed ReportService::buildDashboard()");

assert_p6(!user_can($omId, Action::AuditView->capability()), "OrderManager denied Action::AuditView capability");
assert_p6(user_can($adminId, Action::AuditView->capability()), "Owner/Admin allowed Action::AuditView capability");

// 2. Capture baseline KPIs
$base = $reportService->buildDashboard($omId, 'all');
assert_p6($base !== null, "Baseline KPI snapshot loaded");

$makeCodOrder = static function () use ($productId): array {
    $product = wc_get_product($productId);
    $product->set_manage_stock(true);
    $product->set_stock_quantity(10);
    $product->save();

    $order = wc_create_order();
    $itemId = $order->add_product($product, 1);
    $order->set_payment_method('cod');
    $order->set_status('processing');
    $order->calculate_totals();
    $order->save();

    return [$order, $itemId];
};

// Order 1 -> Delivered (COD outstanding)
[$order1, $item1] = $makeCodOrder();
$id1 = $order1->get_id();
$opsService->logCall($id1, $csId, CallOutcome::Answered, 'P6 answered');
$opsService->runEvent($id1, OrderEvent::Confirm, $csId);
$opsService->assign($id1, $adminId, $csId);
$opsService->runEvent($id1, OrderEvent::StartPreparing, $adminId);
$opsService->savePreparedItems($id1, $adminId, [$item1]);
$opsService->runEvent($id1, OrderEvent::FinishPreparing, $adminId);
$opsService->saveShipmentDetails($id1, $adminId, 'Bosta', 'BST-P6-1');
$opsService->runEvent($id1, OrderEvent::Ship, $adminId);
$opsService->runEvent($id1, OrderEvent::MarkDelivered, $adminId);

// Order 2 -> DeliveryFailed -> RTO
[$order2, $item2] = $makeCodOrder();
$id2 = $order2->get_id();
$opsService->logCall($id2, $csId, CallOutcome::Answered, 'P6 answered 2');
$opsService->runEvent($id2, OrderEvent::Confirm, $csId);
$opsService->assign($id2, $adminId, $csId);
$opsService->runEvent($id2, OrderEvent::StartPreparing, $adminId);
$opsService->savePreparedItems($id2, $adminId, [$item2]);
$opsService->runEvent($id2, OrderEvent::FinishPreparing, $adminId);
$opsService->saveShipmentDetails($id2, $adminId, 'Aramex', 'ARX-P6-2');
$opsService->runEvent($id2, OrderEvent::Ship, $adminId);
$opsService->runEvent($id2, OrderEvent::MarkDeliveryFailed, $adminId, 'Refused');
$opsService->runEvent($id2, OrderEvent::ReceiveReturned, $whId, 'RTO parcel');

// Order 3 -> Rejected
[$order3, $item3] = $makeCodOrder();
$id3 = $order3->get_id();
$opsService->logCall($id3, $csId, CallOutcome::NoAnswer, 'No answer');
$opsService->runEvent($id3, OrderEvent::Reject, $csId, 'Customer cancelled');

$after = $reportService->buildDashboard($omId, 'all');
assert_p6($after->totalOrders - $base->totalOrders === 3, "KPI totalOrders increased by 3");
assert_p6($after->confirmedOrders - $base->confirmedOrders === 2, "KPI confirmedOrders increased by 2");
assert_p6($after->rejectedOrders - $base->rejectedOrders === 1, "KPI rejectedOrders increased by 1");
assert_p6($after->deliveredOrders - $base->deliveredOrders === 1, "KPI deliveredOrders increased by 1");
assert_p6($after->returnedToOriginOrders - $base->returnedToOriginOrders === 1, "KPI returnedToOriginOrders increased by 1");
assert_p6($after->codOutstandingCount - $base->codOutstandingCount === 1, "KPI codOutstandingCount increased by 1");

$csAgentRow = null;
foreach ($after->perAgent as $row) {
    if ($row->userId === $csId) {
        $csAgentRow = $row;
        break;
    }
}
assert_p6(
    $csAgentRow !== null && $csAgentRow->callsLogged === 3 && $csAgentRow->answeredCalls === 2 && $csAgentRow->assignedOrders === 2,
    "Per-agent KPI tracked CS calls (3 total, 2 answered) and assigned orders (2)"
);

// Settle Order 1 -> outstanding count returns to baseline, settled count increments by 1
$cents1 = OrderHandoverItem::toCents((string) $order1->get_total());
assert_p6(Services::codSettlement()->settle($omId, 'Bosta', 'P6-SETTLE', [$id1 => $cents1])->ok, "Order 1 COD settled");
$afterSettle = $reportService->buildDashboard($omId, 'all');
assert_p6(
    $afterSettle->codOutstandingCount === $base->codOutstandingCount
    && ($afterSettle->codSettledCount - $base->codSettledCount) === 1,
    "Settling Order 1 shifts count from outstanding to settled"
);

// 3. ReportsPage UI rendering for OrderManager
wp_set_current_user($omId);
ob_start();
ReportsPage::render();
$reportsHtml = (string) ob_get_clean();
assert_p6(str_contains($reportsHtml, 'Operations Reports') && str_contains($reportsHtml, 'Per-agent performance'), "ReportsPage renders KPI cards and Per-agent table");

// 4. AuditLogPage filtering & OrderBox courier datalist
wp_set_current_user($adminId);
$auditRows = Services::auditLog()->search(null, null, $id1, 50, 0);
assert_p6(count($auditRows) >= 5, "AuditLog::search filters accurately by order ID", "rows=" . count($auditRows));

$_GET['filter_order_id'] = (string) $id1;
ob_start();
AuditLogPage::render();
$auditHtml = (string) ob_get_clean();
unset($_GET['filter_order_id']);
assert_p6(str_contains($auditHtml, '#' . $id1) && str_contains($auditHtml, 'cod.settled'), "AuditLogPage renders filtered entries for order #" . $id1);

// 5. CourierManager & OrderBox datalist
$cm = Services::courierManager();
assert_p6($cm->get('bosta') !== null && $cm->get('aramex') !== null && $cm->get('mylerz') !== null, "CourierManager registers default adapters");
$shipRes = $cm->buildShipmentForOrder('bosta', $order1, 'BST-GATEWAY-77');
assert_p6($shipRes->ok && $shipRes->courierLabel === 'Bosta' && $shipRes->trackingNumber === 'BST-GATEWAY-77', "CourierGateway builds valid ShipmentResult");

$customFilter = static function (CourierRegistry $registry): CourierRegistry {
    $registry->register(new class extends AbstractLocalCourierGateway {
        public function slug(): string { return 'custom_fast'; }
        public function label(): string { return 'CustomFast'; }
    });
    return $registry;
};
add_filter('anoofy_courier_gateways', $customFilter);
assert_p6($cm->get('custom_fast')?->label() === 'CustomFast', "CourierManager honors anoofy_courier_gateways filter");
remove_filter('anoofy_courier_gateways', $customFilter);

// OrderBox courier datalist visible on ReadyToShip order
[$orderRts, $itemRts] = $makeCodOrder();
$idRts = $orderRts->get_id();
$opsService->logCall($idRts, $adminId, CallOutcome::Answered, 'OK');
$opsService->runEvent($idRts, OrderEvent::Confirm, $adminId);
$opsService->assign($idRts, $adminId, $adminId);
$opsService->runEvent($idRts, OrderEvent::StartPreparing, $adminId);
$opsService->savePreparedItems($idRts, $adminId, [$itemRts]);
$opsService->runEvent($idRts, OrderEvent::FinishPreparing, $adminId);
ob_start();
OrderBox::renderOpsBox($orderRts);
$rtsHtml = (string) ob_get_clean();
assert_p6(str_contains($rtsHtml, 'anoofy-courier-list') && str_contains($rtsHtml, 'Mylerz'), "OrderBox renders CourierGateway datalist on ReadyToShip");

// Cleanup
wp_delete_user($omId);
wp_delete_user($csId);
wp_delete_user($whId);
$order1->delete(true);
$order2->delete(true);
$order3->delete(true);
$orderRts->delete(true);
$p = wc_get_product($productId);
$p->set_stock_quantity(10);
$p->save();

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
