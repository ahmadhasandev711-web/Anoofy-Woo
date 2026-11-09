<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Connection;

use DateTimeImmutable;
use DateTimeZone;

final class EnvelopeFactory
{
    /**
     * @param array<string, mixed> $data
     * @return array{
     *   schema_version: int,
     *   event_id: string,
     *   event_type: string,
     *   occurred_at: string,
     *   order_version: int|null,
     *   data: array<string, mixed>
     * }
     */
    public static function build(
        EngazEventType $type,
        ?int $orderVersion,
        array $data,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null
    ): array {
        $id = $eventId ?? self::generateUuidV4();
        $timestamp = ($occurredAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');

        return [
            'schema_version' => 1,
            'event_id' => $id,
            'event_type' => $type->value,
            'occurred_at' => $timestamp,
            'order_version' => $orderVersion,
            'data' => $data,
        ];
    }

    private static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
