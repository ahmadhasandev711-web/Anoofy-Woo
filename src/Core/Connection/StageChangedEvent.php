<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Connection;

final class StageChangedEvent
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        public readonly string $eventId,
        public readonly EngazEventType $eventType,
        public readonly string $occurredAt,
        public readonly int $orderVersion,
        public readonly int $wcOrderId,
        public readonly EngazStage $stage,
        public readonly ?EngazStage $previousStage,
        public readonly string $changedAt,
        public readonly ?string $branchName,
        public readonly ?string $courierName,
        public readonly ?string $trackingNumber,
    ) {
    }

    /**
     * @param array<string, mixed> $envelope
     * @throws InvalidStageChangedEventException
     */
    public static function fromEnvelope(array $envelope): self
    {
        if (!isset($envelope['schema_version']) || $envelope['schema_version'] !== 1) {
            throw new InvalidStageChangedEventException('Invalid or missing schema_version; expected 1.');
        }

        if (!isset($envelope['event_id']) || !is_string($envelope['event_id']) || !preg_match(self::UUID_REGEX, $envelope['event_id'])) {
            throw new InvalidStageChangedEventException('Invalid or missing event_id; expected a valid UUID.');
        }

        if (!isset($envelope['event_type']) || $envelope['event_type'] !== EngazEventType::OrderStageChanged->value) {
            throw new InvalidStageChangedEventException(sprintf(
                'Invalid event_type; expected %s.',
                EngazEventType::OrderStageChanged->value
            ));
        }

        if (!isset($envelope['occurred_at']) || !is_string($envelope['occurred_at']) || trim($envelope['occurred_at']) === '') {
            throw new InvalidStageChangedEventException('Invalid or missing occurred_at timestamp.');
        }

        if (!isset($envelope['order_version']) || !is_int($envelope['order_version']) || $envelope['order_version'] <= 0) {
            throw new InvalidStageChangedEventException('Invalid or missing order_version; expected a positive integer.');
        }

        if (!isset($envelope['data']) || !is_array($envelope['data'])) {
            throw new InvalidStageChangedEventException('Missing or invalid data object in envelope.');
        }

        $data = $envelope['data'];

        if (!isset($data['wc_order_id']) || !is_int($data['wc_order_id']) || $data['wc_order_id'] <= 0) {
            throw new InvalidStageChangedEventException('Missing or invalid data.wc_order_id; expected positive integer.');
        }

        if (!isset($data['stage']) || !is_string($data['stage'])) {
            throw new InvalidStageChangedEventException('Missing data.stage string.');
        }

        $stage = EngazStage::tryFrom($data['stage']);
        if ($stage === null || !$stage->isTransitionStage()) {
            throw new InvalidStageChangedEventException(sprintf(
                'Unknown or invalid data.stage "%s".',
                $data['stage']
            ));
        }

        $previousStage = null;
        if (isset($data['previous_stage']) && $data['previous_stage'] !== null) {
            if (!is_string($data['previous_stage'])) {
                throw new InvalidStageChangedEventException('Invalid data.previous_stage; expected string or null.');
            }
            $previousStage = EngazStage::tryFrom($data['previous_stage']);
            if ($previousStage === null) {
                throw new InvalidStageChangedEventException(sprintf(
                    'Unknown data.previous_stage "%s".',
                    $data['previous_stage']
                ));
            }
        }

        if (!isset($data['changed_at']) || !is_string($data['changed_at']) || trim($data['changed_at']) === '') {
            throw new InvalidStageChangedEventException('Missing or invalid data.changed_at timestamp.');
        }

        $branchName = isset($data['branch_name']) && is_string($data['branch_name']) ? trim($data['branch_name']) : null;
        $courierName = isset($data['courier_name']) && is_string($data['courier_name']) ? trim($data['courier_name']) : null;
        $trackingNumber = isset($data['tracking_number']) && is_string($data['tracking_number']) ? trim($data['tracking_number']) : null;

        return new self(
            eventId: $envelope['event_id'],
            eventType: EngazEventType::OrderStageChanged,
            occurredAt: $envelope['occurred_at'],
            orderVersion: $envelope['order_version'],
            wcOrderId: $data['wc_order_id'],
            stage: $stage,
            previousStage: $previousStage,
            changedAt: $data['changed_at'],
            branchName: $branchName !== '' ? $branchName : null,
            courierName: $courierName !== '' ? $courierName : null,
            trackingNumber: $trackingNumber !== '' ? $trackingNumber : null,
        );
    }
}
