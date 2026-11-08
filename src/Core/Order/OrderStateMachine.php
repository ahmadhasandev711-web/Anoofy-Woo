<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Order;

use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Identity\Permissions;
use Anoofy\Woo\Core\Identity\Role;
use Closure;

/**
 * Order state machine. Pure: no database, no WooCommerce. A use case loads the context, calls
 * `transition()`, then persists the new status and runs the returned effects.
 *
 * Rules are ported from the Anoofy architecture doc (section 6). Differences for WooCommerce:
 *  - there is no outbound sync, so there is no "sync in progress" lock;
 *  - cancelling the Woo order restores stock natively, so rejecting/cancelling is one effect;
 *  - shipping needs courier + tracking recorded (`hasShipmentDetails`).
 */
final class OrderStateMachine
{
    /** @var array<string, array{from: list<OrderStatus>, to: OrderStatus, action: Action, guard: ?Closure, effects: list<Effect>}>|null */
    private static ?array $rules = null;

    public static function transition(OrderStatus $status, OrderEvent $event, Role $actor, OrderContext $ctx): TransitionResult
    {
        $rule = self::rules()[$event->value];

        if (!in_array($status, $rule['from'], true)) {
            return TransitionResult::failure(TransitionError::InvalidState);
        }
        if (!Permissions::can($actor, $rule['action'])) {
            return TransitionResult::failure(TransitionError::Forbidden);
        }
        if ($rule['guard'] !== null) {
            $error = ($rule['guard'])($ctx, $actor);
            if ($error instanceof TransitionError) {
                return TransitionResult::failure($error);
            }
        }

        return TransitionResult::success($status, $rule['to'], $rule['effects']);
    }

