<?php
declare(strict_types=1);

namespace Anoofy\Woo\Services;

use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Core\Identity\Permissions;
use Anoofy\Woo\Core\Identity\RoleResolver;
use Anoofy\Woo\Core\Mode\ModePolicy;
use Anoofy\Woo\Core\Returns\ReturnInspectionLine;
use Anoofy\Woo\Core\Returns\ReturnOpenLine;
use Anoofy\Woo\Core\Returns\ReturnSource;
use Anoofy\Woo\Core\Returns\ReturnStatus;
use Anoofy\Woo\Core\Returns\ReturnValidator;
use Anoofy\Woo\Data\AuditLog;
use Anoofy\Woo\Data\OpsRepository;
use Anoofy\Woo\Data\ReturnRepository;
use Anoofy\Woo\Data\StockMovementRepository;

/**
 * Coordinates RTO return opening, post-delivery customer returns, warehouse inspection,
 * and idempotent per-item stock restock approval via `wc_update_product_stock`.
 */
final class ReturnService
{
    public function __construct(
        private readonly OpsRepository $ops,
        private readonly ReturnRepository $returns,
        private readonly StockMovementRepository $stockMovements,
        private readonly AuditLog $audit,
    ) {
    }

    /** Triggered automatically by `Effect::OpenRtoReturn` on `ReceiveReturned`. Idempotent per order. */
    public function openRtoReturn(\WC_Order $order, int $userId, ?string $reason = null): ?int
    {
        $orderId = $order->get_id();
        $existing = $this->returns->findRtoForOrder($orderId);
        if ($existing !== null) {
            return $existing;
        }

        $items = [];
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $qty = max(0, (int) $item->get_quantity());
            if ($qty <= 0) {
                continue;
            }
            $product = $item->get_product();
            $items[] = [
                'order_item_id' => $item->get_id(),
                'product_id' => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'sku' => ($product instanceof \WC_Product && $product->get_sku() !== '') ? $product->get_sku() : null,
                'name' => $item->get_name(),
                'qty_returned' => $qty,
            ];
        }

        if ($items === []) {
            return null;
        }

        $cleanReason = trim((string) ($reason ?? ''));
        if ($cleanReason === '') {
            $cleanReason = __('Returned to origin (RTO)', 'anoofy-woo');
        }

        $returnId = $this->returns->createReturn(
            $orderId,
            ReturnSource::Rto,
            mb_substr($cleanReason, 0, 1000),
            $userId,
            $items,
            gmdate('Y-m-d H:i:s'),
        );

        $this->audit->record($orderId, $userId, 'return.opened', null, null, $cleanReason, [
            'return_id' => $returnId,
            'source' => ReturnSource::Rto->value,
        ]);

