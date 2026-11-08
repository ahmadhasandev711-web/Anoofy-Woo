<?php
declare(strict_types=1);

namespace Anoofy\Woo\Services;

use Anoofy\Woo\Data\AuditLog;
use Anoofy\Woo\Data\CallLog;
use Anoofy\Woo\Data\OpsRepository;

/** Tiny service locator (no container library on purpose). */
final class Services
{
    private static ?OrderOpsService $orderOps = null;
    private static ?OpsRepository $repository = null;
    private static ?CallLog $callLog = null;
    private static ?AuditLog $auditLog = null;

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

    public static function orderOps(): OrderOpsService
    {
        return self::$orderOps ??= new OrderOpsService(self::repository(), self::callLog(), self::auditLog());
    }
}
