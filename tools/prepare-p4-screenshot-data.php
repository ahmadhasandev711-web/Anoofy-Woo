<?php
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Connection\EngazStage;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Services\Services;

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$product = wc_get_product($productId);
$opsRepo = Services::repository();

// Order 1: Shipped via courier with tracking
$order1 = wc_create_order();
$order1->add_product($product, 2);
$order1->set_billing_first_name('أحمد');
$order1->set_billing_last_name('محمود');
$order1->set_billing_phone('01012345678');
$order1->set_billing_city('القاهرة');
$order1->calculate_totals();
$order1->save();
$id1 = $order1->get_id();

$ops1 = $opsRepo->ensure($id1);
$opsRepo->compareAndSetStatus($id1, $ops1->status, OrderStatus::Confirmed);
$opsRepo->recordHandover($id1, '2026-11-10 12:00:00');
$opsRepo->applyEngazStageChange(
    orderId: $id1,
    stage: EngazStage::SHIPPED_VIA_COURIER,
    stageAt: '2026-11-11 09:15:00',
    version: 2,
    branch: 'فرع المعادي',
    courier: 'أرامكس',
    tracking: 'TRK-987654321'
);

// Order 2: Preparing in warehouse
$order2 = wc_create_order();
$order2->add_product($product, 1);
$order2->set_billing_first_name('محمود');
$order2->set_billing_last_name('علي');
$order2->set_billing_phone('01123456789');
$order2->set_billing_city('الجيزة');
$order2->calculate_totals();
$order2->save();
$id2 = $order2->get_id();

$ops2 = $opsRepo->ensure($id2);
$opsRepo->compareAndSetStatus($id2, $ops2->status, OrderStatus::Confirmed);
$opsRepo->recordHandover($id2, '2026-11-10 14:00:00');
$opsRepo->applyEngazStageChange(
    orderId: $id2,
    stage: EngazStage::PREPARING,
    stageAt: '2026-11-11 08:30:00',
    version: 1,
    branch: 'فرع المهندسين',
    courier: null,
    tracking: null
);

// Order 3: Handed over, waiting for EngazPRO
$order3 = wc_create_order();
$order3->add_product($product, 1);
$order3->set_billing_first_name('سارة');
$order3->set_billing_last_name('حسن');
$order3->set_billing_phone('01234567890');
$order3->set_billing_city('الإسكندرية');
$order3->calculate_totals();
$order3->save();
$id3 = $order3->get_id();

$ops3 = $opsRepo->ensure($id3);
$opsRepo->compareAndSetStatus($id3, $ops3->status, OrderStatus::Confirmed);
$opsRepo->recordHandover($id3, '2026-11-11 11:00:00');

echo "PREPARED_ORDER_1:{$id1}\n";
