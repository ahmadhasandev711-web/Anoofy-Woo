<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests\Core;

use Anoofy\Woo\Core\Identity\Role;
use Anoofy\Woo\Core\Order\Effect;
use Anoofy\Woo\Core\Order\OrderContext;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Core\Order\OrderStateMachine as M;
use Anoofy\Woo\Core\Order\OrderStatus as S;
use Anoofy\Woo\Core\Order\TransitionError as E;
use PHPUnit\Framework\TestCase;

final class OrderStateMachineTest extends TestCase
{
    private function ctx(array $o = []): OrderContext
    {
        return new OrderContext(...$o);
    }

    private function ok(S $from, OrderEvent $ev, Role $who, array $ctx = []): S
    {
        $r = M::transition($from, $ev, $who, $this->ctx($ctx));
        self::assertTrue($r->ok, "expected ok, got " . ($r->error?->value ?? '?'));
        self::assertSame($from, $r->from);

        return $r->to;
    }

    private function fails(S $from, OrderEvent $ev, Role $who, E $error, array $ctx = []): void
    {
        $r = M::transition($from, $ev, $who, $this->ctx($ctx));
        self::assertFalse($r->ok);
        self::assertSame($error, $r->error);
        self::assertNull($r->to);
    }

    public function testHappyPathFromNewToDelivered(): void
    {
        $s = S::New;
        $s = $this->ok($s, OrderEvent::LogFirstCall, Role::CustomerService);
        self::assertSame(S::UnderReview, $s);
        $s = $this->ok($s, OrderEvent::Confirm, Role::CustomerService, ['hasLoggedCall' => true]);
        $s = $this->ok($s, OrderEvent::StartPreparing, Role::Warehouse, ['assigned' => true]);
        $s = $this->ok($s, OrderEvent::FinishPreparing, Role::Warehouse, ['allLinesPrepared' => true]);
        $s = $this->ok($s, OrderEvent::Ship, Role::Shipping, ['hasShipmentDetails' => true]);
        $s = $this->ok($s, OrderEvent::MarkDelivered, Role::Shipping);
        self::assertSame(S::Delivered, $s);
    }

    public function testConfirmNeedsALoggedCall(): void
    {
        $this->fails(S::UnderReview, OrderEvent::Confirm, Role::CustomerService, E::CallConfirmationRequired);
    }

    public function testStockWarningBlocksConfirmAndOnlyManagersCanAcceptIt(): void
    {
        $c = ['hasLoggedCall' => true, 'stockWarningPresent' => true];
        $this->fails(S::UnderReview, OrderEvent::Confirm, Role::CustomerService, E::StockWarningNeedsManager, $c);
        $this->fails(S::UnderReview, OrderEvent::Confirm, Role::OrderManager, E::StockWarningNotAccepted, $c);
        $this->ok(S::UnderReview, OrderEvent::Confirm, Role::OrderManager, $c + ['stockWarningAccepted' => true]);
    }

    public function testRejectAndCancelNeedAReasonAndCancelTheWooOrder(): void
    {
        $this->fails(S::UnderReview, OrderEvent::Reject, Role::CustomerService, E::ReasonRequired);
        $this->fails(S::UnderReview, OrderEvent::Reject, Role::CustomerService, E::ReasonRequired, ['reason' => '   ']);
        $r = M::transition(S::UnderReview, OrderEvent::Reject, Role::CustomerService, $this->ctx(['reason' => 'customer refused']));
        self::assertTrue($r->ok);
        self::assertSame(S::Rejected, $r->to);
        self::assertSame([Effect::CancelWooOrder], $r->effects);

        foreach ([S::New, S::UnderReview, S::Confirmed] as $from) {
            $r = M::transition($from, OrderEvent::CancelDirect, Role::OrderManager, $this->ctx(['reason' => 'x']));
            self::assertTrue($r->ok, $from->value);
            self::assertSame(S::Cancelled, $r->to);
            self::assertSame([Effect::CancelWooOrder], $r->effects);
        }
        $this->fails(S::Preparing, OrderEvent::CancelDirect, Role::OrderManager, E::InvalidState, ['reason' => 'x']);
    }

    public function testCustomerServiceCannotCancelDirectly(): void
    {
        $this->fails(S::Confirmed, OrderEvent::CancelDirect, Role::CustomerService, E::Forbidden, ['reason' => 'x']);
    }

    public function testCancellingWhilePreparingGoesThroughWarehouseRestockConfirmation(): void
    {
        foreach ([S::Preparing, S::ReadyToShip] as $from) {
            $r = M::transition($from, OrderEvent::RequestCancel, Role::OrderManager, $this->ctx(['reason' => 'customer called']));
            self::assertTrue($r->ok, $from->value);
            self::assertSame(S::CancellationPending, $r->to);
            self::assertSame([], $r->effects, 'the Woo order must not be cancelled before the goods are back');
        }
        $r = M::transition(S::CancellationPending, OrderEvent::ConfirmRestocked, Role::Warehouse, $this->ctx());
        self::assertTrue($r->ok);
        self::assertSame(S::Cancelled, $r->to);
        self::assertSame([Effect::CancelWooOrder], $r->effects);
    }

