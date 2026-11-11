<?php
declare(strict_types=1);

namespace Anoofy\Woo\Admin;

use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Services\Services;

/** Result banner after an action. Only whitelisted codes travel in the URL, never free text. */
final class Notices
{
    private const KEY = 'anoofy_notice';
    private const OK = 'anoofy_ok';

    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render']);
    }

    public static function redirect(string $url, string $code, bool $ok): never
    {
        wp_safe_redirect(add_query_arg([self::KEY => $code, self::OK => $ok ? '1' : '0'], $url));
        exit;
    }

    public static function render(): void
    {
        if (Services::engazConnection()->isAuthFailed() && current_user_can(Action::IntegrationManage->capability())) {
            $authMsg = __('The EngazPRO connection needs to be re-established (the key was rejected or the server clock is wrong).', 'anoofy-woo');
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($authMsg));
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only banner, whitelisted codes only
        $raw = $_GET[self::KEY] ?? null;
        if (!is_string($raw)) {
            return;
        }
        $code = sanitize_key(wp_unslash($raw));
        $ok = ($_GET[self::OK] ?? null) === '1';
        // phpcs:enable

        $message = $ok ? Labels::success($code) : Labels::error(strtoupper($code));
        if ($message === null) {
            return;
        }
        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', $ok ? 'notice-success' : 'notice-error', esc_html($message));
    }
}
