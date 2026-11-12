<?php
declare(strict_types=1);

namespace Anoofy\Woo\Services;

/** WooCommerce hooks. Registered on every request type: orders arrive from the storefront and the REST API too. */
final class Hooks
{
    public static function register(): void
    {
        // New order of any origin. Block checkout creates a draft first, so the status-change hook below catches
        // the moment it becomes a real order; OrderOpsService::track() skips drafts.
        add_action('woocommerce_new_order', static function ($orderId): void {
            Services::orderOps()->track((int) $orderId);
        }, 20);
        add_action('woocommerce_order_status_changed', static function ($orderId): void {
            Services::orderOps()->track((int) $orderId);
        }, 20);
        add_action('woocommerce_order_status_cancelled', static function ($orderId): void {
            Services::orderOps()->onWooCancelled((int) $orderId);
        }, 20);
        add_action('anoofy_engaz_send', [EngazOutboxWorker::class, 'process']);
        add_action('rest_api_init', [\Anoofy\Woo\Rest\CallbackController::class, 'registerRoutes']);
    }
}
