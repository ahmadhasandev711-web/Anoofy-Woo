<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Connection;

use JsonException;

final class ConnectionKey
{
    /**
     * @throws InvalidConnectionKeyException
     */
    public static function parse(string $raw): ConnectionKeyData
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw new InvalidConnectionKeyException('Connection key is empty.');
        }

        $normalized = str_replace(['-', '_'], ['+', '/'], $trimmed);
        $remainder = strlen($normalized) % 4;
        if ($remainder !== 0) {
            $normalized .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            throw new InvalidConnectionKeyException('Connection key is not valid base64url.');
        }

        try {
            $data = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidConnectionKeyException('Connection key payload is not valid JSON.', 0, $e);
        }

        if (!is_array($data)) {
            throw new InvalidConnectionKeyException('Connection key payload must be a JSON object.');
        }

        if (!isset($data['v']) || $data['v'] !== 1) {
            throw new InvalidConnectionKeyException('Unsupported or missing connection key version (expected v=1).');
        }

        if (!isset($data['api']) || !is_string($data['api']) || !str_starts_with($data['api'], 'https://') || filter_var($data['api'], FILTER_VALIDATE_URL) === false) {
            throw new InvalidConnectionKeyException('Invalid or non-HTTPS API endpoint URL.');
        }

        if (!isset($data['kid']) || !is_string($data['kid']) || trim($data['kid']) === '') {
            throw new InvalidConnectionKeyException('Invalid or missing kid (key id).');
        }

        if (!isset($data['secret']) || !is_string($data['secret']) || trim($data['secret']) === '') {
            throw new InvalidConnectionKeyException('Invalid or missing connection secret.');
        }

        return new ConnectionKeyData(
            version: (int) $data['v'],
            api: (string) $data['api'],
            kid: trim((string) $data['kid']),
            secret: trim((string) $data['secret'])
        );
    }
}
