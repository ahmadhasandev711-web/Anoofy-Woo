<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Order;

final class TransitionResult
{
    /** @param list<Effect> $effects */
    private function __construct(
        public readonly bool $ok,
        public readonly ?OrderStatus $from,
        public readonly ?OrderStatus $to,
        public readonly array $effects,
        public readonly ?TransitionError $error,
    ) {
    }

    /** @param list<Effect> $effects */
    public static function success(OrderStatus $from, OrderStatus $to, array $effects = []): self
    {
        return new self(true, $from, $to, $effects, null);
    }

    public static function failure(TransitionError $error): self
    {
        return new self(false, null, null, [], $error);
    }
}
