<?php
/**
 * Test E2E: WooCommerce REST API Basic Auth on HTTP & mu-plugin verification
 *
 * Verifies:
 * 1. Without mu-plugin: GET /wp-json/wc/v3/orders with Basic Auth returns 401 (WooCommerce SSL check fails).
 * 2. With mu-plugin: GET /wp-json/wc/v3/orders with Basic Auth returns 200.
 * 3. Filter does NOT affect non-WC routes (e.g. /wp-json/anoofy/) or requests without Basic Auth.
 * 4. http_request_host_is_external filter returns true for engazpro.test only.
 */
defined('ABSPATH') || exit;

$hasFailed = false;

function assert_e2e(bool $condition, string $caseName, string $details = ''): void {
    global $hasFailed;
    if ($condition) {
        echo "  [PASS] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo "  [FAIL] {$caseName}" . ($details ? " ({$details})" : "") . "\n";
        $hasFailed = true;
    }
}

echo "=== Test E2E: WooCommerce Basic Auth & MU Plugin ===\n";

global $wpdb;

// 1. Verify mu-plugin file exists
$muPluginFile = dirname(__DIR__, 2) . '/tools/e2e/anoofy-e2e-dev.mu.php';
assert_e2e(file_exists($muPluginFile), "MU plugin file exists at tools/e2e/anoofy-e2e-dev.mu.php");
if (!file_exists($muPluginFile)) {
    echo "  [FATAL] MU plugin file does not exist, aborting rest of test.\n";
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}

// 2. Create temporary WooCommerce API key
$rawKey = 'ck_e2etest_' . bin2hex(random_bytes(10));
$rawSecret = 'cs_e2etest_' . bin2hex(random_bytes(10));
$hashedKey = wc_api_hash($rawKey);
$adminUser = get_user_by('login', 'admin');
$userId = $adminUser ? $adminUser->ID : 1;

$wpdb->insert("{$wpdb->prefix}woocommerce_api_keys", [
    'user_id' => $userId,
    'description' => 'E2E Basic Auth Integration Test Key',
    'permissions' => 'read_write',
    'consumer_key' => $hashedKey,
    'consumer_secret' => $rawSecret,
    'truncated_key' => substr($rawKey, -7),
]);
$keyId = (int) $wpdb->insert_id;

$cleanup = static function () use ($wpdb, $keyId) {
    $wpdb->delete("{$wpdb->prefix}woocommerce_api_keys", ['key_id' => $keyId]);
};

try {
    // Helper to execute REST request with Basic Auth
    $makeWcRequest = static function (string $key, string $secret): WP_REST_Response {
        wp_set_current_user(0);
        $request = new WP_REST_Request('GET', '/wc/v3/orders');
        $auth = base64_encode("{$key}:{$secret}");
        $request->set_header('Authorization', 'Basic ' . $auth);

        // Populate globals as Apache/WordPress would in a real HTTP request
        $_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/orders';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . $auth;
        $_SERVER['PHP_AUTH_USER'] = $key;
        $_SERVER['PHP_AUTH_PW'] = $secret;

        // In a real HTTP request, WordPress determines current user after headers & URI are set
        $authenticatedUser = apply_filters('determine_current_user', false);
        wp_set_current_user($authenticatedUser ? $authenticatedUser : 0);

        return rest_do_request($request);
    };

    // Case 1: Without mu-plugin (standard HTTP, no SSL) -> 401
    unset($_SERVER['HTTPS']);
    $resWithout = $makeWcRequest($rawKey, $rawSecret);
    assert_e2e(
        $resWithout->get_status() === 401,
        "Without mu-plugin: GET /wp-json/wc/v3/orders with Basic Auth returns 401",
        "actual: " . $resWithout->get_status()
    );

    // Case 2: Load mu-plugin into runtime
    require_once $muPluginFile;

    // With mu-plugin -> 200
    unset($_SERVER['HTTPS']);
    $resWith = $makeWcRequest($rawKey, $rawSecret);
    assert_e2e(
        $resWith->get_status() === 200,
        "With mu-plugin: GET /wp-json/wc/v3/orders with Basic Auth returns 200",
        "actual: " . $resWith->get_status()
    );

    // Case 3: Verify mu-plugin does NOT affect non-WC paths
    unset($_SERVER['HTTPS']);
    $_SERVER['REQUEST_URI'] = '/wp-json/anoofy/v1/callback';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dummy';
    apply_filters('determine_current_user', 0);
    assert_e2e(
        !isset($_SERVER['HTTPS']),
        "mu-plugin does NOT set HTTPS for /wp-json/anoofy/ routes"
    );

    // Case 4: Verify external host filter allows engazpro.test only
    $isExternalEngaz = apply_filters('http_request_host_is_external', false, 'engazpro.test');
    assert_e2e($isExternalEngaz === true, "http_request_host_is_external returns true for engazpro.test");

    $isExternalOther = apply_filters('http_request_host_is_external', false, 'other-internal.test');
    assert_e2e($isExternalOther === false, "http_request_host_is_external returns false for other internal hosts");

} finally {
    $cleanup();
    unset($_SERVER['HTTPS'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
}

if ($hasFailed) {
    if (class_exists('WP_CLI')) {
        WP_CLI::halt(1);
    }
    exit(1);
}
