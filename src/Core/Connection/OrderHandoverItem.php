<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Connection;

final class OrderHandoverItem
{
    public function __construct(
        public readonly int $productId,
        public readonly ?int $variationId,
        public readonly int $quantity,
        public readonly string $name,
        public readonly ?string $sku,
        public readonly int $subtotalCents,
        public readonly int $totalCents,
        public readonly ?int $unitPriceCents = null
    ) {
    }

    public static function fromMoney(
        int $productId,
        ?int $variationId,
        int $quantity,
        string $name,
        ?string $sku,
        string|int|float $subtotal,
        string|int|float $total,
        string|int|float|null $unitPrice = null
    ): self {
        return new self(
            productId: $productId,
            variationId: $variationId,
            quantity: $quantity,
            name: $name,
            sku: $sku,
            subtotalCents: self::toCents($subtotal),
            totalCents: self::toCents($total),
            unitPriceCents: $unitPrice !== null ? self::toCents($unitPrice) : null
        );
    }

    public static function toCents(string|int|float $amount): int
    {
        if (is_int($amount)) {
            return $amount * 100;
        }

        $str = trim((string) $amount);
        if ($str === '' || $str === '0') {
            return 0;
        }

        $negative = str_starts_with($str, '-');
        if ($negative) {
            $str = substr($str, 1);
        }

        $parts = explode('.', $str, 2);
        $major = (int) ($parts[0] !== '' ? $parts[0] : '0');

        if (!isset($parts[1])) {
            $cents = $major * 100;
        } else {
            $dec = str_pad($parts[1], 3, '0');
            $cents2 = (int) substr($dec, 0, 2);
            $digit3 = (int) $dec[2];
            $roundUp = $digit3 >= 5 ? 1 : 0;
            $cents = ($major * 100) + $cents2 + $roundUp;
        }

        return $negative ? -$cents : $cents;
    }

    public static function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    public static function unitPriceCents(int $subtotalCents, int $qty): int
    {
        if ($qty <= 0) {
            return 0;
        }

        return intdiv((2 * $subtotalCents) + $qty, 2 * $qty);
    }
}
