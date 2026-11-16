<?php
/**
 * Integration test: Schema v5 migration & DeliveryAttemptLog / OpsRepository delivery attempts counter.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Order\DeliveryOutcome;
use Anoofy\Woo\Database\Migrator;
use Anoofy\Woo\Database\Schema;
use Anoofy\Woo\Services\Services;

global $wpdb;
$hasFailed = false;

function assert_v5(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test Schema v5: Migration & Delivery Attempts ===\n";

$testOrderId = 99993;
$opsTable = $wpdb->prefix . 'anoofy_order_ops';
$wpdb->query($wpdb->prepare(
    "INSERT IGNORE INTO {$opsTable} (order_id, ops_status, courier, tracking_number, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)",
    $testOrderId,
    'SHIPPED',
    'Bosta',
    'TRK-V5',
    gmdate('Y-m-d H:i:s'),
    gmdate('Y-m-d H:i:s')
));

Migrator::maybeUpgrade();
assert_v5(Migrator::currentVersion() === Schema::VERSION && Schema::VERSION === '5', "Schema version option is '5'", "actual: " . Migrator::currentVersion());

$table = $wpdb->prefix . 'anoofy_delivery_attempts';
$exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
assert_v5($exists, "anoofy_delivery_attempts table exists", "table: {$table}");

$prepTable = $wpdb->prefix . 'anoofy_order_prepared_items';
assert_v5($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $prepTable)) === $prepTable, "v4 table anoofy_order_prepared_items preserved");

$cols = $exists ? $wpdb->get_col("DESCRIBE {$table}", 0) : [];
foreach (['id', 'order_id', 'attempt_no', 'user_id', 'outcome', 'reason', 'created_at'] as $col) {
    assert_v5(in_array($col, $cols, true), "anoofy_delivery_attempts has column '{$col}'");
}

// Verify data preservation
$preserved = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$opsTable} WHERE order_id = %d", $testOrderId), ARRAY_A);
assert_v5($preserved !== null && $preserved['ops_status'] === 'SHIPPED', "Existing v4 order_ops row was preserved");

// Verify OpsRepository::incrementDeliveryAttempts & DeliveryAttemptLog
$attemptsAfter1 = Services::repository()->incrementDeliveryAttempts($testOrderId);
assert_v5($attemptsAfter1 === 1, "incrementDeliveryAttempts increments to 1", "actual: {$attemptsAfter1}");

$log = new \Anoofy\Woo\Data\DeliveryAttemptLog();
$log->record($testOrderId, 1, 1, DeliveryOutcome::Failed, 'Customer did not answer');
$rows = $log->forOrder($testOrderId);
assert_v5(count($rows) === 1 && $rows[0]['outcome'] === DeliveryOutcome::Failed && $rows[0]['attempt_no'] === 1 && $rows[0]['reason'] === 'Customer did not answer', "DeliveryAttemptLog records and reads attempt row");

// Cleanup
$wpdb->delete($table, ['order_id' => $testOrderId]);
$wpdb->delete($opsTable, ['order_id' => $testOrderId]);

Migrator::maybeUpgrade();
assert_v5(Migrator::currentVersion() === '5', "Migrator::maybeUpgrade() is idempotent at '5'");

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
