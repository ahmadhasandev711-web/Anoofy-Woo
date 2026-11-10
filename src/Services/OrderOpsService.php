<?php
declare(strict_types=1);

namespace Anoofy\Woo\Services;

use Anoofy\Woo\Core\Connection\EngazEventType;
use Anoofy\Woo\Core\Connection\EnvelopeFactory;
use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Identity\Permissions;
use Anoofy\Woo\Core\Identity\Role;
use Anoofy\Woo\Core\Identity\RoleResolver;
use Anoofy\Woo\Core\Mode\ModePolicy;
use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\Effect;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Core\Order\OrderStateMachine;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Core\Order\TransitionError;
use Anoofy\Woo\Core\Order\WooStatusMap;
use Anoofy\Woo\Data\AuditLog;
use Anoofy\Woo\Data\CallLog;
use Anoofy\Woo\Data\OpsRepository;

/**
 * The only place that changes an order's operational state: permission check -> state machine ->
 * compare-and-set -> audit -> effects on WooCommerce. Screens and hooks call this, never the repository directly.
 */
final class OrderOpsService
{
    /** Not real orders yet (drafts, awaiting payment, failed): tracked later when they change to a real status. */
    private const UNTRACKED_WOO_STATUSES = ['checkout-draft', 'auto-draft', 'trash', 'pending', 'failed'];
    private const MAX_NOTE = 1000;

    /** @var array<int, true> orders we are cancelling ourselves right now (to ignore our own Woo cancel hook) */
    private array $cancelling = [];

    public function __construct(
        private readonly OpsRepository $ops,
        private readonly CallLog $calls,
        private readonly AuditLog $audit,
    ) {
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
        if (!ModePolicy::allows($event, $ops->isHandedOver())) {
            return ActionResult::fail(ActionResult::FORBIDDEN);
        }
        $result = OrderStateMachine::transition($ops->status, $event, $role, $ops->toContext($reason));
        if (!$result->ok || $result->from === null || $result->to === null) {
            return ActionResult::fail($result->error?->value ?? TransitionError::InvalidState->value);
        }

        // Compare-and-set: if another agent moved the order meanwhile, this changes 0 rows and we stop.
        if (!$this->ops->compareAndSetStatus($orderId, $result->from, $result->to)) {
            return ActionResult::fail(ActionResult::CONCURRENT_CHANGE);
        }
        $this->audit->record($orderId, $userId, 'order.' . strtolower($event->value), $result->from->value, $result->to->value, $reason);

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
        if ($ops === null) {
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
            }
            // OpenRtoReturn / AddRtoTag belong to Phase 5 (returns) and are intentionally ignored here.
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

    private function handOverToEngaz(\WC_Order $order, int $userId): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $version = $this->ops->recordHandover($order->get_id(), $now);

        $user = get_userdata($userId);
        $confirmedByName = $user instanceof \WP_User ? $user->display_name : '';

        $lineItems = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            $productId = $item->get_product_id();
            $variationId = $item->get_variation_id();
            $sku = $product ? $product->get_sku() : '';
            $quantity = $item->get_quantity();
            $subtotal = (float) $item->get_subtotal();
            $total = (float) $item->get_total();
            $price = $quantity > 0 ? (float) ($subtotal / $quantity) : 0.0;

            $lineItems[] = [
                'wc_product_id' => $productId,
                'wc_variation_id' => $variationId > 0 ? $variationId : null,
                'quantity' => $quantity,
                'name' => $item->get_name(),
                'sku' => $sku !== '' ? $sku : null,
                'price' => (int) round($price) == $price ? (int) $price : $price,
                'total' => (int) round($total) == $total ? (int) $total : $total,
            ];
        }

        $address1 = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
        $address2 = $order->get_shipping_address_2() ?: $order->get_billing_address_2();
        $city = $order->get_shipping_city() ?: $order->get_billing_city();
        $state = $order->get_shipping_state() ?: $order->get_billing_state();
        $phone = $order->get_billing_phone();

        $data = [
            'wc_order_id' => $order->get_id(),
            'confirmed_at' => $now,
            'confirmed_by_name' => $confirmedByName,
            'customer' => [
                'phone' => $phone,
                'address_1' => $address1,
                'address_2' => $address2,
                'city' => $city,
                'state' => $state,
            ],
            'line_items' => $lineItems,
        ];

        $envelope = EnvelopeFactory::build(
            type: EngazEventType::OrderConfirmed,
            orderVersion: $version,
            data: $data,
            occurredAt: new \DateTimeImmutable($now)
        );

        $payload = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $outboxId = Services::outbox()->create(
            $envelope['event_id'],
            $envelope['event_type'],
            $order->get_id(),
            $version,
            $payload
        );

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('anoofy_engaz_send', ['outbox_id' => $outboxId]);
        }
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
