<?php
declare(strict_types=1);

namespace Anoofy\Woo\Services;

use Anoofy\Woo\Core\Connection\ConnectionKey;
use Anoofy\Woo\Core\Connection\EngazEventType;
use Anoofy\Woo\Core\Connection\EnvelopeFactory;
use Anoofy\Woo\Core\Connection\InvalidConnectionKeyException;
use Anoofy\Woo\Core\Connection\Signature;
use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

final class EngazConnectionService
{
    public const OPTION_NAME = 'anoofy_engaz_connection';

    /**
     * @return array{api: string, kid: string, secret: string, connected_at: string}|null
     */
    public function getConnection(): ?array
    {
        $conn = get_option(self::OPTION_NAME, null);
        if (!is_array($conn) || empty($conn['api']) || empty($conn['kid']) || empty($conn['secret'])) {
            return null;
        }

        return [
            'api' => (string) $conn['api'],
            'kid' => (string) $conn['kid'],
            'secret' => (string) $conn['secret'],
            'connected_at' => (string) ($conn['connected_at'] ?? ''),
        ];
    }

    public function isConnected(): bool
    {
        return $this->getConnection() !== null;
    }

    public function disconnect(): void
    {
        delete_option(self::OPTION_NAME);
        delete_option('anoofy_engaz_auth_failed_at');
    }

    public function isAuthFailed(): bool
    {
        $conn = get_option(self::OPTION_NAME, null);
        if (is_array($conn) && !empty($conn['auth_failed_at'])) {
            return true;
        }

        return !empty(get_option('anoofy_engaz_auth_failed_at'));
    }

    public function authFailedAt(): ?string
    {
        $conn = get_option(self::OPTION_NAME, null);
        if (is_array($conn) && !empty($conn['auth_failed_at'])) {
            return (string) $conn['auth_failed_at'];
        }

        $val = get_option('anoofy_engaz_auth_failed_at', null);

        return is_string($val) && $val !== '' ? $val : null;
    }

    public function clearAuthFailed(): void
    {
        $conn = get_option(self::OPTION_NAME, null);
        if (is_array($conn) && isset($conn['auth_failed_at'])) {
            unset($conn['auth_failed_at']);
            update_option(self::OPTION_NAME, $conn, false);
        }
        delete_option('anoofy_engaz_auth_failed_at');
    }

    public function handleHttpResponse(int $statusCode): void
    {
        if ($statusCode === 401) {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $conn = get_option(self::OPTION_NAME, null);
            if (is_array($conn)) {
                $conn['auth_failed_at'] = $now;
                update_option(self::OPTION_NAME, $conn, false);
            }
            update_option('anoofy_engaz_auth_failed_at', $now, false);
        }
    }

    public function connect(string $rawKey): ActionResult
    {
        try {
            $keyData = ConnectionKey::parse($rawKey);
        } catch (InvalidConnectionKeyException $e) {
            return ActionResult::fail(ActionResult::INVALID_INPUT, $e->getMessage());
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $timestamp = $now->getTimestamp();

        $envelope = EnvelopeFactory::build(
            type: EngazEventType::ConnectionVerify,
            orderVersion: 1,
            data: [
                'site_url' => site_url(),
                'callback_url' => rest_url('anoofy/v1/callback'),
                'plugin_version' => defined('ANOOFY_WOO_VERSION') ? ANOOFY_WOO_VERSION : '1.0.0',
            ],
            occurredAt: $now
        );

        $rawBody = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = Signature::sign($keyData->secret, $timestamp, $rawBody);

        $response = wp_remote_post($keyData->api, [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Anoofy-Key-Id' => $keyData->kid,
                'X-Anoofy-Timestamp' => (string) $timestamp,
                'X-Anoofy-Signature' => $signature,
            ],
            'body' => $rawBody,
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return ActionResult::fail(ActionResult::CONNECTION_FAILED);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->handleHttpResponse($code);
            return ActionResult::fail(ActionResult::CONNECTION_FAILED);
        }

        update_option(self::OPTION_NAME, [
            'api' => $keyData->api,
            'kid' => $keyData->kid,
            'secret' => $keyData->secret,
            'connected_at' => $now->format('Y-m-d\TH:i:s\Z'),
        ], false);
        $this->clearAuthFailed();

        // Reset failed 401 rows to pending
        Services::outbox()->resetFailedAuthRows();

        // Enqueue async action for each pending row, avoiding duplicates if already scheduled
        $pendingIds = Services::outbox()->findPendingIds();
        foreach ($pendingIds as $pId) {
            if (function_exists('as_enqueue_async_action')) {
                if (function_exists('as_has_scheduled_action')) {
                    if (!as_has_scheduled_action('anoofy_engaz_send', ['outbox_id' => $pId])) {
                        as_enqueue_async_action('anoofy_engaz_send', ['outbox_id' => $pId]);
                    }
                } else {
                    as_enqueue_async_action('anoofy_engaz_send', ['outbox_id' => $pId]);
                }
            }
        }

        return ActionResult::ok();
    }
}
