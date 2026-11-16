<?php
/**
 * Integration test P4-2: CodSettlementPage rendering (upload/preview/history) & OrderBox/OrdersTable COD settled display.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Admin\CodSettlementPage;
use Anoofy\Woo\Admin\OrderBox;
use Anoofy\Woo\Admin\OrdersTable;
use Anoofy\Woo\Core\Connection\OrderHandoverItem;
use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_p4u(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test P4-2: COD Settlement UI & Badges ===\n";

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$admin = get_user_by('login', 'admin');
$adminId = $admin ? $admin->ID : 1;
wp_set_current_user($adminId);

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
$service->saveShipmentDetails($orderId, $adminId, 'Mylerz', 'MYL-UI-1');
$service->runEvent($orderId, OrderEvent::Ship, $adminId);
$service->runEvent($orderId, OrderEvent::MarkDelivered, $adminId);

$cents = OrderHandoverItem::toCents((string) $order->get_total());
$amt = OrderHandoverItem::formatCents($cents);

// 1. Simulate POST preview on CodSettlementPage
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['anoofy_cod_preview'] = '1';
$_POST['anoofy_courier'] = 'Mylerz';
$_POST['anoofy_reference'] = 'MYL-BATCH-UI';
$_POST['anoofy_csv_text'] = "tracking_number,amount\nMYL-UI-1,{$amt}";
$_POST['_anoofy_nonce'] = wp_create_nonce('anoofy_cod_preview');
$_REQUEST['_anoofy_nonce'] = $_POST['_anoofy_nonce'];

ob_start();
CodSettlementPage::render();
$previewHtml = (string) ob_get_clean();
unset($_POST['anoofy_cod_preview'], $_POST['anoofy_courier'], $_POST['anoofy_reference'], $_POST['anoofy_csv_text'], $_POST['_anoofy_nonce'], $_REQUEST['_anoofy_nonce']);
$_SERVER['REQUEST_METHOD'] = 'GET';

assert_p4u(str_contains($previewHtml, 'MYL-UI-1'), "CodSettlementPage preview displays tracking number");
assert_p4u(str_contains($previewHtml, 'settle_orders[' . $orderId . ']'), "CodSettlementPage preview renders checkbox for matched order");
assert_p4u(str_contains($previewHtml, 'anoofy_cod_settle'), "CodSettlementPage preview renders settle batch form");

// 2. Settle and verify OrderBox + OrdersTable + CodSettlementPage history
$res = Services::codSettlement()->settle($adminId, 'Mylerz', 'MYL-BATCH-UI', [$orderId => $cents]);
assert_p4u($res->ok, "Settlement executed for UI verification");

ob_start();
OrderBox::renderOpsBox(wc_get_order($orderId), 'ops');
$boxHtml = (string) ob_get_clean();
assert_p4u(str_contains($boxHtml, 'COD settled:'), "OrderBox displays COD settled date and batch ID");

$table = new OrdersTable(['DELIVERED'], null, [$orderId]);
$table->prepare_items();
$colShipment = $table->items !== [] ? $table->column_shipment($table->items[0]) : '';
assert_p4u(str_contains($colShipment, 'COD settled'), "OrdersTable shipment column displays COD settled badge");

ob_start();
CodSettlementPage::render();
$historyHtml = (string) ob_get_clean();
assert_p4u(str_contains($historyHtml, 'MYL-BATCH-UI'), "CodSettlementPage renders settlement batch in history table");

// Cleanup
global $wpdb;
$ops = Services::repository()->find($orderId);
if ($ops?->codSettlementId) {
    $wpdb->delete($wpdb->prefix . 'anoofy_cod_settlement_orders', ['settlement_id' => $ops->codSettlementId]);
    $wpdb->delete($wpdb->prefix . 'anoofy_cod_settlements', ['id' => $ops->codSettlementId]);
}
$order->delete(true);

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
