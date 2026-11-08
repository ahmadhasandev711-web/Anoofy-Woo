<?php
declare(strict_types=1);

namespace Anoofy\Woo\Admin;

use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Services\ActionResult;

/**
 * Every user-facing label lives here as a literal `__()` call so translation tools can extract it.
 * (The pure Core never contains display text.)
 */
final class Labels
{
    public static function status(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::New => __('New', 'anoofy-woo'),
            OrderStatus::UnderReview => __('Under review', 'anoofy-woo'),
            OrderStatus::Confirmed => __('Confirmed', 'anoofy-woo'),
            OrderStatus::Rejected => __('Rejected', 'anoofy-woo'),
            OrderStatus::Preparing => __('Preparing', 'anoofy-woo'),
            OrderStatus::ReadyToShip => __('Ready to ship', 'anoofy-woo'),
            OrderStatus::CancellationPending => __('Cancellation pending', 'anoofy-woo'),
            OrderStatus::Cancelled => __('Cancelled', 'anoofy-woo'),
            OrderStatus::Shipped => __('Shipped', 'anoofy-woo'),
            OrderStatus::Delivered => __('Delivered', 'anoofy-woo'),
            OrderStatus::DeliveryFailed => __('Delivery failed', 'anoofy-woo'),
            OrderStatus::ReturnedToOrigin => __('Returned to origin', 'anoofy-woo'),
        };
    }

    public static function tab(string $key): string
    {
        return match ($key) {
            'new' => __('New', 'anoofy-woo'),
            'under_review' => __('Under review', 'anoofy-woo'),
            'confirmed' => __('Confirmed', 'anoofy-woo'),
            'in_progress' => __('In progress', 'anoofy-woo'),
            'finished' => __('Finished', 'anoofy-woo'),
            'closed' => __('Rejected / cancelled', 'anoofy-woo'),
            default => __('All', 'anoofy-woo'),
        };
    }

    public static function outcome(CallOutcome $outcome): string
    {
        return match ($outcome) {
            CallOutcome::Answered => __('Answered', 'anoofy-woo'),
            CallOutcome::NoAnswer => __('No answer', 'anoofy-woo'),
            CallOutcome::Busy => __('Busy', 'anoofy-woo'),
            CallOutcome::WrongNumber => __('Wrong number', 'anoofy-woo'),
            CallOutcome::CallLater => __('Call back later', 'anoofy-woo'),
        };
    }

    public static function event(OrderEvent $event): string
    {
        return match ($event) {
            OrderEvent::Confirm => __('Confirm order', 'anoofy-woo'),
            OrderEvent::Reject => __('Reject order', 'anoofy-woo'),
            OrderEvent::CancelDirect => __('Cancel order', 'anoofy-woo'),
            default => $event->value,
        };
    }

    /** Success messages, keyed by the code the controller passes in the redirect. */
    public static function success(string $code): ?string
    {
        return match ($code) {
            'call_logged' => __('Call logged.', 'anoofy-woo'),
            'confirm' => __('Order confirmed.', 'anoofy-woo'),
            'reject' => __('Order rejected and cancelled in WooCommerce.', 'anoofy-woo'),
            'cancel_direct' => __('Order cancelled.', 'anoofy-woo'),
            'assigned' => __('Assignment updated.', 'anoofy-woo'),
            'bulk_assigned' => __('Selected orders assigned.', 'anoofy-woo'),
            default => null,
        };
    }

    /** Error messages for a TransitionError value or an ActionResult code. */
    public static function error(string $code): string
    {
        return match ($code) {
            'INVALID_STATE' => __('This action is not available for the current status of the order.', 'anoofy-woo'),
            'FORBIDDEN', ActionResult::FORBIDDEN => __('You are not allowed to do this.', 'anoofy-woo'),
            'REASON_REQUIRED' => __('A reason is required.', 'anoofy-woo'),
            'CALL_CONFIRMATION_REQUIRED' => __('Log an answered call before confirming the order.', 'anoofy-woo'),
            'STOCK_WARNING_NOT_ACCEPTED' => __('This order has a stock warning. Accept it before confirming.', 'anoofy-woo'),
            'STOCK_WARNING_NEEDS_MANAGER' => __('This order has a stock warning. An order manager must accept it first.', 'anoofy-woo'),
            'ORDER_NOT_ASSIGNED' => __('Assign the order to an employee first.', 'anoofy-woo'),
            'LINES_NOT_PREPARED' => __('Not all items are prepared yet.', 'anoofy-woo'),
            'REPREPARATION_NOT_CONFIRMED' => __('The order was edited after preparation started; confirm the re-preparation first.', 'anoofy-woo'),
            'SHIPMENT_DETAILS_REQUIRED' => __('Enter the courier and tracking number first.', 'anoofy-woo'),
            'MAX_DELIVERY_ATTEMPTS_REACHED' => __('The maximum number of delivery attempts was reached.', 'anoofy-woo'),
            ActionResult::NOT_FOUND => __('Order not found.', 'anoofy-woo'),
            ActionResult::CONCURRENT_CHANGE => __('Someone else changed this order a moment ago. Reload the page and try again.', 'anoofy-woo'),
            ActionResult::INVALID_ASSIGNEE => __('That user cannot be assigned to orders.', 'anoofy-woo'),
            ActionResult::INVALID_INPUT => __('Invalid request.', 'anoofy-woo'),
            ActionResult::EFFECT_FAILED => __('The status changed, but updating WooCommerce failed. Check the order notes.', 'anoofy-woo'),
            default => __('Something went wrong.', 'anoofy-woo'),
        };
    }
}
