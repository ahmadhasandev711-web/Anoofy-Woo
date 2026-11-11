<?php
/**
 * Test C-8: UI Display of EngazPRO Stage in OrderBox and OrdersTable
 * - OrderBox renders EngazPRO stage, branch, courier, tracking number, and wp_date formatted stage_at
 * - When no stage received yet: renders "Waiting for EngazPRO" / "في انتظار EngazPRO"
 * - OrdersTable renders stage badge beside operational status badge for handed-over orders
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Admin\OrderBox;
use Anoofy\Woo\Admin\OrdersTable;
use Anoofy\Woo\Core\Connection\EngazStage;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_c8(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test C-8: Display EngazPRO Stage in OrderBox & OrdersTable ===\n";

global $wpdb;

$productId = wc_get_product_id_by_sku('TEST-PROD-10');
$product = wc_get_product($productId);

// 1. Create handed-over order WITH stage
$orderWithStage = wc_create_order();
$orderWithStage->add_product($product, 1);
$orderWithStage->set_billing_first_name('Kareem');
$orderWithStage->set_billing_last_name('Adel');
$orderWithStage->save();
$orderWithStageId = $orderWithStage->get_id();

$opsRepo = Services::repository();
$ops = $opsRepo->ensure($orderWithStageId);
$opsRepo->compareAndSetStatus($orderWithStageId, $ops->status, OrderStatus::Confirmed);
$opsRepo->recordHandover($orderWithStageId, gmdate('Y-m-d H:i:s'));

$utcStageTime = '2026-11-11 10:30:00';
$opsRepo->applyEngazStageChange(
    orderId: $orderWithStageId,
    stage: EngazStage::SHIPPED_VIA_COURIER,
    stageAt: $utcStageTime,
    version: 1,
    branch: 'فرع المعادي',
    courier: 'أرامكس',
    tracking: 'ARAMEX-TRK-7788'
);

$freshOpsWithStage = $opsRepo->find($orderWithStageId);

// 2. Create handed-over order WITHOUT stage yet
$orderNoStage = wc_create_order();
$orderNoStage->add_product($product, 1);
$orderNoStage->set_billing_first_name('Mona');
$orderNoStage->set_billing_last_name('Hassan');
$orderNoStage->save();
$orderNoStageId = $orderNoStage->get_id();

$opsNo = $opsRepo->ensure($orderNoStageId);
$opsRepo->compareAndSetStatus($orderNoStageId, $opsNo->status, OrderStatus::Confirmed);
$opsRepo->recordHandover($orderNoStageId, gmdate('Y-m-d H:i:s'));

$freshOpsNoStage = $opsRepo->find($orderNoStageId);

// --- Test OrderBox rendering for order with stage ---
ob_start();
OrderBox::render($orderWithStage);
$boxHtmlWithStage = ob_get_clean();

assert_c8(str_contains($boxHtmlWithStage, 'anoofy-stage--shipped-via-courier'), "OrderBox has anoofy-stage--shipped-via-courier class");
assert_c8(str_contains($boxHtmlWithStage, 'فرع المعادي'), "OrderBox contains branch name 'فرع المعادي'");
assert_c8(str_contains($boxHtmlWithStage, 'أرامكس'), "OrderBox contains courier name 'أرامكس'");
assert_c8(str_contains($boxHtmlWithStage, 'ARAMEX-TRK-7788'), "OrderBox contains tracking number 'ARAMEX-TRK-7788'");

// Check date formatting via wp_date
$expectedLocalTime = wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($utcStageTime));
assert_c8(str_contains($boxHtmlWithStage, $expectedLocalTime), "OrderBox displays stage_at formatted with wp_date", "expected: {$expectedLocalTime}");

// --- Test OrderBox rendering for order without stage yet ---
ob_start();
OrderBox::render($orderNoStage);
$boxHtmlNoStage = ob_get_clean();

assert_c8(str_contains($boxHtmlNoStage, 'anoofy-stage--waiting'), "OrderBox has anoofy-stage--waiting class when no stage");
assert_c8(str_contains($boxHtmlNoStage, 'Waiting for EngazPRO') || str_contains($boxHtmlNoStage, 'في انتظار EngazPRO'), "OrderBox displays waiting text when no stage");

// --- Test OrdersTable column_ops_status rendering ---
$table = new OrdersTable(null, null, null);

$columnHtmlWithStage = $table->column_ops_status(['ops' => $freshOpsWithStage, 'order' => $orderWithStage]);
assert_c8(str_contains($columnHtmlWithStage, 'anoofy-stage-badge'), "OrdersTable column has anoofy-stage-badge class");
assert_c8(str_contains($columnHtmlWithStage, 'anoofy-stage--shipped-via-courier'), "OrdersTable column has anoofy-stage--shipped-via-courier class");

$columnHtmlNoStage = $table->column_ops_status(['ops' => $freshOpsNoStage, 'order' => $orderNoStage]);
assert_c8(str_contains($columnHtmlNoStage, 'anoofy-stage--waiting'), "OrdersTable column has anoofy-stage--waiting class when no stage");

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
