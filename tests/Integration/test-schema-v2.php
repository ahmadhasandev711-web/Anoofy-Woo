<?php
/**
 * Test Schema v2 migration:
 * - Migrator::maybeUpgrade() runs and updates schema to v2
 * - wp_anoofy_outbox table exists with all required columns and indexes
 * - wp_anoofy_order_ops has engaz_handover_at and engaz_out_version columns
 * - Idempotency: re-running maybeUpgrade() produces no errors
 */
defined('ABSPATH') || exit;

$hasFailed = false;

function assert_schema_v2(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test Schema v2: Migration & Outbox Table ===\n";

global $wpdb;

// Run migration
\Anoofy\Woo\Database\Migrator::maybeUpgrade();

$storedVersion = \Anoofy\Woo\Database\Migrator::currentVersion();
assert_schema_v2((int)$storedVersion >= 2, "Schema version option is at least '2'", "actual: {$storedVersion}");

// Check order_ops columns
$orderOpsTable = $wpdb->prefix . 'anoofy_order_ops';
$orderOpsColumns = $wpdb->get_col("DESCRIBE {$orderOpsTable}", 0);

assert_schema_v2(in_array('engaz_handover_at', $orderOpsColumns, true), "anoofy_order_ops has 'engaz_handover_at' column");
assert_schema_v2(in_array('engaz_out_version', $orderOpsColumns, true), "anoofy_order_ops has 'engaz_out_version' column");

// Check outbox table existence
$outboxTable = $wpdb->prefix . 'anoofy_outbox';
$outboxExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $outboxTable)) === $outboxTable;
assert_schema_v2($outboxExists, "anoofy_outbox table exists", "table: {$outboxTable}");

if ($outboxExists) {
    $outboxColumns = $wpdb->get_col("DESCRIBE {$outboxTable}", 0);
    $requiredColumns = [
        'id',
        'event_id',
        'event_type',
        'wc_order_id',
        'order_version',
        'payload',
        'status',
        'attempts',
        'next_retry_at',
        'last_http_status',
        'last_error',
        'created_at',
        'sent_at',
    ];

    foreach ($requiredColumns as $col) {
        assert_schema_v2(in_array($col, $outboxColumns, true), "anoofy_outbox has column '{$col}'");
    }
}

// Test Idempotency
\Anoofy\Woo\Database\Migrator::maybeUpgrade();
$versionAfterSecondRun = \Anoofy\Woo\Database\Migrator::currentVersion();
assert_schema_v2((int)$versionAfterSecondRun >= 2, "Migrator::maybeUpgrade() is idempotent", "actual: {$versionAfterSecondRun}");

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
