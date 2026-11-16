<?php
declare(strict_types=1);

namespace Anoofy\Woo\Services;

use Anoofy\Woo\Core\Connection\EngazEventType;
use Anoofy\Woo\Core\Connection\EnvelopeFactory;
use Anoofy\Woo\Core\Connection\OrderConfirmedPayload;
use Anoofy\Woo\Core\Connection\OrderHandoverData;
use Anoofy\Woo\Core\Connection\OrderHandoverItem;
use Anoofy\Woo\Core\Connection\OutboxStatus;
use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Identity\Permissions;
use Anoofy\Woo\Core\Identity\Role;
use Anoofy\Woo\Core\Identity\RoleResolver;
use Anoofy\Woo\Core\Mode\ModePolicy;
use Anoofy\Woo\Core\Mode\OperatingMode;
use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\DeliveryOutcome;
use Anoofy\Woo\Core\Order\Effect;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Core\Order\OrderOps;
use Anoofy\Woo\Core\Order\OrderStateMachine;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Core\Order\PreparationChecker;
use Anoofy\Woo\Core\Order\TransitionError;
use Anoofy\Woo\Core\Order\WooStatusMap;
use Anoofy\Woo\Data\AuditLog;
use Anoofy\Woo\Data\CallLog;
use Anoofy\Woo\Data\DeliveryAttemptLog;
use Anoofy\Woo\Data\OpsRepository;
use Anoofy\Woo\Data\PreparedItemsRepository;

/**
 * The only place that changes an order's operational state: permission check -> state machine ->
 * compare-and-set -> audit -> effects on WooCommerce. Screens and hooks call this, never the repository directly.
 */
final class OrderOpsService
{
    /** Not real orders yet (drafts, awaiting payment, failed): tracked later when they change to a real status. */
    private const UNTRACKED_WOO_STATUSES = ['checkout-draft', 'auto-draft', 'trash', OutboxStatus::Pending->value, OutboxStatus::Failed->value];
    private const MAX_NOTE = 1000;

    /** @var array<int, true> orders we are cancelling ourselves right now (to ignore our own Woo cancel hook) */
    private array $cancelling = [];

    private readonly PreparedItemsRepository $preparedItems;
    private readonly DeliveryAttemptLog $deliveryLog;

    public function __construct(
        private readonly OpsRepository $ops,
        private readonly CallLog $calls,
        private readonly AuditLog $audit,
        ?PreparedItemsRepository $preparedItems = null,
        ?DeliveryAttemptLog $deliveryLog = null,
    ) {
        $this->preparedItems = $preparedItems ?? new PreparedItemsRepository();
        $this->deliveryLog = $deliveryLog ?? new DeliveryAttemptLog();
    }

    public function maxDeliveryAttempts(): int
    {
        $max = (int) apply_filters('anoofy_max_delivery_attempts', (int) get_option('anoofy_max_delivery_attempts', 3));

        return max(1, $max);
    }

