<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Identity;

/** Permissions are code, not database rows (D-07). One action = one unit test. */
final class Permissions
{
    /** @return array<string, list<Role>> keyed by Action value */
    public static function matrix(): array
    {
        $o = Role::Owner;
        $om = Role::OrderManager;
        $wh = Role::Warehouse;
        $sh = Role::Shipping;
        $cs = Role::CustomerService;

        return [
            Action::PluginSettings->value => [$o],
            Action::StaffManage->value => [$o],
            Action::OrderView->value => [$o, $om, $wh, $sh, $cs],
            Action::OrderViewCustomerContact->value => [$o, $om, $sh, $cs],
            Action::OrderLogCall->value => [$o, $om, $cs],
            Action::OrderConfirmOrReject->value => [$o, $om, $cs],
            Action::OrderAcceptStockWarning->value => [$o, $om],
            Action::OrderEdit->value => [$o, $om, $cs],
            Action::OrderAssign->value => [$o, $om],
            Action::OrderCancel->value => [$o, $om],
            Action::OrderConfirmRestock->value => [$o, $om, $wh],
            Action::OrderPrepare->value => [$o, $om, $wh],
            Action::OrderConfirmRepreparation->value => [$o, $om, $wh],
            Action::ShipmentCreate->value => [$o, $om, $sh],
            Action::DeliveryRecord->value => [$o, $om, $sh],
            Action::DeliveryRetry->value => [$o, $om, $sh],
            Action::CodSettle->value => [$o, $om],
            Action::InventoryView->value => [$o, $om, $wh, $sh, $cs],
            Action::InventoryAdjust->value => [$o, $om, $wh],
            Action::InventoryCount->value => [$o, $om, $wh],
            Action::ReturnOpen->value => [$o, $om, $cs],
            Action::ReturnReceiveAndInspect->value => [$o, $wh],
            Action::ReturnApprove->value => [$o, $om],
            Action::ReportView->value => [$o, $om],
            Action::AuditView->value => [$o],
        ];
    }

    public static function can(Role $role, Action $action): bool
    {
        return in_array($role, self::matrix()[$action->value], true);
    }

    /** @return list<Action> */
    public static function actionsFor(Role $role): array
    {
        return array_values(array_filter(Action::cases(), static fn (Action $a): bool => self::can($role, $a)));
    }
}
