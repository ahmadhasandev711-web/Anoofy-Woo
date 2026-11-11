<?php
/**
 * Test C-1: EngazPRO Connection Verification & Permissions
 * 1. Invalid connection key throws validation error and sends NO HTTP request.
 * 2. Valid connection key sends signed connection.verify request via pre_http_request:
 *    - Verifies headers: X-Anoofy-Key-Id, X-Anoofy-Timestamp, X-Anoofy-Signature.
 *    - Verifies body: event_type = connection.verify, site_url, callback_url, plugin_version.
 *    - On 200: saves api, kid, secret, connected_at in options; Services::mode() is Connected.
 * 3. Disconnect clears options and reverts Services::mode() to Standalone.
 * 4. 401 response from EngazPRO preserves keys, sets auth_failed_at, and keeps mode as Connected.
 */
defined('ABSPATH') || exit;

use Anoofy\Woo\Core\Connection\EngazEventType;
use Anoofy\Woo\Core\Connection\Signature;
use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Mode\OperatingMode;
use Anoofy\Woo\Services\Services;

$hasFailed = false;

function assert_c1(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test C-1: Connection Verification & Permissions ===\n";

// Helper to encode base64url
function test_c1_b64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// Ensure clean initial state
delete_option('anoofy_engaz_connection');

// 1. Initial State: Standalone
assert_c1(Services::mode() === OperatingMode::Standalone, "Initial mode is Standalone");
assert_c1(!Services::engazConnection()->isConnected(), "Initially not connected");

// Track HTTP requests
$httpRequestsMade = 0;
$capturedArgs = null;
$capturedUrl = null;
$mockResponseCode = 200;

add_filter('pre_http_request', function ($pre, $args, $url) use (&$httpRequestsMade, &$capturedArgs, &$capturedUrl, &$mockResponseCode) {
    if (str_contains($url, 'anoofy/events') || str_contains($url, 'engazpro')) {
        $httpRequestsMade++;
        $capturedArgs = $args;
        $capturedUrl = $url;

        return [
            'response' => ['code' => $mockResponseCode, 'message' => $mockResponseCode === 200 ? 'OK' : 'Unauthorized'],
            'body' => json_encode(['success' => $mockResponseCode === 200]),
            'headers' => ['content-type' => 'application/json'],
        ];
    }
    return $pre;
}, 10, 3);

// 2. Invalid Key: Should fail validation BEFORE any HTTP request is dispatched
$invalidKey = "not-a-valid-key";
$invalidResult = Services::engazConnection()->connect($invalidKey);

assert_c1(!$invalidResult->ok, "Invalid key fails connection");
assert_c1($httpRequestsMade === 0, "No HTTP request made for invalid key", "actual requests: {$httpRequestsMade}");
assert_c1(Services::mode() === OperatingMode::Standalone, "Mode remains Standalone after invalid key");

// 3. Valid Key + 200 Response
$kid = "01JCTESTKID12345678901234AB";
$secret = "f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a1f0e9d8c7b6a5f4e3d2c1b0a9f8e7";
$endpoint = "https://engazpro.example.com/api/webhooks/anoofy/events";

$validPayload = json_encode([
    'v' => 1,
    'api' => $endpoint,
    'kid' => $kid,
    'secret' => $secret,
]);
$validKey = test_c1_b64url($validPayload);

$mockResponseCode = 200;
$connectResult = Services::engazConnection()->connect($validKey);

assert_c1($connectResult->ok, "Valid key connects successfully on 200");
assert_c1($httpRequestsMade === 1, "Exactly one HTTP request made to EngazPRO", "requests: {$httpRequestsMade}");
assert_c1($capturedUrl === $endpoint, "Request dispatched to URL in key", "url: {$capturedUrl}");

// Validate request headers and body
$headers = $capturedArgs['headers'] ?? [];
$rawBody = $capturedArgs['body'] ?? '';

assert_c1(($headers['X-Anoofy-Key-Id'] ?? '') === $kid, "Header X-Anoofy-Key-Id matches kid");
$timestamp = (int) ($headers['X-Anoofy-Timestamp'] ?? 0);
assert_c1(abs(time() - $timestamp) <= 10, "Header X-Anoofy-Timestamp is recent UTC seconds");

$expectedSig = Signature::sign($secret, $timestamp, $rawBody);
assert_c1(($headers['X-Anoofy-Signature'] ?? '') === $expectedSig, "Header X-Anoofy-Signature matches calculated HMAC-SHA256");

$envelope = json_decode($rawBody, true);
assert_c1(($envelope['schema_version'] ?? 0) === 1, "Envelope schema_version is 1");
assert_c1(($envelope['event_type'] ?? '') === EngazEventType::ConnectionVerify->value, "Envelope event_type is connection.verify");
assert_c1(($envelope['order_version'] ?? 0) === 1, "Envelope order_version is 1");
assert_c1(!empty($envelope['data']['site_url']), "Envelope data contains site_url");
assert_c1(!empty($envelope['data']['callback_url']), "Envelope data contains callback_url");
assert_c1(!empty($envelope['data']['plugin_version']), "Envelope data contains plugin_version");

// Validate persistence
$conn = Services::engazConnection()->getConnection();
assert_c1(is_array($conn), "Connection data stored in options");
assert_c1(($conn['kid'] ?? '') === $kid, "Stored kid matches");
assert_c1(($conn['api'] ?? '') === $endpoint, "Stored api matches");
assert_c1(($conn['secret'] ?? '') === $secret, "Stored secret matches");
assert_c1(!empty($conn['connected_at']), "Stored connected_at is not empty");
assert_c1(Services::mode() === OperatingMode::Connected, "Services::mode() is now Connected");

// 4. Disconnect
Services::engazConnection()->disconnect();
assert_c1(!Services::engazConnection()->isConnected(), "isConnected() is false after disconnect");
assert_c1(Services::mode() === OperatingMode::Standalone, "Services::mode() reverted to Standalone");
assert_c1(get_option('anoofy_engaz_connection') === false, "anoofy_engaz_connection option removed from database");

// 5. Reconnect then simulate 401 response: keys preserved, auth_failed_at saved, mode remains Connected
Services::engazConnection()->connect($validKey);
assert_c1(Services::mode() === OperatingMode::Connected, "Reconnected for 401 test");

// Simulate 401 handler
Services::engazConnection()->handleHttpResponse(401);
assert_c1(Services::engazConnection()->isConnected(), "Keys preserved upon receiving 401");
assert_c1(Services::engazConnection()->isAuthFailed(), "auth_failed_at is set upon receiving 401");
assert_c1(Services::mode() === OperatingMode::Connected, "Mode remains Connected after 401");

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
