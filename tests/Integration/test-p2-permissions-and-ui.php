<?php
/**
 * Integration test P2-4: Role permissions (warehouse / shipping), customer contact privacy in UI,
 * OrderBox rendering for Phase 2 states, and HandedOver protection against Phase 2 actions.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Admin\OrderBox;
use Anoofy\Woo\Admin\OrdersPage;
use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_p2u(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test P2-4: Phase 2 Permissions, Privacy, UI & HandedOver Guard ===\n";

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$product = wc_get_product($productId);
$product->set_stock_quantity(10);
$product->save();

$admin = get_user_by('login', 'admin');
$adminId = $admin ? $admin->ID : 1;

// Create temporary Warehouse and Shipping users
$whName = 'anoofy_wh_test_' . wp_generate_password(5, false);
$whId = wp_create_user($whName, wp_generate_password(12), $whName . '@example.com');
(new WP_User($whId))->set_role('anoofy_warehouse');

$shName = 'anoofy_sh_test_' . wp_generate_password(5, false);
$shId = wp_create_user($shName, wp_generate_password(12), $shName . '@example.com');
(new WP_User($shId))->set_role('anoofy_shipping');

$service = Services::orderOps();
Services::engazConnection()->disconnect();

$secretPhone = '01099887766';
$secretAddress = '99 Secret Nile Street';

$order = wc_create_order();
$itemId = $order->add_product($product, 2);
$order->set_billing_first_name('Khaled');
$order->set_billing_last_name('Tester');
$order->set_billing_phone($secretPhone);
$order->set_billing_address_1($secretAddress);
$order->set_billing_city('Giza');
$order->set_payment_method('cod');
$order->set_status('processing');
$order->calculate_totals();
$order->save();
$orderId = $order->get_id();

$service->logCall($orderId, $adminId, CallOutcome::Answered, 'Confirmed');
$service->runEvent($orderId, OrderEvent::Confirm, $adminId);
$service->assign($orderId, $adminId, $whId);

// 1. Warehouse role workflow + Shipping forbidden for warehouse
wp_set_current_user($whId);
assert_p2u($service->runEvent($orderId, OrderEvent::StartPreparing, $whId)->ok, "Warehouse can StartPreparing");

// Render OrdersPage single order view as Warehouse -> items visible, secret phone/address hidden
$_GET['page'] = 'anoofy';
$_GET['order_id'] = (string) $orderId;
ob_start();
OrdersPage::render();
$whHtml = (string) ob_get_clean();
unset($_GET['order_id'], $_GET['page']);

assert_p2u(!str_contains($whHtml, $secretPhone), "Warehouse order view hides customer phone");
assert_p2u(!str_contains($whHtml, $secretAddress), "Warehouse order view hides customer address");
assert_p2u(str_contains($whHtml, 'TEST-PROD-10'), "Warehouse order view displays product SKU");
assert_p2u(str_contains($whHtml, 'anoofy_save_prepared_items'), "Warehouse order view renders preparation checklist form");
assert_p2u(str_contains($whHtml, 'data-anoofy-return="ops"'), "OrderBox uses ops return target for warehouse");

// Warehouse prepares items & finishes
assert_p2u($service->savePreparedItems($orderId, $whId, [$itemId])->ok, "Warehouse can save prepared items");
assert_p2u($service->runEvent($orderId, OrderEvent::FinishPreparing, $whId)->ok, "Warehouse can FinishPreparing");

// Warehouse CANNOT ship
$whShipAttempt = $service->saveShipmentDetails($orderId, $whId, 'Bosta', 'B-999');
assert_p2u(!$whShipAttempt->ok && $whShipAttempt->code === 'FORBIDDEN', "Warehouse blocked from saving shipment details", "code: {$whShipAttempt->code}");

// 2. Shipping role workflow + Preparing forbidden for shipping
wp_set_current_user($shId);
assert_p2u(!$service->savePreparedItems($orderId, $shId, [$itemId])->ok, "Shipping blocked from preparation mutations");

ob_start();
OrderBox::renderOpsBox(wc_get_order($orderId), 'ops');
$shHtml = (string) ob_get_clean();
assert_p2u(str_contains($shHtml, 'anoofy_save_shipment'), "Shipping user sees shipment details form in READY_TO_SHIP");
assert_p2u(str_contains($shHtml, 'SHIP'), "Shipping user sees Ship order button in READY_TO_SHIP");

assert_p2u($service->saveShipmentDetails($orderId, $shId, 'Aramex', 'ARX-555')->ok, "Shipping can save shipment details");
assert_p2u($service->runEvent($orderId, OrderEvent::Ship, $shId)->ok, "Shipping can Ship order");

// 3. HandedOver order is completely protected from Phase 2 actions & hooks
wp_set_current_user($adminId);
$handedOrder = wc_create_order();
$handedItem = $handedOrder->add_product($product, 1);
$handedOrder->set_payment_method('cod');
$handedOrder->set_status('processing');
$handedOrder->calculate_totals();
$handedOrder->save();
$handedId = $handedOrder->get_id();

$service->logCall($handedId, $adminId, CallOutcome::Answered, 'Confirmed');
$service->runEvent($handedId, OrderEvent::Confirm, $adminId);
$service->assign($handedId, $adminId, $adminId);
Services::repository()->recordHandover($handedId, gmdate('Y-m-d\TH:i:s\Z'));

$rHandedStart = $service->runEvent($handedId, OrderEvent::StartPreparing, $adminId);
assert_p2u(!$rHandedStart->ok && $rHandedStart->code === 'FORBIDDEN', "HandedOver order blocks StartPreparing");

$rHandedPrep = $service->savePreparedItems($handedId, $adminId, [$handedItem]);
assert_p2u(!$rHandedPrep->ok && $rHandedPrep->code === 'FORBIDDEN', "HandedOver order blocks savePreparedItems");

$rHandedShip = $service->saveShipmentDetails($handedId, $adminId, 'Bosta', 'X-1');
assert_p2u(!$rHandedShip->ok && $rHandedShip->code === 'FORBIDDEN', "HandedOver order blocks saveShipmentDetails");

ob_start();
OrderBox::render($handedOrder);
$handedHtml = (string) ob_get_clean();
assert_p2u(!str_contains($handedHtml, 'anoofy_save_prepared_items') && !str_contains($handedHtml, 'START_PREPARING'), "OrderBox does NOT show Phase 2 controls for HandedOver order");

// Cleanup
$order->delete(true);
$handedOrder->delete(true);
wp_delete_user($whId);
wp_delete_user($shId);
$freshProduct = wc_get_product($productId);
$freshProduct->set_stock_quantity(10);
$freshProduct->save();

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
