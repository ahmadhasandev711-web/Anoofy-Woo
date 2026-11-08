<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Identity;

/** One action = one WordPress capability = one row in the permission matrix. */
enum Action: string
{
    case PluginSettings = 'plugin.settings';
    case StaffManage = 'staff.manage';
    case OrderView = 'order.view';
    case OrderViewCustomerContact = 'order.viewCustomerContact';
    case OrderLogCall = 'order.logCall';
    case OrderConfirmOrReject = 'order.confirmOrReject';
    case OrderAcceptStockWarning = 'order.acceptStockWarning';
    case OrderEdit = 'order.edit';
    case OrderAssign = 'order.assign';
    case OrderCancel = 'order.cancel';
    case OrderConfirmRestock = 'order.confirmRestock';
    case OrderPrepare = 'order.prepare';
    case OrderConfirmRepreparation = 'order.confirmRepreparation';
    case ShipmentCreate = 'shipment.create';
    case DeliveryRecord = 'delivery.record';
    case DeliveryRetry = 'delivery.retry';
    case CodSettle = 'cod.settle';
    case InventoryView = 'inventory.view';
    case InventoryAdjust = 'inventory.adjust';
    case InventoryCount = 'inventory.count';
    case ReturnOpen = 'return.open';
    case ReturnReceiveAndInspect = 'return.receiveAndInspect';
    case ReturnApprove = 'return.approve';
    case ReportView = 'report.view';
    case AuditView = 'audit.view';

    /** The WordPress capability name, e.g. `order.logCall` -> `anoofy_order_log_call`. */
    public function capability(): string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', str_replace('.', '_', $this->value)));

        return 'anoofy_' . $snake;
    }
}
