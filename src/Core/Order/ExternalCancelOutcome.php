<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Order;

/** What to do when the WooCommerce order is cancelled outside our workflow (e.g. from the Woo admin). */
final class ExternalCancelOutcome
{
    public function __construct(
        public readonly ?OrderStatus $to,
        public readonly bool $needsDecision,
        public readonly bool $createRestockTask,
    ) {
    }
}
