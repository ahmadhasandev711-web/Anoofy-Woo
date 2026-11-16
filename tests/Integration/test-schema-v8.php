<?php
/**
 * Integration test: Schema v8 migration & Spike S-7 performance indexes.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Database\Migrator;
use Anoofy\Woo\Database\Schema;

global $wpdb;
$hasFailed = false;

function assert_v8(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test Schema v8: Migration & Query Performance Indexes ===\n";

Migrator::maybeUpgrade();
assert_v8(Migrator::currentVersion() === Schema::VERSION && Schema::VERSION === '8', "Schema version option is '8'", "actual: " . Migrator::currentVersion());

$opsIdx = array_column((array) $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}anoofy_order_ops", ARRAY_A), 'Key_name');
foreach (['ops_status', 'assigned_to', 'status_created', 'tracking_number', 'cod_settled_at'] as $idxName) {
    assert_v8(in_array($idxName, $opsIdx, true), "anoofy_order_ops has index '{$idxName}'");
}

$auditIdx = array_column((array) $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}anoofy_audit_log", ARRAY_A), 'Key_name');
assert_v8(in_array('action_created', $auditIdx, true), "anoofy_audit_log has index 'action_created'");

Migrator::maybeUpgrade();
assert_v8(Migrator::currentVersion() === '8', "Migrator::maybeUpgrade() is idempotent at '8'");

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
