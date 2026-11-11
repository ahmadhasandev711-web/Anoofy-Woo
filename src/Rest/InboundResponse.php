<?php
declare(strict_types=1);

namespace Anoofy\Woo\Rest;

final class InboundResponse
{
    public static function unauthorized(string $message = 'Store is not connected to EngazPRO'): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'success' => false,
            'message' => $message,
            'error_code' => 'UNAUTHORIZED',
        ], 401);
    }

    /**
     * @param array<string, mixed> $errors
     */
    public static function invalid(string $message, array $errors = []): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], 422);
    }

    public static function ignored(string $reason): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'success' => true,
            'data' => [
                'result' => 'ignored',
                'reason' => $reason,
            ],
        ], 200);
    }

    public static function ok(string $result, ?string $reason = null): \WP_REST_Response
    {
        $data = ['result' => $result];
        if ($reason !== null) {
            $data['reason'] = $reason;
        }

        return new \WP_REST_Response([
            'success' => true,
            'data' => $data,
        ], 200);
    }

    public static function internal(string $message = 'Internal server error while processing event'): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'success' => false,
            'message' => $message,
            'error_code' => 'INTERNAL',
        ], 500);
    }
}
