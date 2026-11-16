<?php
declare(strict_types=1);

namespace Anoofy\Woo\Data;

use Anoofy\Woo\Core\Order\CallOutcome;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Core\Reports\AgentKpiRow;
use Anoofy\Woo\Core\Returns\ReturnStatus;
use Anoofy\Woo\Database\Schema;

/** Read-only aggregate SQL queries for the KPI Reports dashboard. */
final class ReportRepository
{
    /** @return array<string, int> ops_status => count */
    public function statusCounts(?string $sinceUtc = null): array
    {
        global $wpdb;
        $table = Schema::table($wpdb->prefix, 'order_ops');
        if ($sinceUtc !== null) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ops_status, COUNT(*) AS cnt FROM {$table} WHERE created_at >= %s GROUP BY ops_status",
                $sinceUtc,
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results(
                "SELECT ops_status, COUNT(*) AS cnt FROM {$table} GROUP BY ops_status",
                ARRAY_A,
            );
        }

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['ops_status']] = (int) $row['cnt'];
        }

        return $out;
    }

    /** @return array{count: int, total_cents: int} */
    public function codSettledSummary(?string $sinceUtc = null): array
    {
        global $wpdb;
        $table = Schema::table($wpdb->prefix, 'cod_settlement_orders');
        if ($sinceUtc !== null) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_cents), 0) AS total_cents FROM {$table} WHERE created_at >= %s",
                $sinceUtc,
            ), ARRAY_A);
        } else {
            $row = $wpdb->get_row(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_cents), 0) AS total_cents FROM {$table}",
                ARRAY_A,
            );
        }

        return [
            'count' => is_array($row) ? (int) $row['cnt'] : 0,
            'total_cents' => is_array($row) ? (int) $row['total_cents'] : 0,
        ];
    }

    /** @return list<AgentKpiRow> */
    public function agentStats(?string $sinceUtc = null): array
    {
        global $wpdb;
        $opsTable = Schema::table($wpdb->prefix, 'order_ops');
        $callsTable = Schema::table($wpdb->prefix, 'call_attempts');
        $returnsTable = Schema::table($wpdb->prefix, 'returns');

        $byUser = [];
        $ensure = static function (int $uid) use (&$byUser): void {
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = [
                    'assigned' => 0,
                    'delivered' => 0,
                    'returned' => 0,
                    'calls' => 0,
                    'answered' => 0,
                    'returns_approved' => 0,
                ];
            }
        };

        $opsSql = $sinceUtc !== null
            ? $wpdb->prepare(
                "SELECT assigned_to,
                        COUNT(*) AS assigned_cnt,
                        SUM(CASE WHEN ops_status = %s THEN 1 ELSE 0 END) AS delivered_cnt,
                        SUM(CASE WHEN ops_status = %s THEN 1 ELSE 0 END) AS returned_cnt
                 FROM {$opsTable}
                 WHERE assigned_to IS NOT NULL AND created_at >= %s
                 GROUP BY assigned_to",
                OrderStatus::Delivered->value,
                OrderStatus::ReturnedToOrigin->value,
                $sinceUtc,
            )
            : $wpdb->prepare(
                "SELECT assigned_to,
                        COUNT(*) AS assigned_cnt,
                        SUM(CASE WHEN ops_status = %s THEN 1 ELSE 0 END) AS delivered_cnt,
                        SUM(CASE WHEN ops_status = %s THEN 1 ELSE 0 END) AS returned_cnt
                 FROM {$opsTable}
                 WHERE assigned_to IS NOT NULL
                 GROUP BY assigned_to",
                OrderStatus::Delivered->value,
                OrderStatus::ReturnedToOrigin->value,
            );

        foreach ((array) $wpdb->get_results($opsSql, ARRAY_A) as $row) {
            $uid = (int) $row['assigned_to'];
            if ($uid <= 0) {
                continue;
            }
            $ensure($uid);
            $byUser[$uid]['assigned'] = (int) $row['assigned_cnt'];
            $byUser[$uid]['delivered'] = (int) $row['delivered_cnt'];
            $byUser[$uid]['returned'] = (int) $row['returned_cnt'];
        }

        $callsSql = $sinceUtc !== null
            ? $wpdb->prepare(
                "SELECT user_id, COUNT(*) AS total_calls, SUM(CASE WHEN outcome = %s THEN 1 ELSE 0 END) AS answered_cnt
                 FROM {$callsTable}
                 WHERE created_at >= %s
                 GROUP BY user_id",
                CallOutcome::Answered->value,
                $sinceUtc,
            )
            : $wpdb->prepare(
                "SELECT user_id, COUNT(*) AS total_calls, SUM(CASE WHEN outcome = %s THEN 1 ELSE 0 END) AS answered_cnt
                 FROM {$callsTable}
                 GROUP BY user_id",
                CallOutcome::Answered->value,
            );

        foreach ((array) $wpdb->get_results($callsSql, ARRAY_A) as $row) {
            $uid = (int) $row['user_id'];
            if ($uid <= 0) {
                continue;
            }
            $ensure($uid);
            $byUser[$uid]['calls'] = (int) $row['total_calls'];
            $byUser[$uid]['answered'] = (int) $row['answered_cnt'];
        }

        $returnsSql = $sinceUtc !== null
            ? $wpdb->prepare(
                "SELECT approved_by, COUNT(*) AS approved_cnt
                 FROM {$returnsTable}
                 WHERE status = %s AND approved_by IS NOT NULL AND approved_at >= %s
                 GROUP BY approved_by",
                ReturnStatus::Approved->value,
                $sinceUtc,
            )
            : $wpdb->prepare(
                "SELECT approved_by, COUNT(*) AS approved_cnt
                 FROM {$returnsTable}
                 WHERE status = %s AND approved_by IS NOT NULL
                 GROUP BY approved_by",
                ReturnStatus::Approved->value,
            );

        foreach ((array) $wpdb->get_results($returnsSql, ARRAY_A) as $row) {
            $uid = (int) $row['approved_by'];
            if ($uid <= 0) {
                continue;
            }
            $ensure($uid);
            $byUser[$uid]['returns_approved'] = (int) $row['approved_cnt'];
        }

        $rows = [];
        foreach ($byUser as $uid => $s) {
            $rows[] = new AgentKpiRow(
                userId: $uid,
                assignedOrders: $s['assigned'],
                deliveredOrders: $s['delivered'],
                returnedOrders: $s['returned'],
                callsLogged: $s['calls'],
                answeredCalls: $s['answered'],
                returnsApproved: $s['returns_approved'],
            );
        }

        usort($rows, static fn (AgentKpiRow $a, AgentKpiRow $b): int =>
            ($b->assignedOrders + $b->callsLogged) <=> ($a->assignedOrders + $a->callsLogged)
        );

        return $rows;
    }
}
