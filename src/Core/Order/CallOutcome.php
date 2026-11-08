<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Order;

/** Result of one call attempt to the customer. Stored as the enum value in `anoofy_call_attempts.outcome`. */
enum CallOutcome: string
{
    case Answered = 'answered';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case WrongNumber = 'wrong_number';
    case CallLater = 'call_later';

    /** Only a call where the customer picked up counts as the call confirmation the Confirm guard asks for. */
    public function countsAsConfirmationCall(): bool
    {
        return $this === self::Answered;
    }
}
