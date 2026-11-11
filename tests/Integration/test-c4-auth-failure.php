<?php
/**
 * Test C-4 (401 Auth Failure Handling & Recovery):
 * 1. 401 response does NOT disconnect or delete kid/secret.
 * 2. OperatingMode remains Connected.
 * 3. auth_failed_at is saved in connection option.
 * 4. Failed outbox row has last_http_status = 401.
 * 5. While auth_failed_at is set, worker skips pending rows without incrementing attempts.
 * 6. Admin notice is shown to users with IntegrationManage capability.
 * 7. Successful reconnection clears auth_failed_at, resets 401 rows to pending, and enqueues async action.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Connection\EngazEventType;
use Anoofy\Woo\Core\Connection\EnvelopeFactory;
use Anoofy\Woo\Core\Connection\OutboxStatus;
use Anoofy\Woo\Core\Mode\OperatingMode;
use Anoofy\Woo\Database\Migrator;
use Anoofy\Woo\Services\EngazOutboxWorker;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_c4(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test C-4: 401 Auth Failure & Recovery ===\n";

// Ensure schema is up to date
Migrator::maybeUpgrade();

// 1. Setup connection
$connData = [
    'v' => 1,
    'api' => 'https://app.engazpro.test/api/v1/events',
    'kid' => 'kid_live_c4_test',
    'secret' => 'a9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a1f0e9d8c7b6a5f4e3d2c1b0a9f8',
    'connected_at' => gmdate('Y-m-d\TH:i:s\Z'),
];
$keyB64 = rtrim(strtr(base64_encode(json_encode([
    'v' => 1,
    'api' => $connData['api'],
    'kid' => $connData['kid'],
    'secret' => $connData['secret'],
], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
update_option('anoofy_engaz_connection', $connData, false);

assert_c4(Services::mode() === OperatingMode::Connected, "Initial mode is Connected");
$connBefore = Services::engazConnection()->getConnection();
assert_c4($connBefore !== null && $connBefore['kid'] === 'kid_live_c4_test', "Initial connection has kid");

// 2. Create outbox row for order 101
$envelope1 = EnvelopeFactory::build(
    type: EngazEventType::OrderConfirmed,
    orderVersion: 1,
    data: ['wc_order_id' => 101],
    occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
);
$outboxId1 = Services::outbox()->create(
    $envelope1['event_id'],
    $envelope1['event_type'],
    101,
    1,
    json_encode($envelope1, JSON_UNESCAPED_SLASHES)
);

// Filter pre_http_request to return 401 Unauthorized for event delivery
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (str_contains($url, 'app.engazpro.test')) {
        return [
            'response' => ['code' => 401, 'message' => 'Unauthorized'],
            'body' => json_encode(['error' => 'Invalid signature or timestamp skew']),
        ];
    }
    return $pre;
}, 10, 3);

// Process outboxId1 -> will receive 401
EngazOutboxWorker::process($outboxId1);

// 3. Assertions after 401
$connOption = get_option('anoofy_engaz_connection');
assert_c4(is_array($connOption), "Connection option is preserved after 401 (not deleted)");
assert_c4(isset($connOption['kid']) && $connOption['kid'] === 'kid_live_c4_test', "kid is preserved in option after 401");
assert_c4(isset($connOption['secret']) && $connOption['secret'] === $connData['secret'], "secret is preserved in option after 401");
assert_c4(!empty($connOption['auth_failed_at']), "auth_failed_at timestamp is saved in option after 401");
assert_c4(Services::mode() === OperatingMode::Connected, "OperatingMode remains Connected after 401");
assert_c4(Services::engazConnection()->isAuthFailed(), "isAuthFailed() returns true after 401");

// Verify outbox row status and last_http_status
$row1 = Services::outbox()->find($outboxId1);
assert_c4($row1 !== null && $row1['status'] === OutboxStatus::Failed->value, "Outbox row 1 status is failed");
assert_c4(isset($row1['last_http_status']) && (int) $row1['last_http_status'] === 401, "Outbox row 1 last_http_status is 401", "actual: " . ($row1['last_http_status'] ?? 'null'));

// 4. Test worker skipping while auth_failed_at is present
$envelope2 = EnvelopeFactory::build(
    type: EngazEventType::OrderConfirmed,
    orderVersion: 1,
    data: ['wc_order_id' => 102],
    occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
);
$outboxId2 = Services::outbox()->create(
    $envelope2['event_id'],
    $envelope2['event_type'],
    102,
    1,
    json_encode($envelope2, JSON_UNESCAPED_SLASHES)
);

// Attempt to process row 2 while auth_failed_at is active
EngazOutboxWorker::process($outboxId2);
$row2 = Services::outbox()->find($outboxId2);
assert_c4($row2 !== null && $row2['status'] === OutboxStatus::Pending->value, "Worker does not process row 2; stays pending");
assert_c4((int) ($row2['attempts'] ?? 0) === 0, "Row 2 attempts was NOT incremented");

// 5. Test admin notice
$adminUser = get_user_by('email', 'admin@example.com');
if (!$adminUser) {
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    $adminUser = $admins[0] ?? null;
}
wp_set_current_user($adminUser ? $adminUser->ID : 1);

ob_start();
\Anoofy\Woo\Admin\Notices::render();
$noticeOutput = ob_get_clean();

$expectedEnglish = 'The EngazPRO connection needs to be re-established (the key was rejected or the server clock is wrong).';
$expectedArabic = 'الربط مع EngazPRO محتاج يتعمل تاني (المفتاح اترفض أو ساعة السيرفر مش مظبوطة)';
assert_c4(str_contains($noticeOutput, $expectedEnglish) || str_contains($noticeOutput, $expectedArabic), "Admin notice rendered with expected text for manager");

// Test notice hidden for non-manager
$shopManager = get_users(['role' => 'shop_manager', 'number' => 1]);
if ($shopManager) {
    wp_set_current_user($shopManager[0]->ID);
    ob_start();
    \Anoofy\Woo\Admin\Notices::render();
    $noticeNonManager = ob_get_clean();
    assert_c4(!str_contains($noticeNonManager, $expectedEnglish) && !str_contains($noticeNonManager, $expectedArabic), "Admin notice NOT rendered for non-manager");
}
wp_set_current_user($adminUser ? $adminUser->ID : 1);

// 6. Test Connection Settings page during auth_failed state
ob_start();
\Anoofy\Woo\Admin\ConnectionSettings::render();
$settingsOutput = ob_get_clean();
assert_c4(str_contains($settingsOutput, 'anoofy-status-bad'), "Settings page renders status with bad class when auth failed");
assert_c4(str_contains($settingsOutput, 'name="anoofy_connection_key"'), "Settings page renders connection key textarea when auth failed");
assert_c4(str_contains($settingsOutput, 'action" value="anoofy_disconnect"'), "Settings page renders disconnect form when auth failed");

// 7. Test failed reconnect with invalid key: old connection remains intact
$badKeyResult = Services::engazConnection()->connect('invalid-key-data');
assert_c4(!$badKeyResult->ok, "Connect with invalid key failed");
$connAfterBad = Services::engazConnection()->getConnection();
assert_c4($connAfterBad !== null && $connAfterBad['kid'] === 'kid_live_c4_test', "Old connection is preserved after failed connect");
assert_c4(Services::mode() === OperatingMode::Connected, "Mode remains Connected after failed connect");

// Test failed reconnect with remote error: old connection remains intact
remove_all_filters('pre_http_request');
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (str_contains($url, 'app.engazpro.test')) {
        return [
            'response' => ['code' => 500, 'message' => 'Internal Server Error'],
            'body' => json_encode(['error' => 'Server error']),
        ];
    }
    return $pre;
}, 10, 3);

$failHttpResult = Services::engazConnection()->connect($keyB64);
assert_c4(!$failHttpResult->ok, "Connect with failing endpoint failed");
$connAfterHttpFail = Services::engazConnection()->getConnection();
assert_c4($connAfterHttpFail !== null && $connAfterHttpFail['kid'] === 'kid_live_c4_test', "Old connection preserved after endpoint failure");
assert_c4(Services::mode() === OperatingMode::Connected, "Mode remains Connected after endpoint failure");

// 8. Test Reconnect Recovery with new valid key
remove_all_filters('pre_http_request');
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (str_contains($url, 'app.engazpro.test')) {
        return [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body' => json_encode(['success' => true]),
        ];
    }
    return $pre;
}, 10, 3);

$newConnData = [
    'v' => 1,
    'api' => 'https://app.engazpro.test/api/v1/events',
    'kid' => 'kid_live_c4_new_key',
    'secret' => 'b1b2b3b4b5b6b7b8b9b0a1a2a3a4a5a6a7a8a9a0b1b2b3b4b5b6b7b8b9b0a1a2',
];
$newKeyB64 = rtrim(strtr(base64_encode(json_encode([
    'v' => 1,
    'api' => $newConnData['api'],
    'kid' => $newConnData['kid'],
    'secret' => $newConnData['secret'],
], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

$result = Services::engazConnection()->connect($newKeyB64);
assert_c4($result->ok, "Reconnect with new key succeeded");
assert_c4(Services::mode() === OperatingMode::Connected, "Mode is Connected");

$connAfter = get_option('anoofy_engaz_connection');
assert_c4(is_array($connAfter) && $connAfter['kid'] === 'kid_live_c4_new_key', "kid updated to new key");
assert_c4(empty($connAfter['auth_failed_at']), "auth_failed_at was cleared after reconnect");
assert_c4(!Services::engazConnection()->isAuthFailed(), "isAuthFailed() returns false after reconnect");

$row1After = Services::outbox()->find($outboxId1);
assert_c4($row1After !== null && $row1After['status'] === OutboxStatus::Pending->value, "Row 1 was reset to pending after reconnect");

if ($hasFailed) {
    exit(1);
}
echo "=== Test C-4 Completed Successfully ===\n";
exit(0);
