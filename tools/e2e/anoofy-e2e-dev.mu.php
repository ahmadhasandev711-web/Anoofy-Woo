<?php
/**
 * Plugin Name: Anoofy E2E Dev Helper
 * Description: Development-only MU plugin for local Docker e2e testing with EngazPRO.
 * Version: 1.0.0
 *
 * DO NOT SHIP WITH PRODUCTION PLUGIN.
 */

defined('ABSPATH') || exit;

// Safety guard: strictly local environment only.
if (function_exists('wp_get_environment_type') && wp_get_environment_type() !== 'local') {
    return;
}

// 1. Allow outbound HTTP requests to engazpro.test
add_filter('http_request_host_is_external', static function (bool $isExternal, string $host): bool {
    if (strtolower($host) === 'engazpro.test') {
        return true;
    }
    return $isExternal;
}, 10, 2);

// 2. Enable WooCommerce Basic Auth over HTTP for local dev
$enableLocalWcBasicAuth = static function (): void {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($uri, PHP_URL_PATH) ?? '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $hasBasicAuth = (stripos($authHeader, 'Basic ') === 0) || !empty($_SERVER['PHP_AUTH_USER']);

    if (str_starts_with($path, '/wp-json/wc/') && $hasBasicAuth) {
        $_SERVER['HTTPS'] = 'on';
    }
};

// Run early on request load and before WooCommerce authentication in determine_current_user
$enableLocalWcBasicAuth();

add_filter('determine_current_user', static function ($user) use ($enableLocalWcBasicAuth) {
    $enableLocalWcBasicAuth();
    return $user;
}, 5);
