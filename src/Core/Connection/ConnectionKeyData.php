<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Connection;

final class ConnectionKeyData
{
    public function __construct(
        public readonly int $version,
        public readonly string $api,
        public readonly string $kid,
        public readonly string $secret
    ) {
    }
}
