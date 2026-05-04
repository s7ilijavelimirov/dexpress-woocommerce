<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Shipment;

use S7codedesign\DExpress\Domain\Shipment\Shipment;

final class CreateShipmentResult
{
    public function __construct(
        public readonly Shipment $shipment,
    ) {}

    public function trackingCode(): string
    {
        return $this->shipment->trackingCode();
    }

    /**
     * @return list<string>
     */
    public function allPackageCodes(): array
    {
        return array_values(array_map(
            static fn ($pkg) => $pkg->code->value(),
            $this->shipment->packages,
        ));
    }

    /**
     * True when the API accepted the request in test mode ("TEST" response).
     * The shipment is persisted but no real courier job was created.
     */
    public function isTestMode(): bool
    {
        return $this->shipment->apiResponse() === 'TEST';
    }
}
