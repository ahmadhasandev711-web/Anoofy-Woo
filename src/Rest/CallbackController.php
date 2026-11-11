<?php
declare(strict_types=1);

namespace Anoofy\Woo\Rest;

use Anoofy\Woo\Services\Services;

final class CallbackController
{
    public const ROUTE = '/anoofy/v1/callback';

    public static function registerRoutes(): void
    {
        register_rest_route('anoofy/v1', '/callback', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'handleCallback'],
            'permission_callback' => '__return_true',
        ]);

        add_filter('rest_pre_dispatch', static function ($result, $server, \WP_REST_Request $request) {
            if ($request->get_route() === self::ROUTE) {
                return Services::engazInbound()->handle($request);
            }

            return $result;
        }, 10, 3);
    }

    public static function handleCallback(\WP_REST_Request $request): \WP_REST_Response
    {
        return Services::engazInbound()->handle($request);
    }
}
