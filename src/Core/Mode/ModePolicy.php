<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Mode;

use Anoofy\Woo\Core\Order\Effect;
use Anoofy\Woo\Core\Order\OrderEvent;

final class ModePolicy
{
    public static function allows(bool $handedOver): bool
    {
        return !$handedOver;
    }

    /**
     * @return list<Effect>
     */
    public static function extraEffects(OperatingMode $mode, OrderEvent $event): array
    {
        if ($mode === OperatingMode::Connected && $event === OrderEvent::Confirm) {
            return [Effect::HandOverToEngaz];
        }

        return [];
    }
}
