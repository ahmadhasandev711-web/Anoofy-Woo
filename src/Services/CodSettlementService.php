<?php
declare(strict_types=1);

namespace Anoofy\Woo\Services;

use Anoofy\Woo\Core\Connection\OrderHandoverItem;
use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Identity\Permissions;
use Anoofy\Woo\Core\Identity\RoleResolver;
use Anoofy\Woo\Core\Mode\ModePolicy;
use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Core\Settlement\SettlementCandidate;
use Anoofy\Woo\Core\Settlement\SettlementCsvParser;
use Anoofy\Woo\Core\Settlement\SettlementMatcher;
use Anoofy\Woo\Core\Settlement\SettlementPreview;
use Anoofy\Woo\Data\AuditLog;
use Anoofy\Woo\Data\OpsRepository;
use Anoofy\Woo\Data\SettlementRepository;

/** Reconciles courier COD statements and marks settled WooCommerce orders as paid in one batch. */
final class CodSettlementService
{
    public function __construct(
        private readonly OpsRepository $ops,
        private readonly SettlementRepository $settlements,
        private readonly AuditLog $audit,
    ) {
    }

    public function previewCsv(string $csvContent): SettlementPreview
    {
        $parsed = SettlementCsvParser::parse($csvContent);
        $trackings = array_map(static fn ($line): string => $line->trackingNumber, $parsed['lines']);
        $opsList = $this->ops->findByTrackingNumbers($trackings);

        $candidates = [];
        foreach ($opsList as $ops) {
            if ($ops->trackingNumber === null || $ops->trackingNumber === '') {
                continue;
            }
            $order = wc_get_order($ops->orderId);
            if (!$order instanceof \WC_Order) {
                continue;
            }
            $candidates[$ops->trackingNumber] = new SettlementCandidate(
                orderId: $ops->orderId,
                trackingNumber: $ops->trackingNumber,
                opsStatus: $ops->status,
                orderTotalCents: OrderHandoverItem::toCents((string) $order->get_total()),
                alreadySettled: $ops->isCodSettled(),
                handedOver: $ops->isHandedOver(),
            );
        }

        return SettlementMatcher::match($parsed['lines'], $candidates, $parsed['errors']);
    }

    /** @return array{count: int, total_cents: int} */
    public function pendingSummary(): array
    {
        $pending = $this->ops->findDeliveredUnsettled();
        $totalCents = 0;
        $count = 0;
        foreach ($pending as $ops) {
            $order = wc_get_order($ops->orderId);
            if (!$order instanceof \WC_Order) {
                continue;
            }
            $count++;
            $totalCents += OrderHandoverItem::toCents((string) $order->get_total());
        }

        return ['count' => $count, 'total_cents' => $totalCents];
    }

    /**
     * @param array<int, int> $amountsByOrderId order_id => collected_cents
     */
    public function settle(int $userId, string $courier, string $reference, array $amountsByOrderId): ActionResult
    {
        $user = get_userdata($userId);
        $role = $user instanceof \WP_User ? RoleResolver::fromWpRoles(array_values(array_map('strval', $user->roles))) : null;
        if ($role === null || !Permissions::can($role, Action::CodSettle)) {
            return ActionResult::fail(ActionResult::FORBIDDEN);
        }

        $courier = mb_substr(trim(sanitize_text_field($courier)), 0, 100);
        $reference = mb_substr(trim(sanitize_text_field($reference)), 0, 100);
        if ($courier === '' || $amountsByOrderId === []) {
            return ActionResult::fail(ActionResult::INVALID_INPUT);
        }

        $batchItems = [];
        $wooOrders = [];

        foreach ($amountsByOrderId as $orderId => $amountCents) {
            $orderId = (int) $orderId;
            $amountCents = (int) $amountCents;
            if ($orderId <= 0 || $amountCents < 0) {
                return ActionResult::fail(ActionResult::INVALID_INPUT);
            }

            $ops = $this->ops->find($orderId);
            if ($ops === null || !ModePolicy::allows($ops->isHandedOver())) {
                return ActionResult::fail(ActionResult::FORBIDDEN);
            }
            if ($ops->status !== OrderStatus::Delivered || $ops->isCodSettled() || $ops->trackingNumber === null) {
                return ActionResult::fail(ActionResult::CONCURRENT_CHANGE);
            }

            $order = wc_get_order($orderId);
            if (!$order instanceof \WC_Order) {
                return ActionResult::fail(ActionResult::NOT_FOUND);
            }

            $batchItems[] = [
                'order_id' => $orderId,
                'tracking_number' => $ops->trackingNumber,
                'amount_cents' => $amountCents,
            ];
            $wooOrders[$orderId] = $order;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        try {
            $settlementId = $this->settlements->createBatch(
                $this->ops,
                $courier,
                $reference !== '' ? $reference : null,
                $batchItems,
                $userId,
                $nowUtc
            );
        } catch (\Throwable $e) {
            return ActionResult::fail(ActionResult::CONCURRENT_CHANGE);
        }

        $paidTimestamp = time();
        foreach ($wooOrders as $orderId => $order) {
            if (!$order->get_date_paid('edit')) {
                $order->set_date_paid($paidTimestamp);
            }
            if ($reference !== '' && $order->get_transaction_id() === '') {
                $order->set_transaction_id($reference);
            }
            $order->add_order_note(sprintf(
                /* translators: 1: courier name, 2: settlement batch ID */
                __('Anoofy: COD settled via %1$s (settlement #%2$d).', 'anoofy-woo'),
                $courier,
                $settlementId
            ));
            $order->save();

            $this->audit->record($orderId, $userId, 'cod.settled', null, null, $reference !== '' ? $reference : null, [
                'settlement_id' => $settlementId,
                'courier' => $courier,
                'amount_cents' => (int) ($amountsByOrderId[$orderId] ?? 0),
            ]);
        }

        return ActionResult::ok();
    }
}
