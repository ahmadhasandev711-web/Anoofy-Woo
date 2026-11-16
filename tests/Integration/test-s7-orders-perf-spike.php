<?php
/**
 * Spike S-7: Verify Orders screen query plans (`EXPLAIN`) and latencies on `anoofy_order_ops`
 * across thousands of rows on MariaDB 11.4.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Data\OpsQuery;
use Anoofy\Woo\Database\Schema;

global $wpdb;
$hasFailed = false;

function assert_s7(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Spike S-7: Orders Screen Query Plans & Indexes ===\n";

$table = Schema::table($wpdb->prefix, 'order_ops');
$baseId = 900000;
$totalSynthetic = 5000;
$now = gmdate('Y-m-d H:i:s');
$statuses = ['NEW', 'UNDER_REVIEW', 'CONFIRMED', 'PREPARING', 'READY_TO_SHIP', 'SHIPPED', 'DELIVERED', 'CANCELLED'];

// Seed synthetic rows in batches of 500
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE order_id >= %d", $baseId));
for ($offset = 0; $offset < $totalSynthetic; $offset += 500) {
    $values = [];
    for ($i = 0; $i < 500; $i++) {
        $idx = $offset + $i;
        $oid = $baseId + $idx;
        $st = $statuses[$idx % count($statuses)];
        $assignee = ($idx % 3 === 0) ? 'NULL' : (string) (($idx % 5) + 1);
        $trk = sprintf("'S7-TRK-%06d'", $idx);
        $values[] = "({$oid}, '{$st}', {$assignee}, {$trk}, '{$now}', '{$now}')";
    }
    $wpdb->query("INSERT INTO {$table} (order_id, ops_status, assigned_to, tracking_number, created_at, updated_at) VALUES " . implode(',', $values));
}
$wpdb->query("ANALYZE TABLE {$table}");

$totalCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
assert_s7($totalCount >= $totalSynthetic, "Seeded {$totalSynthetic} synthetic rows for query-plan verification", "total={$totalCount}");

// 1. Single-status Orders tab query uses index (no full table scan)
[$sqlStatus, $argsStatus] = OpsQuery::select($table, ['NEW'], null, null, 20, 0);
$preparedStatus = $wpdb->prepare($sqlStatus, ...$argsStatus);
$explainStatus = $wpdb->get_row("EXPLAIN {$preparedStatus}", ARRAY_A);
$t0 = microtime(true);
$rowsStatus = $wpdb->get_results($preparedStatus, ARRAY_A);
$msStatus = (microtime(true) - $t0) * 1000;
assert_s7(
    is_array($explainStatus) && !empty($explainStatus['key']) && $explainStatus['type'] !== 'ALL' && count($rowsStatus) === 20,
    "Status tab query uses index",
    "key=" . ($explainStatus['key'] ?? 'none') . ", type=" . ($explainStatus['type'] ?? 'none') . ", time=" . number_format($msStatus, 2) . "ms"
);

// 2. Assignee ("Mine") Orders query uses index
[$sqlAssign, $argsAssign] = OpsQuery::select($table, null, 2, null, 20, 0);
$preparedAssign = $wpdb->prepare($sqlAssign, ...$argsAssign);
$explainAssign = $wpdb->get_row("EXPLAIN {$preparedAssign}", ARRAY_A);
$t1 = microtime(true);
$rowsAssign = $wpdb->get_results($preparedAssign, ARRAY_A);
$msAssign = (microtime(true) - $t1) * 1000;
assert_s7(
    is_array($explainAssign) && !empty($explainAssign['key']) && $explainAssign['type'] !== 'ALL' && count($rowsAssign) === 20,
    "Assignee filter query uses index",
    "key=" . ($explainAssign['key'] ?? 'none') . ", type=" . ($explainAssign['type'] ?? 'none') . ", time=" . number_format($msAssign, 2) . "ms"
);

// 3. Status counts query uses covering index (`Using index`)
[$sqlCounts, $argsCounts] = OpsQuery::statusCounts($table, null, null);
$preparedCounts = $argsCounts === [] ? $sqlCounts : $wpdb->prepare($sqlCounts, ...$argsCounts);
$explainCounts = $wpdb->get_row("EXPLAIN {$preparedCounts}", ARRAY_A);
$t2 = microtime(true);
$counts = $wpdb->get_results($preparedCounts, ARRAY_A);
$msCounts = (microtime(true) - $t2) * 1000;
assert_s7(
    is_array($explainCounts) && str_contains((string) ($explainCounts['Extra'] ?? ''), 'Using index') && count($counts) >= count($statuses),
    "Status tab counts use covering index",
    "key=" . ($explainCounts['key'] ?? 'none') . ", extra=" . ($explainCounts['Extra'] ?? '') . ", time=" . number_format($msCounts, 2) . "ms"
);

// 4. Latency sanity check (< 100ms per query)
assert_s7($msStatus < 100 && $msAssign < 100 && $msCounts < 100, "All list-table queries complete under 100ms");

// Cleanup synthetic rows
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE order_id >= %d", $baseId));

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
