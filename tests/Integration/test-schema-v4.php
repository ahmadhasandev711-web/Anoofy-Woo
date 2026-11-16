<?php
/**
 * Integration test: Schema v4 migration & PreparedItemsRepository / OpsRepository Phase 2 helpers.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Database\Migrator;
use Anoofy\Woo\Database\Schema;
use Anoofy\Woo\Services\Services;

global $wpdb;
$hasFailed = false;

function assert_v4(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test Schema v4: Migration & Prepared Items ===\n";

$testOrderId = 99992;
$opsTable = $wpdb->prefix . 'anoofy_order_ops';
$wpdb->query($wpdb->prepare(
    "INSERT IGNORE INTO {$opsTable} (order_id, ops_status, engaz_stage, created_at, updated_at) VALUES (%d, %s, %s, %s, %s)",
    $testOrderId,
    'CONFIRMED',
    'CONFIRMED',
    gmdate('Y-m-d H:i:s'),
    gmdate('Y-m-d H:i:s')
));

Migrator::maybeUpgrade();
assert_v4(Migrator::currentVersion() === Schema::VERSION && Schema::VERSION === '4', "Schema version option is '4'", "actual: " . Migrator::currentVersion());

$opsCols = $wpdb->get_col("DESCRIBE {$opsTable}", 0);
assert_v4(in_array('prep_items_hash', $opsCols, true), "anoofy_order_ops has 'prep_items_hash' column");
assert_v4(in_array('engaz_stage', $opsCols, true), "anoofy_order_ops preserves v3 'engaz_stage' column");

$inboxTable = $wpdb->prefix . 'anoofy_inbox';
$inboxExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $inboxTable)) === $inboxTable;
assert_v4($inboxExists, "anoofy_inbox table exists", "table: {$inboxTable}");

$prepTable = $wpdb->prefix . 'anoofy_order_prepared_items';
$prepExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $prepTable)) === $prepTable;
assert_v4($prepExists, "anoofy_order_prepared_items table exists", "table: {$prepTable}");

$prepCols = $prepExists ? $wpdb->get_col("DESCRIBE {$prepTable}", 0) : [];
foreach (['id', 'order_id', 'item_id', 'prepared_by', 'prepared_at'] as $col) {
    assert_v4(in_array($col, $prepCols, true), "anoofy_order_prepared_items has column '{$col}'");
}

// Verify existing row preservation
$preserved = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$opsTable} WHERE order_id = %d", $testOrderId), ARRAY_A);
assert_v4($preserved !== null && $preserved['ops_status'] === 'CONFIRMED', "Existing v3 order_ops row was preserved");
$wpdb->delete($opsTable, ['order_id' => $testOrderId]);

// Verify PreparedItemsRepository CRUD & OpsRepository Phase 2 updates
$prepRepo = new \Anoofy\Woo\Data\PreparedItemsRepository();
$prepRepo->setPreparedItems($testOrderId, [101, 102], 1);
assert_v4($prepRepo->forOrder($testOrderId) === [101, 102], "PreparedItemsRepository saves and returns sorted item IDs");
$prepRepo->setPreparedItems($testOrderId, [102], 1);
assert_v4($prepRepo->forOrder($testOrderId) === [102], "PreparedItemsRepository replaces item IDs atomically");
$prepRepo->clearOrder($testOrderId);
assert_v4($prepRepo->forOrder($testOrderId) === [], "PreparedItemsRepository clearOrder deletes rows");

// Verify idempotency
Migrator::maybeUpgrade();
assert_v4(Migrator::currentVersion() === '4', "Migrator::maybeUpgrade() is idempotent at '4'");

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
