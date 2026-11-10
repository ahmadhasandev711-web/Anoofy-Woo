<?php
declare(strict_types=1);

namespace Anoofy\Woo\Services;

use Anoofy\Woo\Core\Connection\Signature;

final class EngazOutboxWorker
{
    private const RETRY_SCHEDULE = [60, 300, 900, 3600, 21600, 43200];

    public static function process(int $outboxId): void
    {
        $repo = Services::outbox();
        $row = $repo->find($outboxId);
        if ($row === null) {
            return;
        }

        if ($row['status'] !== 'pending' && $row['status'] !== 'in_flight') {
            return;
        }

        if (!empty($row['next_retry_at']) && strtotime($row['next_retry_at']) > time()) {
            return;
        }

        $repo->updateStatus($outboxId, 'in_flight');

        $conn = Services::engazConnection()->getConnection();
        if ($conn === null) {
            $repo->updateStatus($outboxId, 'failed', lastError: 'Not connected to EngazPRO', incrementAttempts: true);
            return;
        }

        $timestamp = time();
        $rawBody = (string) $row['payload'];
        $signature = Signature::sign($conn['secret'], $timestamp, $rawBody);

        $response = wp_remote_post($conn['api'], [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Anoofy-Key-Id' => $conn['kid'],
                'X-Anoofy-Timestamp' => (string) $timestamp,
                'X-Anoofy-Signature' => $signature,
            ],
            'body' => $rawBody,
            'timeout' => 15,
        ]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $repo->markSent($outboxId, gmdate('Y-m-d H:i:s'));
            return;
        }

        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $errorMsg = is_wp_error($response) ? $response->get_error_message() : "HTTP {$code}";

        if ($code === 401) {
            Services::engazConnection()->handleHttpResponse(401);
            $repo->updateStatus($outboxId, 'failed', lastError: '401 Unauthorized', incrementAttempts: true);
            return;
        }

        $currentAttempts = (int) $row['attempts'] + 1;
        if ($currentAttempts > count(self::RETRY_SCHEDULE)) {
            $repo->updateStatus($outboxId, 'failed', lastError: $errorMsg, incrementAttempts: true);
            return;
        }

        $delay = self::RETRY_SCHEDULE[$currentAttempts - 1];
        $nextRetryAt = gmdate('Y-m-d H:i:s', time() + $delay);

        $repo->updateStatus(
            $outboxId,
            'pending',
            nextRetryAt: $nextRetryAt,
            lastError: $errorMsg,
            incrementAttempts: true
        );

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + $delay, 'anoofy_engaz_send', ['outbox_id' => $outboxId]);
        }
    }
}
