<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence;

use S7codedesign\DExpress\Domain\Money\Grams;
use S7codedesign\DExpress\Domain\Shipment\Package;
use S7codedesign\DExpress\Domain\Shipment\PackageCode;

final class WpdbPackageRepository
{
    private string $table;

    public function __construct(private readonly \wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'dexpress_packages';
    }

    /**
     * Inserts a package row for the given shipment. Returns the package with its DB id.
     */
    public function saveForShipment(Package $package, int $shipmentId): Package
    {
        $now = current_time('mysql');

        // Only include nullable columns when values exist — avoids wpdb format/null alignment issues.
        $row = [
            'shipment_id'  => $shipmentId,
            'code'         => $package->code->value(),
            'reference_id' => $package->referenceId,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
        $format = ['%d', '%s', '%s', '%s', '%s'];

        if ($package->mass !== null) {
            $row['mass_grams'] = $package->mass->value();
            $format[]         = '%d';
        }

        foreach (['dim_x' => $package->dimX, 'dim_y' => $package->dimY, 'dim_z' => $package->dimZ] as $col => $val) {
            if ($val !== null && $val > 0) {
                $row[$col] = $val;
                $format[] = '%d';
            }
        }

        if ($package->vmass !== null && $package->vmass > 0) {
            $row['vmass'] = $package->vmass;
            $format[]    = '%d';
        }

        if ($package->contentNote !== null && $package->contentNote !== '') {
            $row['content_note'] = $package->contentNote;
            $format[]           = '%s';
        }

        $this->wpdb->insert($this->table, $row, $format);

        return new Package(
            code:        $package->code,
            ordinal:     $package->ordinal,
            mass:        $package->mass,
            dimX:        $package->dimX,
            dimY:        $package->dimY,
            dimZ:        $package->dimZ,
            vmass:       $package->vmass,
            referenceId: $package->referenceId,
            contentNote: $package->contentNote,
            id:          (int) $this->wpdb->insert_id,
        );
    }

    /**
     * @return Package[]
     */
    public function findByShipmentId(int $shipmentId): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM `{$this->table}` WHERE shipment_id = %d ORDER BY id ASC",
                $shipmentId,
            ),
            ARRAY_A,
        );

        if (!is_array($rows)) {
            return [];
        }

        $packages = [];
        foreach ($rows as $i => $row) {
            $cn = isset($row['content_note']) && $row['content_note'] !== ''
                ? (string) $row['content_note']
                : null;

            $packages[] = new Package(
                code:        PackageCode::fromString($row['code']),
                ordinal:     $i + 1,
                mass:        $row['mass_grams'] !== null ? Grams::fromGrams((int) $row['mass_grams']) : null,
                dimX:        $row['dim_x'] !== null ? (int) $row['dim_x'] : null,
                dimY:        $row['dim_y'] !== null ? (int) $row['dim_y'] : null,
                dimZ:        $row['dim_z'] !== null ? (int) $row['dim_z'] : null,
                vmass:       $row['vmass'] !== null ? (int) $row['vmass'] : null,
                referenceId: $row['reference_id'],
                contentNote: $cn,
                id:          (int) $row['id'],
            );
        }

        return $packages;
    }

    public function updateForShipment(Package $package, int $shipmentId): void
    {
        if ($package->id === null || $package->id <= 0) {
            throw new \RuntimeException('Paket nije validan za ažuriranje.');
        }

        $this->wpdb->update(
            $this->table,
            [
                'mass_grams'   => $package->mass?->value(),
                'dim_x'        => $package->dimX,
                'dim_y'        => $package->dimY,
                'dim_z'        => $package->dimZ,
                'vmass'        => $package->vmass,
                'reference_id' => $package->referenceId,
                'content_note' => $package->contentNote,
                'updated_at'   => current_time('mysql'),
            ],
            [
                'id'          => $package->id,
                'shipment_id' => $shipmentId,
            ],
            ['%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s'],
            ['%d', '%d'],
        );
    }
}
