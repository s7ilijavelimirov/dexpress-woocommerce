<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Domain\Shipment;

use S7codedesign\DExpress\Domain\Money\Grams;

final class Package
{
    public function __construct(
        public readonly PackageCode $code,
        public readonly int $ordinal,
        public readonly ?Grams $mass = null,
        public readonly ?int $dimX = null,
        public readonly ?int $dimY = null,
        public readonly ?int $dimZ = null,
        public readonly ?int $vmass = null,
        public readonly ?string $referenceId = null,
        public readonly ?string $contentNote = null,
        public readonly ?int $id = null,
    ) {}

    /**
     * Build the PackageList entry for the D Express API payload.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $data = ['Code' => $this->code->value()];

        if ($this->mass !== null) {
            $data['Mass'] = $this->mass->value();
        }

        if ($this->dimX !== null) {
            $data['DimX'] = $this->dimX;
        }

        if ($this->dimY !== null) {
            $data['DimY'] = $this->dimY;
        }

        if ($this->dimZ !== null) {
            $data['DimZ'] = $this->dimZ;
        }

        if ($this->vmass !== null) {
            $data['VMass'] = $this->vmass;
        }

        if ($this->referenceId !== null) {
            $data['ReferenceID'] = $this->referenceId;
        }

        return $data;
    }
}