        return $returnId;
    }

    /** Triggered by `Effect::AddRtoTag` on `ReceiveReturned`. */
    public function addRtoTag(\WC_Order $order): void
    {
        if ($order->get_meta('_anoofy_rto', true) !== 'yes') {
            $order->update_meta_data('_anoofy_rto', 'yes');
            $order->add_order_note(__('Anoofy: order marked as Returned to Origin (RTO); awaiting warehouse inspection.', 'anoofy-woo'));
            $order->save();
        }
    }

    /**
     * Opens a post-delivery customer return on a `DELIVERED` order.
     *
     * @param array<int, int> $qtyByOrderItemId order_item_id => qty_to_return
     */
    public function openCustomerReturn(int $orderId, int $userId, array $qtyByOrderItemId, string $reason): ActionResult
    {
        if (!$this->canUser($userId, Action::ReturnOpen)) {
            return ActionResult::fail(ActionResult::FORBIDDEN);
        }

        $ops = $this->ops->find($orderId);
        if ($ops === null) {
            return ActionResult::fail(ActionResult::NOT_FOUND);
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return ActionResult::fail(ActionResult::NOT_FOUND);
        }

        $alreadyReturned = $this->returns->alreadyReturnedQuantities($orderId);
        $orderItemsById = [];
        $openLines = [];
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $itemId = $item->get_id();
            $orderItemsById[$itemId] = $item;
            $openLines[] = new ReturnOpenLine(
                orderItemId: $itemId,
                orderedQty: max(0, (int) $item->get_quantity()),
                alreadyReturnedQty: (int) ($alreadyReturned[$itemId] ?? 0),
                requestedQty: (int) ($qtyByOrderItemId[$itemId] ?? 0),
            );
        }

        foreach (array_keys($qtyByOrderItemId) as $submittedItemId) {
            if (!isset($orderItemsById[(int) $submittedItemId])) {
                return ActionResult::fail(ActionResult::INVALID_INPUT);
            }
        }

        $cleanReason = mb_substr(trim(sanitize_textarea_field($reason)), 0, 1000);
        $validation = ReturnValidator::validateCustomerOpen($ops->status, $ops->isHandedOver(), $openLines, $cleanReason);
        if (!$validation->ok) {
            return ActionResult::fail($validation->errorCode ?? ActionResult::INVALID_INPUT);
        }

        $items = [];
        foreach ($orderItemsById as $itemId => $item) {
            $reqQty = (int) ($qtyByOrderItemId[$itemId] ?? 0);
            if ($reqQty <= 0) {
                continue;
            }
            $product = $item->get_product();
            $items[] = [
                'order_item_id' => $itemId,
                'product_id' => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'sku' => ($product instanceof \WC_Product && $product->get_sku() !== '') ? $product->get_sku() : null,
                'name' => $item->get_name(),
                'qty_returned' => $reqQty,
            ];
        }

        $returnId = $this->returns->createReturn(
            $orderId,
            ReturnSource::Customer,
            $cleanReason,
            $userId,
            $items,
            gmdate('Y-m-d H:i:s'),
        );

        $order->add_order_note(sprintf(
            /* translators: 1: return ID, 2: reason */
            __('Anoofy: customer return #%1$d opened (%2$s).', 'anoofy-woo'),
            $returnId,
            $cleanReason
        ));
        $order->save();

        $this->audit->record($orderId, $userId, 'return.opened', null, null, $cleanReason, [
            'return_id' => $returnId,
            'source' => ReturnSource::Customer->value,
        ]);

        return ActionResult::ok();
    }

    /**
     * Records warehouse inspection results per item (restockable vs damaged quantities).
     *
     * @param array<int, array{restock_qty: int, damaged_qty: int, note?: string}> $linesByOrderItemId
     */
    public function saveInspection(int $returnId, int $userId, array $linesByOrderItemId): ActionResult
    {
        if (!$this->canUser($userId, Action::ReturnReceiveAndInspect)) {
            return ActionResult::fail(ActionResult::FORBIDDEN);
        }

        $ret = $this->returns->find($returnId);
        if ($ret === null) {
            return ActionResult::fail(ActionResult::NOT_FOUND);
        }

        $orderId = (int) $ret['order_id'];
        $ops = $this->ops->find($orderId);
        $handedOver = $ops !== null && $ops->isHandedOver();
        if (!ModePolicy::allows($handedOver)) {
            return ActionResult::fail(ActionResult::FORBIDDEN);
        }

        $items = $this->returns->findItems($returnId);
        $itemIds = [];
        $inspectionLines = [];
        $notesByItemId = [];

        foreach ($items as $item) {
            $itemId = (int) $item['order_item_id'];
            $itemIds[$itemId] = true;
            $input = $linesByOrderItemId[$itemId] ?? null;
            $restockQty = $input !== null ? (int) $input['restock_qty'] : (int) $item['restock_qty'];
            $damagedQty = $input !== null ? (int) $input['damaged_qty'] : (int) $item['damaged_qty'];
            $rawNote = $input !== null ? (string) ($input['note'] ?? '') : (string) ($item['note'] ?? '');
            $cleanNote = mb_substr(trim(sanitize_text_field($rawNote)), 0, 500);
            $notesByItemId[$itemId] = $cleanNote !== '' ? $cleanNote : null;

            $inspectionLines[] = new ReturnInspectionLine(
                orderItemId: $itemId,
                qtyReturned: (int) $item['qty_returned'],
                restockQty: $restockQty,
                damagedQty: $damagedQty,
                note: $cleanNote,
            );
        }

        foreach (array_keys($linesByOrderItemId) as $submittedId) {
            if (!isset($itemIds[(int) $submittedId])) {
                return ActionResult::fail(ActionResult::INVALID_INPUT);
            }
        }

        $validation = ReturnValidator::validateInspection($ret['status'], $handedOver, $inspectionLines);
        if (!$validation->ok) {
            return ActionResult::fail($validation->errorCode ?? ActionResult::INVALID_INPUT);
        }

        $updates = [];
        foreach ($inspectionLines as $line) {
            $updates[] = [
                'order_item_id' => $line->orderItemId,
                'restock_qty' => $line->restockQty,
                'damaged_qty' => $line->damagedQty,
                'condition' => $validation->conditionsByItemId[$line->orderItemId],
                'note' => $notesByItemId[$line->orderItemId],
            ];
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        if (!$this->returns->saveInspection($returnId, $userId, $updates, $nowUtc)) {
            return ActionResult::fail(ActionResult::CONCURRENT_CHANGE);
        }

        $this->audit->record($orderId, $userId, 'return.inspected', null, null, null, [
            'return_id' => $returnId,
        ]);

        return ActionResult::ok();
    }

    /**
     * Approves an inspected return via atomic CAS and performs one idempotent stock restock per item.
     */
    public function approveReturn(int $returnId, int $userId): ActionResult
    {
        if (!$this->canUser($userId, Action::ReturnApprove)) {
            return ActionResult::fail(ActionResult::FORBIDDEN);
        }

        $ret = $this->returns->find($returnId);
        if ($ret === null) {
            return ActionResult::fail(ActionResult::NOT_FOUND);
        }

        $orderId = (int) $ret['order_id'];
        $ops = $this->ops->find($orderId);
        $handedOver = $ops !== null && $ops->isHandedOver();
        if (!ModePolicy::allows($handedOver)) {
            return ActionResult::fail(ActionResult::FORBIDDEN);
        }

        $items = $this->returns->findItems($returnId);
        $inspectionLines = [];
        foreach ($items as $item) {
            $inspectionLines[] = new ReturnInspectionLine(
                orderItemId: (int) $item['order_item_id'],
                qtyReturned: (int) $item['qty_returned'],
                restockQty: (int) $item['restock_qty'],
                damagedQty: (int) $item['damaged_qty'],
                note: (string) ($item['note'] ?? ''),
            );
        }

        $validation = ReturnValidator::validateApproval($ret['status'], $handedOver, $inspectionLines);
        if (!$validation->ok) {
            return ActionResult::fail($validation->errorCode ?? ActionResult::CONCURRENT_CHANGE);
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        if (!$this->returns->compareAndSetApproved($returnId, $userId, $nowUtc)) {
            return ActionResult::fail(ActionResult::CONCURRENT_CHANGE);
        }

        $totalRestocked = 0;
        $totalDamaged = 0;

        foreach ($items as $item) {
            $returnItemId = (int) $item['id'];
            $restockQty = (int) $item['restock_qty'];
            $damagedQty = (int) $item['damaged_qty'];
            $productId = (int) $item['product_id'];
            $variationId = (int) $item['variation_id'];

            if ($restockQty > 0) {
                $restockKey = "return:{$returnId}:item:{$returnItemId}:restock";
                if ($this->stockMovements->recordOnce(
                    $orderId,
                    $returnId,
                    $returnItemId,
                    $productId,
                    $variationId,
                    'return_restock',
                    $restockQty,
                    $restockKey,
                    $userId,
                    $nowUtc
                )) {
                    $this->increaseProductStock($productId, $variationId, $restockQty);
                    $totalRestocked += $restockQty;
                }
            }

            if ($damagedQty > 0) {
                $damagedKey = "return:{$returnId}:item:{$returnItemId}:damaged";
                if ($this->stockMovements->recordOnce(
                    $orderId,
                    $returnId,
                    $returnItemId,
                    $productId,
                    $variationId,
                    'return_damaged',
                    $damagedQty,
                    $damagedKey,
                    $userId,
                    $nowUtc
                )) {
                    $totalDamaged += $damagedQty;
                }
            }
        }

        $order = wc_get_order($orderId);
        if ($order instanceof \WC_Order) {
            $order->add_order_note(sprintf(
                /* translators: 1: return ID, 2: restocked units, 3: damaged units */
                __('Anoofy: return #%1$d approved (%2$d restocked, %3$d damaged).', 'anoofy-woo'),
                $returnId,
                $totalRestocked,
                $totalDamaged
            ));
            $order->save();
        }

        $this->audit->record($orderId, $userId, 'return.approved', null, null, null, [
            'return_id' => $returnId,
            'restocked_qty' => $totalRestocked,
            'damaged_qty' => $totalDamaged,
        ]);

        return ActionResult::ok();
    }

    private function increaseProductStock(int $productId, int $variationId, int $qty): void
    {
        if ($qty <= 0 || !function_exists('wc_update_product_stock')) {
            return;
        }

        $targetId = $variationId > 0 ? $variationId : $productId;
        $product = wc_get_product($targetId);
        if (!$product instanceof \WC_Product) {
            return;
        }

        $managedBy = $product->get_stock_managed_by_id();
        $stockProduct = $managedBy !== $product->get_id() ? wc_get_product($managedBy) : $product;
        if ($stockProduct instanceof \WC_Product && $stockProduct->managing_stock()) {
            wc_update_product_stock($stockProduct, $qty, 'increase');
        }
    }

    private function canUser(int $userId, Action $action): bool
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User) {
            return false;
        }
        $role = RoleResolver::fromWpRoles(array_values(array_map('strval', $user->roles)));

        return $role !== null && Permissions::can($role, $action);
    }
}
