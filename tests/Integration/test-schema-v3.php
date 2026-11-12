<?php
/**
 * Test Schema v3 migration:
 * - Migrator::maybeUpgrade() updates schema to v3
 * - wp_anoofy_inbox table exists with all required columns and indexes
 * - wp_anoofy_order_ops has engaz_stage, engaz_stage_at, engaz_version, engaz_branch, engaz_courier, engaz_tracking columns
 * - Data preservation: existing v2 data remains intact
 * - Idempotency: re-running maybeUpgrade() produces no errors
 */
defined('ABSPATH') || exit;

$hasFailed = false;

function assert_schema_v3(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test Schema v3: Migration & Inbox Table ===\n";

global $wpdb;

// 1. Ensure existing v2 order_ops and outbox rows exist
$testOrderId = 99991;
$wpdb->query($wpdb->prepare(
    "INSERT IGNORE INTO {$wpdb->prefix}anoofy_order_ops (order_id, ops_status, engaz_handover_at, engaz_out_version, created_at, updated_at) VALUES (%d, %s, %s, %d, %s, %s)",
    $testOrderId,
    'CONFIRMED',
    gmdate('Y-m-d H:i:s'),
    1,
    gmdate('Y-m-d H:i:s'),
    gmdate('Y-m-d H:i:s')
));

// 2. Run migration
\Anoofy\Woo\Database\Migrator::maybeUpgrade();

$storedVersion = \Anoofy\Woo\Database\Migrator::currentVersion();
assert_schema_v3($storedVersion === '3', "Schema version option is '3'", "actual: {$storedVersion}");

// 3. Check order_ops columns
$orderOpsTable = $wpdb->prefix . 'anoofy_order_ops';
$orderOpsColumns = $wpdb->get_col("DESCRIBE {$orderOpsTable}", 0);

$expectedOrderOpsCols = [
    'engaz_stage',
    'engaz_stage_at',
    'engaz_version',
    'engaz_branch',
    'engaz_courier',
    'engaz_tracking',
];

foreach ($expectedOrderOpsCols as $col) {
    assert_schema_v3(in_array($col, $orderOpsColumns, true), "anoofy_order_ops has '{$col}' column");
}

// 4. Check inbox table existence & columns
$inboxTable = $wpdb->prefix . 'anoofy_inbox';
$inboxExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $inboxTable)) === $inboxTable;
assert_schema_v3($inboxExists, "anoofy_inbox table exists", "table: {$inboxTable}");

if ($inboxExists) {
    $inboxColumns = $wpdb->get_col("DESCRIBE {$inboxTable}", 0);
    $requiredInboxCols = [
        'id',
        'event_id',
        'event_type',
        'wc_order_id',
        'result',
        'received_at',
    ];

    foreach ($requiredInboxCols as $col) {
        assert_schema_v3(in_array($col, $inboxColumns, true), "anoofy_inbox has column '{$col}'");
    }
}

// 5. Check data preservation
$preservedRow = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$orderOpsTable} WHERE order_id = %d",
    $testOrderId
), ARRAY_A);
assert_schema_v3($preservedRow !== null && $preservedRow['ops_status'] === 'CONFIRMED', "Existing v2 order_ops row was preserved");

// 6. Test Idempotency
\Anoofy\Woo\Database\Migrator::maybeUpgrade();
$versionAfterSecondRun = \Anoofy\Woo\Database\Migrator::currentVersion();
assert_schema_v3($versionAfterSecondRun === '3', "Migrator::maybeUpgrade() is idempotent", "actual: {$versionAfterSecondRun}");

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
