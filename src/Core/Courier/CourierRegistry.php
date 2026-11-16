<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Courier;

/** Registry of available courier adapters keyed by slug. */
final class CourierRegistry
{
    /** @var array<string, CourierGateway> */
    private array $gateways = [];

    public static function withDefaults(): self
    {
        $registry = new self();
        $registry->register(new ManualCourierGateway());
        $registry->register(new BostaCourierGateway());
        $registry->register(new AramexCourierGateway());
        $registry->register(new MylerzCourierGateway());

        return $registry;
    }

    public function register(CourierGateway $gateway): void
    {
        $this->gateways[$gateway->slug()] = $gateway;
    }

    public function get(string $slug): ?CourierGateway
    {
        return $this->gateways[$slug] ?? null;
    }

    /** @return array<string, CourierGateway> */
    public function all(): array
    {
        return $this->gateways;
    }
}
