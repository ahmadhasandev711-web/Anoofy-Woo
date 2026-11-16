<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Returns;

use Anoofy\Woo\Core\Order\OrderStatus;

/** Pure validation rules for opening customer returns, warehouse inspection, and stock-restock approval. */
final class ReturnValidator
{
    /** @param list<ReturnOpenLine> $lines */
    public static function validateCustomerOpen(
        OrderStatus $orderStatus,
        bool $handedOver,
        array $lines,
        string $reason,
    ): ReturnValidationResult {
        if ($handedOver) {
            return ReturnValidationResult::fail('HANDED_OVER_TO_ENGAZ');
        }
        if ($orderStatus !== OrderStatus::Delivered) {
            return ReturnValidationResult::fail('WRONG_STATUS');
        }
        if (trim($reason) === '') {
            return ReturnValidationResult::fail('REASON_REQUIRED');
        }
        if ($lines === []) {
            return ReturnValidationResult::fail('INVALID_QUANTITY');
        }

        $totalRequested = 0;
        foreach ($lines as $line) {
            if ($line->orderedQty <= 0 || $line->alreadyReturnedQty < 0 || $line->requestedQty < 0) {
                return ReturnValidationResult::fail('INVALID_QUANTITY');
            }
            $remaining = $line->orderedQty - $line->alreadyReturnedQty;
            if ($line->requestedQty > $remaining) {
                return ReturnValidationResult::fail('INVALID_QUANTITY');
            }
            $totalRequested += $line->requestedQty;
        }

        if ($totalRequested <= 0) {
            return ReturnValidationResult::fail('INVALID_QUANTITY');
        }

        return ReturnValidationResult::ok();
    }

    /** @param list<ReturnInspectionLine> $lines */
    public static function validateInspection(
        ReturnStatus $returnStatus,
        bool $handedOver,
        array $lines,
    ): ReturnValidationResult {
        if ($handedOver) {
            return ReturnValidationResult::fail('HANDED_OVER_TO_ENGAZ');
        }
        if ($returnStatus === ReturnStatus::Approved) {
            return ReturnValidationResult::fail('ALREADY_APPROVED');
        }
        if ($lines === []) {
            return ReturnValidationResult::fail('INVALID_QUANTITY');
        }

        $conditions = [];
        $totalClassified = 0;
        foreach ($lines as $line) {
            if ($line->qtyReturned <= 0 || $line->restockQty < 0 || $line->damagedQty < 0) {
                return ReturnValidationResult::fail('INVALID_QUANTITY');
            }
            if (($line->restockQty + $line->damagedQty) > $line->qtyReturned) {
                return ReturnValidationResult::fail('INVALID_QUANTITY');
            }
            $totalClassified += $line->restockQty + $line->damagedQty;
            $conditions[$line->orderItemId] = ReturnItemCondition::fromQuantities(
                $line->qtyReturned,
                $line->restockQty,
                $line->damagedQty
            );
        }

        if ($totalClassified <= 0) {
            return ReturnValidationResult::fail('INVALID_QUANTITY');
        }

        return ReturnValidationResult::ok($conditions);
    }

    /** @param list<ReturnInspectionLine> $lines */
    public static function validateApproval(
        ReturnStatus $returnStatus,
        bool $handedOver,
        array $lines,
    ): ReturnValidationResult {
        if ($handedOver) {
            return ReturnValidationResult::fail('HANDED_OVER_TO_ENGAZ');
        }
        if ($returnStatus === ReturnStatus::Approved) {
            return ReturnValidationResult::fail('ALREADY_APPROVED');
        }
        if ($returnStatus !== ReturnStatus::Inspected || $lines === []) {
            return ReturnValidationResult::fail('INSPECTION_REQUIRED');
        }

        foreach ($lines as $line) {
            if (
                $line->qtyReturned <= 0
                || $line->restockQty < 0
                || $line->damagedQty < 0
                || ($line->restockQty + $line->damagedQty) !== $line->qtyReturned
            ) {
                return ReturnValidationResult::fail('INSPECTION_INCOMPLETE');
            }
        }

        return ReturnValidationResult::ok();
    }
}
