<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Settlement;

final class SettlementLine
{
    public function __construct(
        public readonly int $rowNumber,
        public readonly string $trackingNumber,
        public readonly int $collectedCents,
        public readonly ?string $reference = null,
    ) {
    }
}