    public function roleFor(int $userId): ?Role
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User) {
            return null;
        }

        return RoleResolver::fromWpRoles(array_values(array_map('strval', $user->roles)));
    }

    /** Called from WooCommerce hooks: make sure every real (non-draft) order has an ops row. */
    public function track(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order || in_array($order->get_status(), self::UNTRACKED_WOO_STATUSES, true)) {
            return;
        }
        if ($this->ops->find($orderId) !== null) {
            return;
        }
        $this->ops->ensure($orderId);
        $this->audit->record($orderId, 0, 'order.tracked', null, OrderStatus::New->value);
    }

    public function runEvent(int $orderId, OrderEvent $event, int $userId, ?string $reason = null): ActionResult
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return ActionResult::fail(ActionResult::NOT_FOUND);
        }
        $role = $this->roleFor($userId);
        if ($role === null) {
            return ActionResult::fail(ActionResult::FORBIDDEN);
        }

        $reason = $this->clean($reason);
        $ops = $this->ops->ensure($orderId);
        if (!ModePolicy::allows($ops->isHandedOver())) {
            return ActionResult::fail(ActionResult::FORBIDDEN);
        }

        $allLinesPrepared = $event === OrderEvent::FinishPreparing
            ? PreparationChecker::allPrepared($this->orderItemIds($order), $this->preparedItems->forOrder($orderId))
            : false;

        $result = OrderStateMachine::transition($ops->status, $event, $role, $ops->toContext($reason, $allLinesPrepared, $this->maxDeliveryAttempts()));
        if (!$result->ok || $result->from === null || $result->to === null) {
            return ActionResult::fail($result->error?->value ?? TransitionError::InvalidState->value);
        }

        // Compare-and-set: if another agent moved the order meanwhile, this changes 0 rows and we stop.
        if (!$this->ops->compareAndSetStatus($orderId, $result->from, $result->to)) {
            return ActionResult::fail(ActionResult::CONCURRENT_CHANGE);
        }
        $this->audit->record($orderId, $userId, 'order.' . strtolower($event->value), $result->from->value, $result->to->value, $reason);

        if ($event === OrderEvent::StartPreparing) {
            $this->preparedItems->clearOrder($orderId);
            $this->ops->setPrepState($orderId, $this->itemsDigest($order), false);
        } elseif ($event === OrderEvent::MarkDeliveryFailed) {
            $attemptNo = $this->ops->incrementDeliveryAttempts($orderId);
            $this->deliveryLog->record($orderId, $attemptNo, $userId, DeliveryOutcome::Failed, $reason);
        } elseif ($event === OrderEvent::MarkDelivered) {
            $this->deliveryLog->record($orderId, $ops->deliveryAttempts + 1, $userId, DeliveryOutcome::Delivered, null);
        }

        $extraEffects = ModePolicy::extraEffects(Services::mode(), $event);
        $allEffects = array_merge($result->effects, $extraEffects);

        try {
            $this->applyEffects($order, $allEffects, $result->to, $reason, $userId);
        } catch (\Throwable $e) {
            $this->audit->record($orderId, $userId, 'order.effect_failed', $result->from->value, $result->to->value, null, ['error' => $e->getMessage()]);

            return ActionResult::ok(ActionResult::EFFECT_FAILED);
        }

        return ActionResult::ok();
    }

    /** @param list<int> $itemIds */
    public function savePreparedItems(int $orderId, int $userId, array $itemIds): ActionResult
    {
        $checked = $this->guardStandaloneOrder($orderId, $userId, Action::OrderPrepare, [OrderStatus::Preparing]);
        if ($checked instanceof ActionResult) {
            return $checked;
        }
        [$order, $ops] = $checked;

        $validLookup = array_flip($this->orderItemIds($order));
        $filtered = [];
        foreach ($itemIds as $id) {
            $id = (int) $id;
            if (isset($validLookup[$id])) {
                $filtered[] = $id;
            }
        }
        $filtered = array_values(array_unique($filtered));

        $this->preparedItems->setPreparedItems($orderId, $filtered, $userId);
        if ($ops->prepItemsHash === null) {
            $this->ops->setPrepState($orderId, $this->itemsDigest($order), $ops->editedAfterPreparing);
        }
        $this->audit->record($orderId, $userId, 'order.prepared_items_saved', null, null, null, [
            'prepared_count' => count($filtered),
            'total_items' => count($validLookup),
        ]);

        return ActionResult::ok();
    }

    public function confirmRepreparation(int $orderId, int $userId): ActionResult
    {
        $checked = $this->guardStandaloneOrder($orderId, $userId, Action::OrderConfirmRepreparation, [OrderStatus::Preparing]);
        if ($checked instanceof ActionResult) {
            return $checked;
        }
        [$order] = $checked;

        $this->ops->setPrepState($orderId, $this->itemsDigest($order), false);
        $this->audit->record($orderId, $userId, 'order.confirm_repreparation');

        return ActionResult::ok();
    }

    public function saveShipmentDetails(int $orderId, int $userId, string $courier, string $trackingNumber): ActionResult
    {
        $checked = $this->guardStandaloneOrder($orderId, $userId, Action::ShipmentCreate, [OrderStatus::ReadyToShip]);
        if ($checked instanceof ActionResult) {
            return $checked;
        }

        $courier = mb_substr(trim(sanitize_text_field($courier)), 0, 100);
        $trackingNumber = mb_substr(trim(sanitize_text_field($trackingNumber)), 0, 100);
        if ($courier === '' || $trackingNumber === '') {
            return ActionResult::fail(TransitionError::ShipmentDetailsRequired->value);
        }

        $this->ops->setShipmentDetails($orderId, $courier, $trackingNumber);
        $this->audit->record($orderId, $userId, 'order.shipment_details_saved', null, null, null, [
            'courier' => $courier,
            'tracking_number' => $trackingNumber,
        ]);

        return ActionResult::ok();
    }

    /** Hook for WooCommerce order/item edits: flags re-preparation and reopens READY_TO_SHIP if line items changed. */
    public function onWooOrderEdited(int $orderId): void
    {
        $ops = $this->ops->find($orderId);
        if ($ops === null || $ops->isHandedOver()) {
            return;
        }

        $target = OrderStateMachine::onWooOrderEdited($ops->status);
        if ($target === null) {
            return;
        }

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('order-items-' . $orderId, 'orders');
        }
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }

        $newHash = $this->itemsDigest($order);
        if ($ops->prepItemsHash !== null && hash_equals($ops->prepItemsHash, $newHash)) {
            return;
        }

        $actor = get_current_user_id();
        if ($ops->status === OrderStatus::ReadyToShip) {
            if (!$this->ops->compareAndSetStatus($orderId, OrderStatus::ReadyToShip, OrderStatus::Preparing)) {
                return;
            }
            $this->audit->record($orderId, $actor, 'order.reopened_for_repreparation', OrderStatus::ReadyToShip->value, OrderStatus::Preparing->value);
        } elseif (!$ops->editedAfterPreparing) {
            $this->audit->record($orderId, $actor, 'order.edited_after_preparing', $ops->status->value, OrderStatus::Preparing->value);
        }

        $this->ops->setPrepState($orderId, $newHash, true);
        $this->preparedItems->clearOrder($orderId);
        $order->add_order_note(__('Anoofy: order items were modified during preparation; re-preparation confirmation is required.', 'anoofy-woo'));
    }

    /**
     * Shared validation for Phase 2 standalone mutations.
     *
     * @param list<OrderStatus> $allowedStatuses
     * @return ActionResult|array{\WC_Order, OrderOps}
     */
    private function guardStandaloneOrder(int $orderId, int $userId, Action $action, array $allowedStatuses): ActionResult|array
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return ActionResult::fail(ActionResult::NOT_FOUND);
        }
        $role = $this->roleFor($userId);
        if ($role === null || !Permissions::can($role, $action)) {
            return ActionResult::fail(ActionResult::FORBIDDEN);
        }
        $ops = $this->ops->ensure($orderId);
        if (!ModePolicy::allows($ops->isHandedOver())) {
            return ActionResult::fail(ActionResult::FORBIDDEN);
        }
        if (!in_array($ops->status, $allowedStatuses, true)) {
            return ActionResult::fail(TransitionError::InvalidState->value);
        }

        return [$order, $ops];
    }

    /** @return list<int> */
    private function orderItemIds(\WC_Order $order): array
    {
        $ids = [];
        foreach ($order->get_items('line_item') as $item) {
            if ($item instanceof \WC_Order_Item_Product) {
                $ids[] = $item->get_id();
            }
        }

        return $ids;
    }

    private function itemsDigest(\WC_Order $order): string
    {
        $lines = [];
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $lines[] = [
                'item_id' => $item->get_id(),
                'product_id' => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'qty' => $item->get_quantity(),
            ];
        }

        return PreparationChecker::itemsHash($lines);
    }

    public function logCall(int $orderId, int $userId, CallOutcome $outcome, ?string $note): ActionResult
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return ActionResult::fail(ActionResult::NOT_FOUND);
        }
        $role = $this->roleFor($userId);
        if ($role === null || !Permissions::can($role, Action::OrderLogCall)) {
            return ActionResult::fail(ActionResult::FORBIDDEN);
        }

        $ops = $this->ops->ensure($orderId);
        if (!in_array($ops->status, [OrderStatus::New, OrderStatus::UnderReview], true)) {
            return ActionResult::fail(TransitionError::InvalidState->value);
        }

        // The first call of any outcome moves NEW -> UNDER_REVIEW.
        if ($ops->status === OrderStatus::New) {
            $moved = $this->runEvent($orderId, OrderEvent::LogFirstCall, $userId);
            if (!$moved->ok && $moved->code !== ActionResult::CONCURRENT_CHANGE) {
                return $moved;
            }
        }

        $note = $this->clean($note);
        $this->calls->add($orderId, $userId, $outcome, $note);
        if ($outcome->countsAsConfirmationCall()) {
            $this->ops->markCallConfirmed($orderId);
        }
        $this->audit->record($orderId, $userId, 'order.call_logged', null, null, $note, ['outcome' => $outcome->value]);

        return ActionResult::ok();
    }

    public function assign(int $orderId, int $actorId, ?int $assigneeId): ActionResult
    {
        if (!wc_get_order($orderId) instanceof \WC_Order) {
            return ActionResult::fail(ActionResult::NOT_FOUND);
        }
        $role = $this->roleFor($actorId);
        if ($role === null || !Permissions::can($role, Action::OrderAssign)) {
            return ActionResult::fail(ActionResult::FORBIDDEN);
        }
        $ops = $this->ops->find($orderId);
        if ($ops !== null && $ops->isHandedOver()) {
            return ActionResult::fail(ActionResult::FORBIDDEN);
        }
        if ($assigneeId !== null && $assigneeId > 0 && $this->roleFor($assigneeId) === null) {
            return ActionResult::fail(ActionResult::INVALID_ASSIGNEE);
        }

        $this->ops->ensure($orderId);
        $this->ops->assign($orderId, $assigneeId);
        $this->audit->record($orderId, $actorId, 'order.assigned', null, null, null, ['assigned_to' => $assigneeId]);

        return ActionResult::ok();
    }

    /** Hook for `woocommerce_order_status_cancelled`: someone cancelled in WooCommerce outside our workflow. */
    public function onWooCancelled(int $orderId): void
    {
        if (isset($this->cancelling[$orderId])) {
            return;
        }
        $ops = $this->ops->find($orderId);
        if ($ops === null || $ops->isHandedOver()) {
            return;
        }

        $outcome = OrderStateMachine::onWooCancelled($ops->status);
        $actor = get_current_user_id();
        $order = wc_get_order($orderId);

        if ($outcome->to !== null && $this->ops->compareAndSetStatus($orderId, $ops->status, $outcome->to)) {
            $this->audit->record($orderId, $actor, 'order.woo_cancelled', $ops->status->value, $outcome->to->value);
            if ($outcome->createRestockTask && $order instanceof \WC_Order) {
                $order->add_order_note(__('Anoofy: this order was cancelled in WooCommerce while it was being prepared. Make sure the items are put back on the shelf.', 'anoofy-woo'));
            }
        } elseif ($outcome->needsDecision) {
            $this->audit->record($orderId, $actor, 'order.woo_cancelled_needs_decision', $ops->status->value);
            if ($order instanceof \WC_Order) {
                $order->add_order_note(__('Anoofy: this order was cancelled in WooCommerce after it had shipped. Its operational status was NOT changed; decide what to do with the shipment.', 'anoofy-woo'));
            }
        }
    }

    /** @param list<Effect> $effects */
    private function applyEffects(\WC_Order $order, array $effects, OrderStatus $newStatus, ?string $reason, int $userId = 0): void
    {
        $cancelled = false;
        foreach ($effects as $effect) {
            if ($effect === Effect::CancelWooOrder) {
                $cancelled = true;
                $this->cancelling[$order->get_id()] = true;
                try {
                    $order->update_status('cancelled', sprintf(
                        /* translators: %s: reason entered by the employee */
                        __('Cancelled by Anoofy: %s', 'anoofy-woo'),
                        $reason ?? '-',
                    ));
                } finally {
                    unset($this->cancelling[$order->get_id()]);
                }
            } elseif ($effect === Effect::HandOverToEngaz) {
                $this->handOverToEngaz($order, $userId);
            } elseif ($effect === Effect::OpenRtoReturn) {
                Services::returnService()->openRtoReturn($order, $userId, $reason);
            } elseif ($effect === Effect::AddRtoTag) {
                Services::returnService()->addRtoTag($order);
            }
        }

        // Optional mirror of the ops status onto the Woo status. Off by default until spikes S-1/S-2 confirm
        // which Woo emails a status change triggers (see SPEC section 6).
        if (!$cancelled && $this->syncWooStatusEnabled()) {
            $target = WooStatusMap::forOps($newStatus);
            if ($target !== null && $order->get_status() !== $target) {
                $order->update_status($target, __('Status updated by Anoofy.', 'anoofy-woo'));
            }
        }
    }

    public function canRetryHandover(int $orderId): bool
    {
        if (Services::mode() !== OperatingMode::Connected) {
            return false;
        }

        $ops = $this->ops->find($orderId);
        if ($ops === null || $ops->status !== OrderStatus::Confirmed || $ops->isHandedOver()) {
            return false;
        }

        $conn = Services::engazConnection()->getConnection();
        if ($conn === null || empty($conn['connected_at'])) {
            return false;
        }

        return $this->audit->hasActionAfter($orderId, 'order.effect_failed', $conn['connected_at']);
    }

    public function retryHandover(int $orderId, int $userId): ActionResult
    {
        $role = $this->roleFor($userId);
        if ($role === null || !Permissions::can($role, Action::IntegrationManage)) {
            return ActionResult::fail(ActionResult::FORBIDDEN);
        }

        if (!$this->canRetryHandover($orderId)) {
            return ActionResult::fail(ActionResult::FORBIDDEN);
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return ActionResult::fail(ActionResult::NOT_FOUND);
        }

        $ok = $this->handOverToEngaz($order, $userId);

        return $ok ? ActionResult::ok() : ActionResult::fail(ActionResult::EFFECT_FAILED);
    }

    private function handOverToEngaz(\WC_Order $order, int $userId): bool
    {
        global $wpdb;

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $user = get_userdata($userId);
        $confirmedByName = $user instanceof \WP_User ? $user->display_name : '';

        $handoverData = $this->extractHandoverData($order, $now, $confirmedByName);
        $payloadData = OrderConfirmedPayload::build($handoverData);

        $wpdb->query('START TRANSACTION');
        try {
            $version = $this->ops->recordHandover($order->get_id(), $now);
            $envelope = EnvelopeFactory::build(EngazEventType::OrderConfirmed, $version, $payloadData, occurredAt: new \DateTimeImmutable($now));
            $payload = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $outboxId = Services::outbox()->create($envelope['event_id'], $envelope['event_type'], $order->get_id(), $version, $payload);
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            $this->audit->record($order->get_id(), $userId, 'order.effect_failed', null, null, 'handover_failed: ' . $e->getMessage());
            return false;
        }

        if (function_exists('as_enqueue_async_action') && $outboxId > 0) {
            as_enqueue_async_action('anoofy_engaz_send', ['outbox_id' => $outboxId]);
        }

        return true;
    }

    private function extractHandoverData(\WC_Order $order, string $now, string $confirmedByName): OrderHandoverData
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            $variationId = $item->get_variation_id();
            $sku = $product ? $product->get_sku() : '';

            $items[] = OrderHandoverItem::fromMoney(
                productId: $item->get_product_id(),
                variationId: $variationId > 0 ? $variationId : null,
                quantity: $item->get_quantity(),
                name: $item->get_name(),
                sku: $sku !== '' ? $sku : null,
                subtotal: (string) $item->get_subtotal(),
                total: (string) $item->get_total()
            );
        }

        return new OrderHandoverData(
            orderId: $order->get_id(),
            confirmedAt: $now,
            confirmedByName: $confirmedByName,
            customerPhone: $order->get_billing_phone() ?: null,
            address1: ($order->get_shipping_address_1() ?: $order->get_billing_address_1()) ?: null,
            address2: ($order->get_shipping_address_2() ?: $order->get_billing_address_2()) ?: null,
            city: ($order->get_shipping_city() ?: $order->get_billing_city()) ?: null,
            state: ($order->get_shipping_state() ?: $order->get_billing_state()) ?: null,
            items: $items
        );
    }

    private function syncWooStatusEnabled(): bool
    {
        return (bool) apply_filters('anoofy_sync_woo_status', (bool) get_option('anoofy_sync_woo_status', false));
    }

    private function clean(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim(sanitize_textarea_field($text));

        return $text === '' ? null : mb_substr($text, 0, self::MAX_NOTE);
    }
}
