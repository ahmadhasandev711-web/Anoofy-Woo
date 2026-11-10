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
    }

    public function handleHttpResponse(int $statusCode): void
    {
        if ($statusCode === 401) {
            $this->disconnect();
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
            return ActionResult::fail('CONNECTION_FAILED', $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->handleHttpResponse($code);
            return ActionResult::fail('CONNECTION_FAILED', "EngazPRO verification failed with status {$code}");
        }

        update_option(self::OPTION_NAME, [
            'api' => $keyData->api,
            'kid' => $keyData->kid,
            'secret' => $keyData->secret,
            'connected_at' => $now->format('Y-m-d\TH:i:s\Z'),
        ], false);

        return ActionResult::ok();
    }
}
