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
        // 1. Check if plugin is connected
        $conn = $this->connectionService->getConnection();
        if ($conn === null || empty($conn['kid']) || empty($conn['secret'])) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Store is not connected to EngazPRO',
                'error_code' => 'UNAUTHORIZED',
            ], 401);
        }

        // 2. InboundVerifier on raw body BEFORE any JSON decoding
        $rawBody = (string) $request->get_body();
        $headerKid = $request->get_header('x-anoofy-key-id');
        $headerTs = $request->get_header('x-anoofy-timestamp');
        $headerSig = $request->get_header('x-anoofy-signature');

        $isValid = InboundVerifier::verify(
            expectedKid: (string) $conn['kid'],
            secret: (string) $conn['secret'],
            headerKid: $headerKid !== null ? (string) $headerKid : null,
            headerTs: $headerTs !== null ? (string) $headerTs : null,
            headerSig: $headerSig !== null ? (string) $headerSig : null,
            rawBody: $rawBody,
            now: time()
        );

        if (!$isValid) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Invalid signature or expired timestamp',
                'error_code' => 'UNAUTHORIZED',
            ], 401);
        }

        // 3. Decode JSON and validate envelope/event
        $envelope = json_decode($rawBody, true);
        if (!is_array($envelope)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'بيانات غير صالحة',
                'errors' => ['body' => 'Malformed JSON payload'],
            ], 422);
        }

        // 4. Check event_type before parsing specific event: unsupported types are ignored per contract
        if (!isset($envelope['event_type']) || $envelope['event_type'] !== EngazEventType::OrderStageChanged->value) {
            return new \WP_REST_Response([
                'success' => true,
                'data' => [
                    'result' => InboxResult::Ignored->value,
                    'reason' => 'unsupported event type',
                ],
            ], 200);
        }

        try {
            $event = StageChangedEvent::fromEnvelope($envelope);
        } catch (InvalidStageChangedEventException $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'بيانات غير صالحة',
                'errors' => ['envelope' => $e->getMessage()],
            ], 422);
        }

        // 5. Check order existence and handover state (DO NOT write to anoofy_inbox if ignored)
        $ops = $this->opsRepo->find($event->wcOrderId);
        if ($ops === null) {
            return new \WP_REST_Response([
                'success' => true,
                'data' => [
                    'result' => InboxResult::Ignored->value,
                    'reason' => 'order not found',
                ],
            ], 200);
        }

        if (!$ops->isHandedOver()) {
            return new \WP_REST_Response([
                'success' => true,
                'data' => [
                    'result' => InboxResult::Ignored->value,
                    'reason' => 'order is not handed over',
                ],
            ], 200);
        }

        // 6. Claim in inbox and apply stage update atomically
        try {
            $result = $this->inboxRepo->claimAndApply($event, $this->opsRepo);
        } catch (\Throwable $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Internal server error while processing event',
                'error_code' => 'INTERNAL',
            ], 500);
        }

        $responseData = ['result' => $result->value];
        if ($result === InboxResult::Duplicate) {
            $responseData['reason'] = 'Event already processed';
        } elseif ($result === InboxResult::Stale) {
            $responseData['reason'] = 'Older or duplicate version';
        }

        return new \WP_REST_Response([
            'success' => true,
            'data' => $responseData,
        ], 200);
    }
}
