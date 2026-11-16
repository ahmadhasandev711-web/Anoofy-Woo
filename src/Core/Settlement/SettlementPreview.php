<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Settlement;

final class SettlementPreview
{
    /**
     * @param list<SettlementMatch> $rows
     * @param list<SettlementMatch> $matched
     * @param list<string>          $parseErrors
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $matched,
        public readonly int $matchedTotalCents,
        public readonly array $parseErrors = [],
    ) {
    }
}
