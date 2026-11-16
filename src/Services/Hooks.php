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
        add_action('woocommerce_update_order', static function ($orderId): void {
            Services::orderOps()->onWooOrderEdited((int) $orderId);
        }, 20);
        add_action('woocommerce_saved_order_items', static function ($orderId): void {
            Services::orderOps()->onWooOrderEdited((int) $orderId);
        }, 20);
        add_action('woocommerce_update_order_item', static function ($itemId, $item, $orderId = 0): void {
            $id = (int) $orderId ?: ($item instanceof \WC_Order_Item ? $item->get_order_id() : 0);
            if ($id > 0) {
                Services::orderOps()->onWooOrderEdited($id);
            }
        }, 20, 3);
        add_action('woocommerce_new_order_item', static function ($itemId, $item, $orderId = 0): void {
            $id = (int) $orderId ?: ($item instanceof \WC_Order_Item ? $item->get_order_id() : 0);
            if ($id > 0) {
                Services::orderOps()->onWooOrderEdited($id);
            }
        }, 20, 3);
        $deletingItemOrders = [];
        add_action('woocommerce_before_delete_order_item', static function ($itemId) use (&$deletingItemOrders): void {
            if (function_exists('wc_get_order_id_by_order_item_id')) {
                $id = (int) wc_get_order_id_by_order_item_id((int) $itemId);
                if ($id > 0) {
                    $deletingItemOrders[(int) $itemId] = $id;
                }
            }
        }, 20);
        add_action('woocommerce_delete_order_item', static function ($itemId) use (&$deletingItemOrders): void {
            $id = $deletingItemOrders[(int) $itemId] ?? 0;
            unset($deletingItemOrders[(int) $itemId]);
            if ($id > 0) {
                Services::orderOps()->onWooOrderEdited($id);
            }
        }, 20);
        add_action('anoofy_engaz_send', [EngazOutboxWorker::class, 'process']);
        add_action('rest_api_init', [\Anoofy\Woo\Rest\CallbackController::class, 'registerRoutes']);
    }
}
