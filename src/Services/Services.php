<?php
declare(strict_types=1);

namespace Anoofy\Woo\Services;

use Anoofy\Woo\Core\Mode\OperatingMode;
use Anoofy\Woo\Data\AuditLog;
use Anoofy\Woo\Data\CallLog;
use Anoofy\Woo\Data\DeliveryAttemptLog;
use Anoofy\Woo\Data\InboxRepository;
use Anoofy\Woo\Data\OpsRepository;
use Anoofy\Woo\Data\OutboxRepository;
use Anoofy\Woo\Data\PreparedItemsRepository;
use Anoofy\Woo\Data\SettlementRepository;

/** Tiny service locator (no container library on purpose). */
final class Services
{
    private static ?OrderOpsService $orderOps = null;
    private static ?OpsRepository $repository = null;
    private static ?CallLog $callLog = null;
    private static ?AuditLog $auditLog = null;
    private static ?EngazConnectionService $engazConnection = null;
    private static ?EngazInboundService $engazInbound = null;
    private static ?OutboxRepository $outbox = null;
    private static ?InboxRepository $inbox = null;
    private static ?PreparedItemsRepository $preparedItems = null;
    private static ?DeliveryAttemptLog $deliveryLog = null;
    private static ?SettlementRepository $settlements = null;
    private static ?CodSettlementService $codSettlement = null;

    public static function engazInbound(): EngazInboundService
    {
        return self::$engazInbound ??= new EngazInboundService(
            self::engazConnection(),
            self::repository(),
            self::inbox()
        );
    }

    public static function inbox(): InboxRepository
    {
        return self::$inbox ??= new InboxRepository();
    }

    public static function outbox(): OutboxRepository
    {
        return self::$outbox ??= new OutboxRepository();
    }

    public static function engazConnection(): EngazConnectionService
    {
        return self::$engazConnection ??= new EngazConnectionService();
    }

    public static function mode(): OperatingMode
    {
        return self::engazConnection()->isConnected()
            ? OperatingMode::Connected
            : OperatingMode::Standalone;
    }

    public static function repository(): OpsRepository
    {
        return self::$repository ??= new OpsRepository();
    }

    public static function callLog(): CallLog
    {
        return self::$callLog ??= new CallLog();
    }

    public static function auditLog(): AuditLog
    {
        return self::$auditLog ??= new AuditLog();
    }

    public static function preparedItems(): PreparedItemsRepository
    {
        return self::$preparedItems ??= new PreparedItemsRepository();
    }

    public static function deliveryLog(): DeliveryAttemptLog
    {
        return self::$deliveryLog ??= new DeliveryAttemptLog();
    }

    public static function settlements(): SettlementRepository
    {
        return self::$settlements ??= new SettlementRepository();
    }

    public static function codSettlement(): CodSettlementService
    {
        return self::$codSettlement ??= new CodSettlementService(
            self::repository(),
            self::settlements(),
            self::auditLog()
        );
    }

    public static function orderOps(): OrderOpsService
    {
        return self::$orderOps ??= new OrderOpsService(
            self::repository(),
            self::callLog(),
            self::auditLog(),
            self::preparedItems(),
            self::deliveryLog()
        );
    }
}