    /**
     * Events the UI may offer for a status (the UI must still call transition() on submit).
     *
     * @return list<OrderEvent>
     */
    public static function eventsFrom(OrderStatus $status): array
    {
        $events = [];
        foreach (OrderEvent::cases() as $event) {
            if (in_array($status, self::rules()[$event->value]['from'], true)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /** The permission an event needs (for showing/hiding buttons without running the guards). */
    public static function actionFor(OrderEvent $event): Action
    {
        return self::rules()[$event->value]['action'];
    }

    /** What an order cancelled directly in WooCommerce does to our operational status. */
    public static function onWooCancelled(OrderStatus $status): ExternalCancelOutcome
    {
        return match ($status) {
            OrderStatus::Cancelled, OrderStatus::Rejected => new ExternalCancelOutcome(null, false, false),
            OrderStatus::Preparing, OrderStatus::ReadyToShip, OrderStatus::CancellationPending
                => new ExternalCancelOutcome(OrderStatus::Cancelled, false, true),
            OrderStatus::New, OrderStatus::UnderReview, OrderStatus::Confirmed
                => new ExternalCancelOutcome(OrderStatus::Cancelled, false, false),
            // Shipped and later: goods may be on the road, never moved automatically.
            default => new ExternalCancelOutcome(null, true, false),
        };
    }

    /** @return array<string, array{from: list<OrderStatus>, to: OrderStatus, action: Action, guard: ?Closure, effects: list<Effect>}> */
    private static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        $needReason = static fn (OrderContext $c): ?TransitionError
            => $c->hasReason() ? null : TransitionError::ReasonRequired;

        return self::$rules = [
            OrderEvent::LogFirstCall->value => [
                'from' => [OrderStatus::New],
                'to' => OrderStatus::UnderReview,
                'action' => Action::OrderLogCall,
                'guard' => null,
                'effects' => [],
            ],
            OrderEvent::Confirm->value => [
                'from' => [OrderStatus::UnderReview],
                'to' => OrderStatus::Confirmed,
                'action' => Action::OrderConfirmOrReject,
                'guard' => static function (OrderContext $c, Role $actor): ?TransitionError {
                    if (!$c->hasLoggedCall) {
                        return TransitionError::CallConfirmationRequired;
                    }
                    if ($c->stockWarningPresent && !$c->stockWarningAccepted) {
                        return Permissions::can($actor, Action::OrderAcceptStockWarning)
                            ? TransitionError::StockWarningNotAccepted
                            : TransitionError::StockWarningNeedsManager;
                    }

                    return null;
                },
                'effects' => [],
            ],
            OrderEvent::Reject->value => [
                'from' => [OrderStatus::UnderReview],
                'to' => OrderStatus::Rejected,
                'action' => Action::OrderConfirmOrReject,
                'guard' => $needReason,
                'effects' => [Effect::CancelWooOrder],
            ],
            OrderEvent::CancelDirect->value => [
                'from' => [OrderStatus::New, OrderStatus::UnderReview, OrderStatus::Confirmed],
                'to' => OrderStatus::Cancelled,
                'action' => Action::OrderCancel,
                'guard' => $needReason,
                'effects' => [Effect::CancelWooOrder],
            ],
            OrderEvent::StartPreparing->value => [
                'from' => [OrderStatus::Confirmed],
                'to' => OrderStatus::Preparing,
                'action' => Action::OrderPrepare,
                'guard' => static fn (OrderContext $c): ?TransitionError
                    => $c->assigned ? null : TransitionError::OrderNotAssigned,
                'effects' => [],
            ],
            OrderEvent::FinishPreparing->value => [
                'from' => [OrderStatus::Preparing],
                'to' => OrderStatus::ReadyToShip,
                'action' => Action::OrderPrepare,
                'guard' => static function (OrderContext $c): ?TransitionError {
                    if (!$c->allLinesPrepared) {
                        return TransitionError::LinesNotPrepared;
                    }

                    return $c->editedAfterPreparingUnconfirmed ? TransitionError::RepreparationNotConfirmed : null;
                },
                'effects' => [],
            ],
            OrderEvent::RequestCancel->value => [
                'from' => [OrderStatus::Preparing, OrderStatus::ReadyToShip],
                'to' => OrderStatus::CancellationPending,
                'action' => Action::OrderCancel,
                'guard' => $needReason, // Woo order is NOT cancelled yet: stock is restored only after the warehouse confirms
                'effects' => [],
            ],
            OrderEvent::ConfirmRestocked->value => [
                'from' => [OrderStatus::CancellationPending],
                'to' => OrderStatus::Cancelled,
                'action' => Action::OrderConfirmRestock,
                'guard' => null,
                'effects' => [Effect::CancelWooOrder],
            ],
            OrderEvent::Ship->value => [
                'from' => [OrderStatus::ReadyToShip],
                'to' => OrderStatus::Shipped,
                'action' => Action::ShipmentCreate,
                'guard' => static fn (OrderContext $c): ?TransitionError
                    => $c->hasShipmentDetails ? null : TransitionError::ShipmentDetailsRequired,
                'effects' => [],
            ],
            OrderEvent::MarkDelivered->value => [
                'from' => [OrderStatus::Shipped],
                'to' => OrderStatus::Delivered,
                'action' => Action::DeliveryRecord,
                'guard' => null,
                'effects' => [],
            ],
            OrderEvent::MarkDeliveryFailed->value => [
                'from' => [OrderStatus::Shipped],
                'to' => OrderStatus::DeliveryFailed,
                'action' => Action::DeliveryRecord,
                'guard' => $needReason,
                'effects' => [],
            ],
            OrderEvent::RetryDelivery->value => [
                'from' => [OrderStatus::DeliveryFailed],
                'to' => OrderStatus::Shipped,
                'action' => Action::DeliveryRetry,
                'guard' => static fn (OrderContext $c): ?TransitionError
                    => $c->deliveryAttempts < $c->maxDeliveryAttempts ? null : TransitionError::MaxDeliveryAttemptsReached,
                'effects' => [],
            ],
            OrderEvent::ReceiveReturned->value => [
                'from' => [OrderStatus::DeliveryFailed],
                'to' => OrderStatus::ReturnedToOrigin,
                'action' => Action::ReturnReceiveAndInspect,
                'guard' => null,
                'effects' => [Effect::OpenRtoReturn, Effect::AddRtoTag],
            ],
        ];
    }
}
