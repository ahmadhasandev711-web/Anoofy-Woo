<?php
declare(strict_types=1);

namespace Anoofy\Woo\Admin;

use Anoofy\Woo\Core\Connection\OutboxStatus;
use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Services\ActionResult;
use Anoofy\Woo\Services\Services;

/**
 * admin-post.php handlers. Every handler: capability check -> nonce -> sanitise -> service -> redirect with a code.
 * The service re-checks permissions through the permission matrix, so a forged request cannot skip them.
 */
final class ActionsController
{
    /** Events the Phase 1 screens may trigger (later phases add more). */
    private const EVENTS = [OrderEvent::Confirm, OrderEvent::Reject, OrderEvent::CancelDirect];

    public static function register(): void
    {
        add_action('admin_post_anoofy_log_call', [self::class, 'logCall']);
        add_action('admin_post_anoofy_order_event', [self::class, 'orderEvent']);
        add_action('admin_post_anoofy_assign', [self::class, 'assign']);
        add_action('admin_post_anoofy_bulk_assign', [self::class, 'bulkAssign']);
        add_action('admin_post_anoofy_connect', [self::class, 'connect']);
        add_action('admin_post_anoofy_disconnect', [self::class, 'disconnect']);
        add_action('admin_post_anoofy_retry_outbox', [self::class, 'retryOutbox']);
    }

    public static function logCall(): void
    {
        $orderId = self::orderId();
        self::guard($orderId, Action::OrderLogCall);

        $outcome = CallOutcome::tryFrom(self::text('anoofy_outcome'));
        if ($outcome === null) {
            self::finish($orderId, ActionResult::fail(ActionResult::INVALID_INPUT), 'call_logged');
        }
        $result = Services::orderOps()->logCall($orderId, get_current_user_id(), $outcome, self::text('anoofy_note', true));
        self::finish($orderId, $result, 'call_logged');
    }

    public static function orderEvent(): void
    {
        $orderId = self::orderId();
        check_admin_referer('anoofy_order_' . $orderId, '_anoofy_nonce');
        $event = OrderEvent::tryFrom(self::text('anoofy_event'));
        if ($event === null || !in_array($event, self::EVENTS, true)) {
            self::finish($orderId, ActionResult::fail(ActionResult::INVALID_INPUT), 'confirm');
        }
        self::guard($orderId, \Anoofy\Woo\Core\Order\OrderStateMachine::actionFor($event));

        $result = Services::orderOps()->runEvent($orderId, $event, get_current_user_id(), self::text('anoofy_reason', true));
        self::finish($orderId, $result, strtolower($event->value));
    }

    public static function assign(): void
    {
        $orderId = self::orderId();
        self::guard($orderId, Action::OrderAssign);

        $assignee = isset($_POST['anoofy_assignee']) ? absint(wp_unslash($_POST['anoofy_assignee'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard()
        $result = Services::orderOps()->assign($orderId, get_current_user_id(), $assignee > 0 ? $assignee : null);
        self::finish($orderId, $result, 'assigned');
    }

    public static function bulkAssign(): void
    {
        check_admin_referer('anoofy_bulk_assign', '_anoofy_nonce');
        if (!current_user_can(Action::OrderAssign->capability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'anoofy-woo'), '', ['response' => 403]);
        }

        $back = admin_url('admin.php?page=anoofy');
        $assignee = isset($_POST['anoofy_assignee']) ? absint(wp_unslash($_POST['anoofy_assignee'])) : 0;
        $ids = isset($_POST['order_ids']) && is_array($_POST['order_ids']) ? array_map('absint', wp_unslash($_POST['order_ids'])) : [];
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            Notices::redirect($back, ActionResult::INVALID_INPUT, false);
        }

        $service = Services::orderOps();
        foreach ($ids as $id) {
            $result = $service->assign($id, get_current_user_id(), $assignee > 0 ? $assignee : null);
            if (!$result->ok) {
                Notices::redirect($back, $result->code, false);
            }
        }
        Notices::redirect($back, 'bulk_assigned', true);
    }

    private static function orderId(): int
    {
        return isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard()
    }

    private static function guard(int $orderId, Action $action): void
    {
        if ($orderId <= 0) {
            wp_die(esc_html__('Invalid request.', 'anoofy-woo'), '', ['response' => 400]);
        }
        check_admin_referer('anoofy_order_' . $orderId, '_anoofy_nonce');
        if (!current_user_can($action->capability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'anoofy-woo'), '', ['response' => 403]);
        }
    }

    private static function text(string $key, bool $multiline = false): string
    {
        if (!isset($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard()
            return '';
        }
        $raw = $_POST[$key]; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!is_string($raw)) {
            return '';
        }
        $value = wp_unslash($raw);

        return $multiline ? sanitize_textarea_field($value) : sanitize_text_field($value);
    }

    public static function connect(): void
    {
        if (!current_user_can(Action::IntegrationManage->capability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'anoofy-woo'));
        }
        check_admin_referer('anoofy_connect', '_anoofy_nonce');

        $key = sanitize_textarea_field(wp_unslash($_POST['anoofy_connection_key'] ?? ''));
        $result = Services::engazConnection()->connect($key);

        Notices::redirect(
            admin_url('admin.php?page=anoofy-connection'),
            $result->ok ? 'connected' : $result->code,
            $result->ok
        );
    }

    public static function disconnect(): void
    {
        if (!current_user_can(Action::IntegrationManage->capability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'anoofy-woo'));
        }
        check_admin_referer('anoofy_disconnect', '_anoofy_nonce');

        Services::engazConnection()->disconnect();

        Notices::redirect(
            admin_url('admin.php?page=anoofy-connection'),
            'disconnected',
            true
        );
    }

    public static function retryOutbox(): void
    {
        $orderId = self::orderId();
        self::guard($orderId, Action::IntegrationManage);
        check_admin_referer('anoofy_retry_outbox_' . $orderId, '_anoofy_nonce');

        $outboxId = isset($_POST['anoofy_outbox_id']) ? (int) $_POST['anoofy_outbox_id'] : 0;
        if ($outboxId > 0) {
            Services::outbox()->updateStatus($outboxId, OutboxStatus::Pending, nextRetryAt: gmdate('Y-m-d H:i:s'));
            \Anoofy\Woo\Services\EngazOutboxWorker::process($outboxId);
        }

        $order = wc_get_order($orderId);
        $url = $order instanceof \WC_Order ? $order->get_edit_order_url() : admin_url('admin.php?page=anoofy');
        wp_safe_redirect($url);
        exit;
    }

    private static function finish(int $orderId, ActionResult $result, string $successCode): never
    {
        $order = wc_get_order($orderId);
        $url = $order instanceof \WC_Order ? $order->get_edit_order_url() : admin_url('admin.php?page=anoofy');
        // A partial success (status changed, Woo update failed) is reported as an error so nobody misses it.
        $ok = $result->ok && $result->code === ActionResult::OK;
        Notices::redirect($url, $ok ? $successCode : $result->code, $ok);
    }
}
