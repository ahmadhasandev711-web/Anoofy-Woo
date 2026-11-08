<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests\Core;

use Anoofy\Woo\Core\Identity\Role;
use Anoofy\Woo\Core\Identity\RoleResolver;
use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderEvent;
use Anoofy\Woo\Core\Order\OrderOps;
use Anoofy\Woo\Core\Order\OrderStateMachine;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Core\Order\StatusTabs;
use Anoofy\Woo\Core\Order\TransitionError;
use Anoofy\Woo\Core\Order\WooStatusMap;
use PHPUnit\Framework\TestCase;

final class PhaseOneCoreTest extends TestCase
{
    public function testOnlyAnAnsweredCallCountsAsConfirmationCall(): void
    {
        self::assertTrue(CallOutcome::Answered->countsAsConfirmationCall());
        foreach ([CallOutcome::NoAnswer, CallOutcome::Busy, CallOutcome::WrongNumber, CallOutcome::CallLater] as $o) {
            self::assertFalse($o->countsAsConfirmationCall(), $o->value);
        }
    }

    public function testOutcomeValuesFitTheVarchar20Column(): void
    {
        foreach (CallOutcome::cases() as $o) {
            self::assertTrue(strlen($o->value) <= 20, $o->value);
        }
    }

    public function testEveryStatusBelongsToExactlyOneTab(): void
    {
        $seen = [];
        foreach (StatusTabs::groups() as $statuses) {
            foreach ($statuses as $s) {
                $seen[$s->value] = ($seen[$s->value] ?? 0) + 1;
            }
        }
        foreach (OrderStatus::cases() as $s) {
            self::assertSame(1, $seen[$s->value] ?? 0, $s->value);
        }
    }

    public function testTabKeysStartWithAllAndRejectUnknownKeys(): void
    {
        self::assertSame('all', StatusTabs::keys()[0]);
        self::assertTrue(StatusTabs::isValid('under_review'));
        self::assertFalse(StatusTabs::isValid('nope'));
        self::assertNull(StatusTabs::statusesFor('all'));
        self::assertNull(StatusTabs::statusesFor('nope'));
    }

    public function testWooStatusMapMatchesSpecSection3(): void
    {
        self::assertSame('on-hold', WooStatusMap::forOps(OrderStatus::New));
        self::assertSame('on-hold', WooStatusMap::forOps(OrderStatus::UnderReview));
        self::assertSame('processing', WooStatusMap::forOps(OrderStatus::Confirmed));
        self::assertSame('processing', WooStatusMap::forOps(OrderStatus::Shipped));
        self::assertSame('completed', WooStatusMap::forOps(OrderStatus::Delivered));
        self::assertSame('cancelled', WooStatusMap::forOps(OrderStatus::Rejected));
        self::assertSame('cancelled', WooStatusMap::forOps(OrderStatus::Cancelled));
        self::assertNull(WooStatusMap::forOps(OrderStatus::ReturnedToOrigin));
    }

    public function testRoleResolverPrefersOwnerThenTheStrongestPluginRole(): void
    {
        self::assertSame(Role::Owner, RoleResolver::fromWpRoles(['administrator']));
        self::assertSame(Role::Owner, RoleResolver::fromWpRoles(['shop_manager', 'anoofy_warehouse']));
        self::assertSame(Role::OrderManager, RoleResolver::fromWpRoles(['anoofy_warehouse', 'anoofy_order_manager']));
        self::assertSame(Role::Warehouse, RoleResolver::fromWpRoles(['anoofy_warehouse']));
        self::assertNull(RoleResolver::fromWpRoles(['subscriber', 'customer']));
        self::assertNull(RoleResolver::fromWpRoles([]));
    }

    public function testOrderOpsFromWpdbStringRow(): void
    {
        $ops = OrderOps::fromRow([
            'order_id' => '42', 'ops_status' => 'UNDER_REVIEW', 'assigned_to' => '7', 'has_logged_call' => '1',
            'stock_warning' => '0', 'stock_warning_accepted' => '0', 'edited_after_preparing' => '0',
            'delivery_attempts' => '2', 'courier' => ' Bosta ', 'tracking_number' => '',
        ]);
        self::assertSame(42, $ops->orderId);
        self::assertSame(OrderStatus::UnderReview, $ops->status);
        self::assertSame(7, $ops->assignedTo);
        self::assertTrue($ops->hasLoggedCall);
        self::assertSame('Bosta', $ops->courier);
        self::assertNull($ops->trackingNumber);
        self::assertSame(2, $ops->deliveryAttempts);
    }

    public function testNullOrZeroAssigneeMeansUnassigned(): void
    {
        self::assertNull(OrderOps::fromRow(['order_id' => '1', 'ops_status' => 'NEW', 'assigned_to' => null])->assignedTo);
        self::assertNull(OrderOps::fromRow(['order_id' => '1', 'ops_status' => 'NEW', 'assigned_to' => '0'])->assignedTo);
    }

    public function testContextCarriesTheRowFactsAndReason(): void
    {
        $ops = OrderOps::fromRow(['order_id' => '1', 'ops_status' => 'CONFIRMED', 'assigned_to' => '3',
            'has_logged_call' => '1', 'courier' => 'Bosta', 'tracking_number' => 'T1']);
        $ctx = $ops->toContext('customer changed mind');
        self::assertTrue($ctx->assigned);
        self::assertTrue($ctx->hasLoggedCall);
        self::assertTrue($ctx->hasShipmentDetails);
        self::assertTrue($ctx->hasReason());
        self::assertFalse($ops->toContext()->hasReason());
    }

    public function testFullConfirmationFlowWithRowBackedContext(): void
    {
        $row = ['order_id' => '9', 'ops_status' => 'NEW'];
        $ops = OrderOps::fromRow($row);

        // 1) first call of any outcome moves NEW -> UNDER_REVIEW
        $r = OrderStateMachine::transition($ops->status, OrderEvent::LogFirstCall, Role::CustomerService, $ops->toContext());
        self::assertTrue($r->ok);
        self::assertSame(OrderStatus::UnderReview, $r->to);

        // 2) confirming without an answered call is refused
        $ops = OrderOps::fromRow(['order_id' => '9', 'ops_status' => 'UNDER_REVIEW']);
        $r = OrderStateMachine::transition($ops->status, OrderEvent::Confirm, Role::CustomerService, $ops->toContext());
        self::assertFalse($r->ok);
        self::assertSame(TransitionError::CallConfirmationRequired, $r->error);

        // 3) after an answered call it works
        $ops = OrderOps::fromRow(['order_id' => '9', 'ops_status' => 'UNDER_REVIEW', 'has_logged_call' => '1']);
        $r = OrderStateMachine::transition($ops->status, OrderEvent::Confirm, Role::CustomerService, $ops->toContext());
        self::assertTrue($r->ok);
        self::assertSame(OrderStatus::Confirmed, $r->to);
    }
}
