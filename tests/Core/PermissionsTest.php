<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests\Core;

use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Identity\Permissions;
use Anoofy\Woo\Core\Identity\Role;
use PHPUnit\Framework\TestCase;

final class PermissionsTest extends TestCase
{
    public function testEveryActionHasARowInTheMatrixAndAtLeastOneRole(): void
    {
        $matrix = Permissions::matrix();
        foreach (Action::cases() as $action) {
            self::assertArrayHasKey($action->value, $matrix, $action->value);
            self::assertNotEmpty($matrix[$action->value], $action->value);
        }
        self::assertCount(count(Action::cases()), $matrix);
    }

    public function testOwnerCanDoEverything(): void
    {
        foreach (Action::cases() as $action) {
            self::assertTrue(Permissions::can(Role::Owner, $action), $action->value);
        }
    }

    public function testOnlyOwnerCanManageIntegration(): void
    {
        self::assertTrue(Permissions::can(Role::Owner, Action::IntegrationManage));
        foreach (Role::cases() as $role) {
            if ($role === Role::Owner) {
                continue;
            }
            self::assertFalse(Permissions::can($role, Action::IntegrationManage), $role->value);
        }
    }

    public function testCustomerServiceCanCallConfirmAndEditButNotAssignCancelOrAcceptStockWarning(): void
    {
        self::assertTrue(Permissions::can(Role::CustomerService, Action::OrderLogCall));
        self::assertTrue(Permissions::can(Role::CustomerService, Action::OrderConfirmOrReject));
        self::assertTrue(Permissions::can(Role::CustomerService, Action::OrderEdit));
        self::assertFalse(Permissions::can(Role::CustomerService, Action::OrderAssign));
        self::assertFalse(Permissions::can(Role::CustomerService, Action::OrderCancel));
        self::assertFalse(Permissions::can(Role::CustomerService, Action::OrderAcceptStockWarning));
    }

    public function testWarehouseCannotSeeCustomerContactOrConfirm(): void
    {
        self::assertFalse(Permissions::can(Role::Warehouse, Action::OrderViewCustomerContact));
        self::assertFalse(Permissions::can(Role::Warehouse, Action::OrderConfirmOrReject));
        self::assertTrue(Permissions::can(Role::Warehouse, Action::OrderPrepare));
        self::assertTrue(Permissions::can(Role::Warehouse, Action::OrderConfirmRestock));
    }

    public function testOnlyOwnerAndWarehouseReceiveReturnedGoods(): void
    {
        foreach (Role::cases() as $role) {
            $expected = in_array($role, [Role::Owner, Role::Warehouse], true);
            self::assertSame($expected, Permissions::can($role, Action::ReturnReceiveAndInspect), $role->value);
        }
    }

    public function testShippingHandlesShipmentAndDeliveryOnly(): void
    {
        self::assertTrue(Permissions::can(Role::Shipping, Action::ShipmentCreate));
        self::assertTrue(Permissions::can(Role::Shipping, Action::DeliveryRecord));
        self::assertTrue(Permissions::can(Role::Shipping, Action::DeliveryRetry));
        self::assertFalse(Permissions::can(Role::Shipping, Action::OrderPrepare));
        self::assertFalse(Permissions::can(Role::Shipping, Action::CodSettle));
    }

    public function testCodSettlementAndReportsAreManagerLevel(): void
    {
        foreach ([Action::CodSettle, Action::ReportView] as $action) {
            foreach (Role::cases() as $role) {
                $expected = in_array($role, [Role::Owner, Role::OrderManager], true);
                self::assertSame($expected, Permissions::can($role, $action), $role->value . ' ' . $action->value);
            }
        }
    }

    public function testCapabilityNamesAreStableSnakeCase(): void
    {
        self::assertSame('anoofy_order_log_call', Action::OrderLogCall->capability());
        self::assertSame('anoofy_order_view_customer_contact', Action::OrderViewCustomerContact->capability());
        self::assertSame('anoofy_cod_settle', Action::CodSettle->capability());
        self::assertSame('anoofy_return_receive_and_inspect', Action::ReturnReceiveAndInspect->capability());
        $caps = array_map(static fn (Action $a): string => $a->capability(), Action::cases());
        self::assertSame($caps, array_values(array_unique($caps)), 'capabilities must be unique');
    }

    public function testActionsForRoleMatchesCan(): void
    {
        foreach (Role::cases() as $role) {
            foreach (Action::cases() as $action) {
                self::assertSame(
                    Permissions::can($role, $action),
                    in_array($action, Permissions::actionsFor($role), true),
                );
            }
        }
    }
}