    public function testPreparingNeedsAssignmentAndAllLinesAndNoUnconfirmedEdits(): void
    {
        $this->fails(S::Confirmed, OrderEvent::StartPreparing, Role::Warehouse, E::OrderNotAssigned);
        $this->fails(S::Preparing, OrderEvent::FinishPreparing, Role::Warehouse, E::LinesNotPrepared);
        $this->fails(
            S::Preparing,
            OrderEvent::FinishPreparing,
            Role::Warehouse,
            E::RepreparationNotConfirmed,
            ['allLinesPrepared' => true, 'editedAfterPreparingUnconfirmed' => true],
        );
    }

    public function testShippingNeedsCourierAndTracking(): void
    {
        $this->fails(S::ReadyToShip, OrderEvent::Ship, Role::Shipping, E::ShipmentDetailsRequired);
        $this->fails(S::ReadyToShip, OrderEvent::Ship, Role::Warehouse, E::Forbidden, ['hasShipmentDetails' => true]);
    }

    public function testDeliveryFailureRetryLimitAndReturnToOrigin(): void
    {
        $this->fails(S::Shipped, OrderEvent::MarkDeliveryFailed, Role::Shipping, E::ReasonRequired);
        $s = $this->ok(S::Shipped, OrderEvent::MarkDeliveryFailed, Role::Shipping, ['reason' => 'no answer']);
        self::assertSame(S::DeliveryFailed, $s);

        $this->ok(S::DeliveryFailed, OrderEvent::RetryDelivery, Role::Shipping, ['deliveryAttempts' => 2, 'maxDeliveryAttempts' => 3]);
        $this->fails(
            S::DeliveryFailed,
            OrderEvent::RetryDelivery,
            Role::Shipping,
            E::MaxDeliveryAttemptsReached,
            ['deliveryAttempts' => 3, 'maxDeliveryAttempts' => 3],
        );

        $r = M::transition(S::DeliveryFailed, OrderEvent::ReceiveReturned, Role::Warehouse, $this->ctx());
        self::assertTrue($r->ok);
        self::assertSame(S::ReturnedToOrigin, $r->to);
        self::assertSame([Effect::OpenRtoReturn, Effect::AddRtoTag], $r->effects);
    }

    public function testInvalidTransitionsAreRejectedForEveryStatusEventPair(): void
    {
        $allowedPairs = 0;
        foreach (S::cases() as $status) {
            $offered = M::eventsFrom($status);
            foreach (OrderEvent::cases() as $event) {
                if (in_array($event, $offered, true)) {
                    $allowedPairs++;
                    continue;
                }
                $r = M::transition($status, $event, Role::Owner, $this->ctx(['reason' => 'x', 'hasLoggedCall' => true, 'assigned' => true, 'allLinesPrepared' => true, 'hasShipmentDetails' => true]));
                self::assertFalse($r->ok, "$status->value + $event->value must be invalid");
                self::assertSame(E::InvalidState, $r->error);
            }
        }
        // 13 events; cancel-direct is allowed from 3 states (+2) and request-cancel from 2 (+1).
        self::assertSame(count(OrderEvent::cases()) + 2 + 1, $allowedPairs);
    }

    public function testTerminalStatesOfferNoEvents(): void
    {
        foreach ([S::Rejected, S::Cancelled, S::Delivered, S::ReturnedToOrigin] as $status) {
            self::assertSame([], M::eventsFrom($status), $status->value);
        }
    }

    public function testEveryEventMapsToAPermission(): void
    {
        foreach (OrderEvent::cases() as $event) {
            self::assertNotNull(M::actionFor($event), $event->value);
        }
    }

    public function testExternalWooCancellation(): void
    {
        foreach ([S::New, S::UnderReview, S::Confirmed] as $s) {
            $o = M::onWooCancelled($s);
            self::assertSame(S::Cancelled, $o->to, $s->value);
            self::assertFalse($o->createRestockTask);
            self::assertFalse($o->needsDecision);
        }
        foreach ([S::Preparing, S::ReadyToShip, S::CancellationPending] as $s) {
            $o = M::onWooCancelled($s);
            self::assertSame(S::Cancelled, $o->to, $s->value);
            self::assertTrue($o->createRestockTask, 'warehouse must put the goods back');
        }
        foreach ([S::Cancelled, S::Rejected] as $s) {
            $o = M::onWooCancelled($s);
            self::assertNull($o->to);
            self::assertFalse($o->needsDecision);
        }
        foreach ([S::Shipped, S::Delivered, S::DeliveryFailed, S::ReturnedToOrigin] as $s) {
            $o = M::onWooCancelled($s);
            self::assertNull($o->to, $s->value);
            self::assertTrue($o->needsDecision, $s->value);
        }
    }
}
