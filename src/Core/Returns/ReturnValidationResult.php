<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Returns;

/** Outcome of validating return opening, warehouse inspection, or approval. */
final class ReturnValidationResult
{
    /** @param array<int, ReturnItemCondition> $conditionsByItemId */
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $errorCode,
        public readonly array $conditionsByItemId,
    ) {
    }

    /** @param array<int, ReturnItemCondition> $conditionsByItemId */
    public static function ok(array $conditionsByItemId = []): self
    {
        return new self(true, null, $conditionsByItemId);
    }

    public static function fail(string $errorCode): self
    {
        return new self(false, $errorCode, []);
    }
}
