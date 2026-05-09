<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence\Sync;

use S7codedesign\DExpress\Application\Sync\RowChangeStats;
use wpdb;

/**
 * Mapira mysqli_affected_rows posle INSERT ... ON DUPLICATE KEY UPDATE (MySQL 5.7+).
 *
 * 1 = novi red, 2 = postojeći red sa izmenjenim vrednostima, 0 = dupli ključ bez izmene kolona.
 */
final class UpsertOutcome
{
    public static function fromWpdb(wpdb $wpdb): RowChangeStats
    {
        $n = (int) $wpdb->rows_affected;

        if ($n === 1) {
            return new RowChangeStats(inserted: 1);
        }

        if ($n === 2) {
            return new RowChangeStats(updated: 1);
        }

        return new RowChangeStats(unchanged: 1);
    }

    public static function mergeStats(RowChangeStats $into, wpdb $wpdb): RowChangeStats
    {
        return RowChangeStats::merge($into, self::fromWpdb($wpdb));
    }

    /**
     * Za jedan INSERT ... ON DUPLICATE sa više redova MySQL vraća zbir po-vrednosti (1 + insert, 2 + update, 0 + unchanged).
     * Rešava I+U+C=N, I+2U=A za nenegativne cele brojeve (uz preferencu manjeg C — više „novih“ redova).
     */
    public static function fromBatchWpdb(wpdb $wpdb, int $rowCount): RowChangeStats
    {
        if ($rowCount <= 0) {
            return new RowChangeStats();
        }

        $a = (int) $wpdb->rows_affected;

        for ($c = 0; $c <= $rowCount; ++$c) {
            $u = $a - $rowCount + $c;
            $i = 2 * $rowCount - $a - 2 * $c;
            if ($i >= 0 && $u >= 0) {
                return new RowChangeStats(inserted: $i, updated: $u, unchanged: $c);
            }
        }

        return new RowChangeStats(unchanged: $rowCount);
    }
}
