<?php
/**
 * Test C-1 (Caps Sync): Automatic capability sync via permissions hash
 * 1. Remove anoofy_integration_manage from administrator.
 * 2. Run Plugin::boot().
 * 3. Verify anoofy_integration_manage is restored to administrator.
 * 4. Verify administrator can access and render admin.php?page=anoofy-connection without permission error.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Plugin;
use Anoofy\Woo\Admin\ConnectionSettings;

$hasFailed = false;

function assert_caps(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test C-1 (Caps Sync): Permission Matrix Hash & Access ===\n";

// 1. Remove the capability from administrator
$adminRole = get_role('administrator');
if ($adminRole === null) {
    echo "  [FAIL] administrator role not found\n";
    exit(1);
}

$capName = Action::IntegrationManage->capability();
$adminRole->remove_cap($capName);

assert_caps(!$adminRole->has_cap($capName), "Capability {$capName} successfully stripped from administrator");

// 2. Trigger Plugin::boot() to simulate request/boot without version bump
Plugin::boot();

// Refresh role object
$adminRole = get_role('administrator');
assert_caps($adminRole->has_cap($capName), "Capability {$capName} restored to administrator by boot sync");

// 3. Set current user to administrator and verify screen rendering
$adminUser = get_user_by('login', 'admin');
if ($adminUser) {
    wp_set_current_user($adminUser->ID);
}

assert_caps(current_user_can($capName), "current_user_can({$capName}) is true for administrator");

ob_start();
try {
    ConnectionSettings::render();
    $html = ob_get_clean();
    $hasHeader = str_contains($html, 'EngazPRO Integration');
    $hasNoPermError = str_contains($html, 'You do not have permission');
    assert_caps($hasHeader && !$hasNoPermError, "ConnectionSettings::render() succeeded without permission error");
} catch (\Throwable $e) {
    ob_end_clean();
    assert_caps(false, "ConnectionSettings::render() threw exception: " . $e->getMessage());
}

if ($hasFailed) {
    exit(1);
}
echo "=== Caps Sync Test Completed Successfully ===\n";
exit(0);
