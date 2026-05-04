<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Domain\Events;

use S7codedesign\DExpress\Domain\Shipment\Shipment;

final class ShipmentCreated
{
    public function __construct(
        public readonly Shipment $shipment,
        public readonly int $orderId,
    ) {}
}
