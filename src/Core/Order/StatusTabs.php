<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Order;

/** Groups the 12 statuses into the tabs of the Orders screen. Every status belongs to exactly one tab (plus "all"). */
final class StatusTabs
{
    public const ALL = 'all';

    /** @return array<string, list<OrderStatus>> tab key => statuses, in display order (without "all") */
    public static function groups(): array
    {
        return [
            'new' => [OrderStatus::New],
            'under_review' => [OrderStatus::UnderReview],
            'confirmed' => [OrderStatus::Confirmed],
            'in_progress' => [
                OrderStatus::Preparing,
                OrderStatus::ReadyToShip,
                OrderStatus::CancellationPending,
                OrderStatus::Shipped,
                OrderStatus::DeliveryFailed,
            ],
            'finished' => [OrderStatus::Delivered, OrderStatus::ReturnedToOrigin],
            'closed' => [OrderStatus::Rejected, OrderStatus::Cancelled],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return [self::ALL, ...array_keys(self::groups())];
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /** @return list<OrderStatus>|null null = no filter ("all" or unknown key) */
    public static function statusesFor(string $key): ?array
    {
        return self::groups()[$key] ?? null;
    }
}
