<?php
declare(strict_types=1);

namespace Anoofy\Woo\Services;

use Anoofy\Woo\Core\Connection\EngazEventType;
use Anoofy\Woo\Core\Connection\InboundVerifier;
use Anoofy\Woo\Core\Connection\InboxResult;
use Anoofy\Woo\Core\Connection\InvalidStageChangedEventException;
use Anoofy\Woo\Core\Connection\StageChangedEvent;
use Anoofy\Woo\Data\InboxRepository;
use Anoofy\Woo\Data\OpsRepository;
use Anoofy\Woo\Rest\InboundResponse;

final class EngazInboundService
{
    public function __construct(
        private readonly EngazConnectionService $connectionService,
        private readonly OpsRepository $opsRepo,
        private readonly InboxRepository $inboxRepo,
    ) {
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $conn = $this->connectionService->getConnection();
        if ($conn === null || empty($conn['kid']) || empty($conn['secret'])) {
            return InboundResponse::unauthorized();
        }

        $rawBody = (string) $request->get_body();
        if (!$this->verifySignature($request, $conn, $rawBody)) {
            return InboundResponse::unauthorized('Invalid signature or expired timestamp');
        }

        $envelope = json_decode($rawBody, true);
        if (!is_array($envelope)) {
            return InboundResponse::invalid('بيانات غير صالحة', ['body' => 'Malformed JSON payload']);
        }

        if (!isset($envelope['event_type']) || $envelope['event_type'] !== EngazEventType::OrderStageChanged->value) {
            return InboundResponse::ignored('unsupported event type');
        }

        try {
            $event = StageChangedEvent::fromEnvelope($envelope);
        } catch (InvalidStageChangedEventException $e) {
            return InboundResponse::invalid('بيانات غير صالحة', ['envelope' => $e->getMessage()]);
        }

        return $this->handleStageChanged($event);
    }

    private function verifySignature(\WP_REST_Request $request, array $conn, string $rawBody): bool
    {
        return InboundVerifier::verify(
            expectedKid: (string) $conn['kid'],
            secret: (string) $conn['secret'],
            headerKid: $request->get_header('x-anoofy-key-id'),
            headerTs: $request->get_header('x-anoofy-timestamp'),
            headerSig: $request->get_header('x-anoofy-signature'),
            rawBody: $rawBody,
            now: time()
        );
    }

    private function handleStageChanged(StageChangedEvent $event): \WP_REST_Response
    {
        $ops = $this->opsRepo->find($event->wcOrderId);
        if ($ops === null) {
            return InboundResponse::ignored('order not found');
        }

        if (!$ops->isHandedOver()) {
            return InboundResponse::ignored('order is not handed over');
        }

        try {
            $result = $this->applyEvent($event);
        } catch (\Throwable) {
            return InboundResponse::internal();
        }

        return match ($result) {
            InboxResult::Duplicate => InboundResponse::ok($result->value, 'Event already processed'),
            InboxResult::Stale => InboundResponse::ok($result->value, 'Older or duplicate version'),
            default => InboundResponse::ok($result->value),
        };
    }

    private function applyEvent(StageChangedEvent $event): InboxResult
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $claimed = $this->inboxRepo->claim($event->eventId, $event->eventType->value, $event->wcOrderId, gmdate('Y-m-d H:i:s'));
            if (!$claimed) {
                $wpdb->query('COMMIT');
                return InboxResult::Duplicate;
            }

            $updated = $this->opsRepo->applyEngazStageChange(
                $event->wcOrderId, $event->stage, $event->changedAt, $event->orderVersion,
                $event->branchName, $event->courierName, $event->trackingNumber
            );

            if (!$updated) {
                $this->inboxRepo->markStale($event->eventId);
                $wpdb->query('COMMIT');
                return InboxResult::Stale;
            }

            $wpdb->query('COMMIT');
            return InboxResult::Processed;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
}
