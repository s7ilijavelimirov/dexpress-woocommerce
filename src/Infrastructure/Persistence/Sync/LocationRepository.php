<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence\Sync;

use S7codedesign\DExpress\Application\Sync\RowChangeStats;
use wpdb;

final class LocationRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'dexpress_locations';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function replaceAll(array $rows): RowChangeStats
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM `{$this->table}`");

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->wpdb->query("TRUNCATE TABLE `{$this->table}`");

        if (empty($rows)) {
            return new RowChangeStats(deleted: $deleted);
        }

        $now      = current_time('mysql');
        $inserted = 0;

        foreach ($rows as $row) {
            $result = $this->wpdb->insert(
                $this->table,
                [
                    'id'            => (int) $row['ID'],
                    'name'          => $row['Name'] ?? '',
                    'description'   => $row['Description'] ?? '',
                    'address'       => $row['Address'] ?? '',
                    'town_name'     => $row['Town'] ?? '',
                    'town_id'       => (int) ($row['TownID'] ?? 0),
                    'work_hours'    => $row['WorkHours'] ?? '',
                    'work_days'     => $row['WorkDays'] ?? '',
                    'phone'         => $row['Phone'] ?? '',
                    'latitude'      => $row['Latitude'] ?? '',
                    'longitude'     => $row['Longitude'] ?? '',
                    'location_type' => $row['LocationType'] ?? '',
                    'pay_by_cash'   => !empty($row['PayByCash']) ? 1 : 0,
                    'pay_by_card'   => !empty($row['PayByCard']) ? 1 : 0,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ],
                ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'],
            );

            if ($result !== false) {
                ++$inserted;
            }
        }

        return new RowChangeStats(inserted: $inserted, deleted: $deleted);
    }
}
