<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Settlement;

final class SettlementMatch
{
    public function __construct(
        public readonly SettlementLine $line,
        public readonly SettlementRowStatus $status,
        public readonly ?SettlementCandidate $candidate = null,
    ) {
    }
}
