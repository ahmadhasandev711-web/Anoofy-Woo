<?php
/**
 * Integration test P4-1: COD Settlement CSV preview, batch settlement, WooCommerce date_paid update,
 * double-settlement prevention, role permission, and HandedOver protection.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Connection\OrderHandoverItem;
use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Core\Settlement\SettlementRowStatus;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_p4(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test P4-1: COD Settlement Service & WooCommerce date_paid ===\n";

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$admin = get_user_by('login', 'admin');
$adminId = $admin ? $admin->ID : 1;
wp_set_current_user($adminId);

$shName = 'anoofy_sh_p4_' . wp_generate_password(5, false);
$shId = wp_create_user($shName, wp_generate_password(12), $shName . '@example.com');
(new WP_User($shId))->set_role('anoofy_shipping');

$service = Services::orderOps();
Services::engazConnection()->disconnect();

$makeOrderInStage = static function (string $tracking, bool $delivered, bool $handedOver = false) use ($productId, $service, $adminId): \WC_Order {
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
    if ($handedOver) {
        Services::repository()->recordHandover($id, gmdate('Y-m-d\TH:i:s\Z'));
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'anoofy_order_ops', ['tracking_number' => $tracking], ['order_id' => $id]);
        return $order;
    }

    $service->assign($id, $adminId, $adminId);
    $service->runEvent($id, OrderEvent::StartPreparing, $adminId);
    $service->savePreparedItems($id, $adminId, [$itemId]);
    $service->runEvent($id, OrderEvent::FinishPreparing, $adminId);
    $service->saveShipmentDetails($id, $adminId, 'Bosta', $tracking);
    $service->runEvent($id, OrderEvent::Ship, $adminId);
    if ($delivered) {
        $service->runEvent($id, OrderEvent::MarkDelivered, $adminId);
    }

    return $order;
};

$o1 = $makeOrderInStage('P4-DEL-1', true);
$o2 = $makeOrderInStage('P4-DEL-2', true);
$oShip = $makeOrderInStage('P4-SHIP-1', false);
$oEngaz = $makeOrderInStage('P4-ENGAZ-1', false, true);

$totalStr1 = OrderHandoverItem::formatCents(OrderHandoverItem::toCents((string) $o1->get_total()));
$totalStr2 = OrderHandoverItem::formatCents(OrderHandoverItem::toCents((string) $o2->get_total()));

$csv = implode("\n", [
    'tracking_number,cod_amount,reference',
    "P4-DEL-1,{$totalStr1},BATCH-P4",
    "P4-DEL-2,{$totalStr2},BATCH-P4",
    "P4-SHIP-1,{$totalStr1},BATCH-P4",
    "P4-ENGAZ-1,{$totalStr1},BATCH-P4",
    "P4-UNKNOWN,100.00,BATCH-P4",
]);

$settlementSvc = Services::codSettlement();
$preview = $settlementSvc->previewCsv($csv);

assert_p4(count($preview->rows) === 5, "Preview parsed all 5 CSV rows", "count: " . count($preview->rows));
assert_p4($preview->rows[0]->status === SettlementRowStatus::Matched, "Row 0 (P4-DEL-1) matched");
assert_p4($preview->rows[1]->status === SettlementRowStatus::Matched, "Row 1 (P4-DEL-2) matched");
assert_p4($preview->rows[2]->status === SettlementRowStatus::NotDelivered, "Row 2 (P4-SHIP-1) marked NOT_DELIVERED");
assert_p4($preview->rows[3]->status === SettlementRowStatus::HandedOverToEngaz, "Row 3 (P4-ENGAZ-1) marked HANDED_OVER_TO_ENGAZ");
assert_p4($preview->rows[4]->status === SettlementRowStatus::NotFound, "Row 4 (P4-UNKNOWN) marked NOT_FOUND");

// Permission check: shipping user cannot settle
$itemsToSettle = [
    $o1->get_id() => OrderHandoverItem::toCents((string) $o1->get_total()),
    $o2->get_id() => OrderHandoverItem::toCents((string) $o2->get_total()),
];

$rForbidden = $settlementSvc->settle($shId, 'Bosta', 'BATCH-P4', $itemsToSettle);
assert_p4(!$rForbidden->ok && $rForbidden->code === 'FORBIDDEN', "Shipping role blocked from settling COD");

// Settle by admin
$rSettle = $settlementSvc->settle($adminId, 'Bosta', 'BATCH-P4', $itemsToSettle);
assert_p4($rSettle->ok, "Admin settles 2 delivered COD orders in one batch");

$fresh1 = wc_get_order($o1->get_id());
$fresh2 = wc_get_order($o2->get_id());
$ops1 = Services::repository()->find($o1->get_id());
$ops2 = Services::repository()->find($o2->get_id());

assert_p4($fresh1->get_date_paid('edit') !== null && $fresh2->get_date_paid('edit') !== null, "WooCommerce date_paid set for both settled orders");
assert_p4($fresh1->get_transaction_id() === 'BATCH-P4', "WooCommerce transaction_id set to batch reference");
assert_p4($ops1?->isCodSettled() && $ops2?->isCodSettled(), "Ops records cod_settled_at and cod_settlement_id");

// Double settlement prevented in both preview and settle()
$preview2 = $settlementSvc->previewCsv($csv);
assert_p4($preview2->rows[0]->status === SettlementRowStatus::AlreadySettled, "Re-preview marks P4-DEL-1 as ALREADY_SETTLED");

$rDouble = $settlementSvc->settle($adminId, 'Bosta', 'BATCH-P4-DUP', $itemsToSettle);
assert_p4(!$rDouble->ok, "Second settle attempt on already-settled orders is rejected");

// Cleanup
global $wpdb;
if ($ops1?->codSettlementId) {
    $wpdb->delete($wpdb->prefix . 'anoofy_cod_settlement_orders', ['settlement_id' => $ops1->codSettlementId]);
    $wpdb->delete($wpdb->prefix . 'anoofy_cod_settlements', ['id' => $ops1->codSettlementId]);
}
$o1->delete(true);
$o2->delete(true);
$oShip->delete(true);
$oEngaz->delete(true);
wp_delete_user($shId);

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
