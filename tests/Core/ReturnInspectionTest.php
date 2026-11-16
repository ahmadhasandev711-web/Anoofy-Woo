<?php
declare(strict_types=1);

namespace Anoofy\Woo\Tests\Core;

use Anoofy\Woo\Core\Order\OrderStatus;
use Anoofy\Woo\Core\Returns\ReturnInspectionLine;
use Anoofy\Woo\Core\Returns\ReturnItemCondition;
use Anoofy\Woo\Core\Returns\ReturnOpenLine;
use Anoofy\Woo\Core\Returns\ReturnSource;
use Anoofy\Woo\Core\Returns\ReturnStatus;
use Anoofy\Woo\Core\Returns\ReturnValidator;
use PHPUnit\Framework\TestCase;

final class ReturnInspectionTest extends TestCase
{
    public function testEnumsExposeExpectedValues(): void
    {
        self::assertSame('rto', ReturnSource::Rto->value);
        self::assertSame('customer', ReturnSource::Customer->value);
        self::assertSame('open', ReturnStatus::Open->value);
        self::assertSame('inspected', ReturnStatus::Inspected->value);
        self::assertSame('approved', ReturnStatus::Approved->value);
    }

    public function testConditionClassificationFromQuantities(): void
    {
        self::assertSame(ReturnItemCondition::Uninspected, ReturnItemCondition::fromQuantities(3, 0, 0));
        self::assertSame(ReturnItemCondition::Restock, ReturnItemCondition::fromQuantities(3, 3, 0));
        self::assertSame(ReturnItemCondition::Damaged, ReturnItemCondition::fromQuantities(3, 0, 3));
        self::assertSame(ReturnItemCondition::Partial, ReturnItemCondition::fromQuantities(3, 2, 1));
        self::assertSame(ReturnItemCondition::Partial, ReturnItemCondition::fromQuantities(3, 1, 0));
    }

    public function testCustomerReturnRequiresDeliveredNonHandedOverOrderReasonAndValidQuantities(): void
    {
        $validLine = new ReturnOpenLine(10, 3, 1, 2);

        $rWrongStatus = ReturnValidator::validateCustomerOpen(OrderStatus::Shipped, false, [$validLine], 'Size wrong');
        self::assertFalse($rWrongStatus->ok);
        self::assertSame('WRONG_STATUS', $rWrongStatus->errorCode);

        $rHandedOver = ReturnValidator::validateCustomerOpen(OrderStatus::Delivered, true, [$validLine], 'Size wrong');
        self::assertFalse($rHandedOver->ok);
        self::assertSame('HANDED_OVER_TO_ENGAZ', $rHandedOver->errorCode);

        $rNoReason = ReturnValidator::validateCustomerOpen(OrderStatus::Delivered, false, [$validLine], '   ');
        self::assertFalse($rNoReason->ok);
        self::assertSame('REASON_REQUIRED', $rNoReason->errorCode);

        $rZeroQty = ReturnValidator::validateCustomerOpen(
            OrderStatus::Delivered,
            false,
            [new ReturnOpenLine(10, 3, 1, 0)],
            'Size wrong'
        );
        self::assertFalse($rZeroQty->ok);
        self::assertSame('INVALID_QUANTITY', $rZeroQty->errorCode);

        $rExceedsRemaining = ReturnValidator::validateCustomerOpen(
            OrderStatus::Delivered,
            false,
            [new ReturnOpenLine(10, 3, 2, 2)],
            'Size wrong'
        );
        self::assertFalse($rExceedsRemaining->ok);
        self::assertSame('INVALID_QUANTITY', $rExceedsRemaining->errorCode);

        $rOk = ReturnValidator::validateCustomerOpen(OrderStatus::Delivered, false, [$validLine], 'Wrong size');
        self::assertTrue($rOk->ok);
    }

    public function testInspectionRejectsNegativeOrExcessQuantitiesAndApprovedReturns(): void
    {
        $rNeg = ReturnValidator::validateInspection(ReturnStatus::Open, false, [
            new ReturnInspectionLine(1, 3, -1, 1),
        ]);
        self::assertFalse($rNeg->ok);
        self::assertSame('INVALID_QUANTITY', $rNeg->errorCode);

        $rOverflow = ReturnValidator::validateInspection(ReturnStatus::Open, false, [
            new ReturnInspectionLine(1, 3, 2, 2),
        ]);
        self::assertFalse($rOverflow->ok);
        self::assertSame('INVALID_QUANTITY', $rOverflow->errorCode);

        $rAllZero = ReturnValidator::validateInspection(ReturnStatus::Open, false, [
            new ReturnInspectionLine(1, 3, 0, 0),
        ]);
        self::assertFalse($rAllZero->ok);
        self::assertSame('INVALID_QUANTITY', $rAllZero->errorCode);

        $rApproved = ReturnValidator::validateInspection(ReturnStatus::Approved, false, [
            new ReturnInspectionLine(1, 3, 3, 0),
        ]);
        self::assertFalse($rApproved->ok);
        self::assertSame('ALREADY_APPROVED', $rApproved->errorCode);

        $rHandedOver = ReturnValidator::validateInspection(ReturnStatus::Open, true, [
            new ReturnInspectionLine(1, 3, 3, 0),
        ]);
        self::assertFalse($rHandedOver->ok);
        self::assertSame('HANDED_OVER_TO_ENGAZ', $rHandedOver->errorCode);

        $rValid = ReturnValidator::validateInspection(ReturnStatus::Open, false, [
            new ReturnInspectionLine(1, 4, 3, 1),
            new ReturnInspectionLine(2, 2, 2, 0),
        ]);
        self::assertTrue($rValid->ok);
        self::assertSame(ReturnItemCondition::Partial, $rValid->conditionsByItemId[1]);
        self::assertSame(ReturnItemCondition::Restock, $rValid->conditionsByItemId[2]);
    }

    public function testApprovalRequiresInspectedStatusAndFullQuantityAccounting(): void
    {
        $rOpen = ReturnValidator::validateApproval(ReturnStatus::Open, false, [
            new ReturnInspectionLine(1, 3, 3, 0),
        ]);
        self::assertFalse($rOpen->ok);
        self::assertSame('INSPECTION_REQUIRED', $rOpen->errorCode);

        $rAlreadyApproved = ReturnValidator::validateApproval(ReturnStatus::Approved, false, [
            new ReturnInspectionLine(1, 3, 3, 0),
        ]);
        self::assertFalse($rAlreadyApproved->ok);
        self::assertSame('ALREADY_APPROVED', $rAlreadyApproved->errorCode);

        $rIncomplete = ReturnValidator::validateApproval(ReturnStatus::Inspected, false, [
            new ReturnInspectionLine(1, 3, 2, 0),
        ]);
        self::assertFalse($rIncomplete->ok);
        self::assertSame('INSPECTION_INCOMPLETE', $rIncomplete->errorCode);

        $rOk = ReturnValidator::validateApproval(ReturnStatus::Inspected, false, [
            new ReturnInspectionLine(1, 3, 2, 1),
        ]);
        self::assertTrue($rOk->ok);
    }
}
