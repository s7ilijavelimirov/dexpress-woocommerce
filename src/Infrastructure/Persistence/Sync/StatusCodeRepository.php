<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence\Sync;

use S7codedesign\DExpress\Application\Sync\RowChangeStats;
use wpdb;

final class StatusCodeRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'dexpress_status_codes';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function upsertBatch(array $rows): RowChangeStats
    {
        if (empty($rows)) {
            return new RowChangeStats();
        }

        $now   = current_time('mysql');
        $stats = new RowChangeStats();

        foreach ($rows as $row) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $result = $this->wpdb->query(
                $this->wpdb->prepare(
                    "INSERT INTO `{$this->table}`
                        (sid, name_sr, name_en, created_at, updated_at)
                     VALUES (%d, %s, %s, %s, %s)
                     ON DUPLICATE KEY UPDATE
                        name_sr = VALUES(name_sr),
                        name_en = VALUES(name_en),
                        updated_at = VALUES(updated_at)",
                    $row['ID'],
                    $row['Name'] ?? '',
                    $row['NameEn'] ?? '',
                    $now,
                    $now,
                ),
            );

            if ($result !== false) {
                $stats = UpsertOutcome::mergeStats($stats, $this->wpdb);
            }
        }

        return $stats;
    }

    /**
     * Najmanji sid u šifarniku (za pregled mejla koji koristi istu rezoluciju labela kao produkcija).
     */
    public function findLowestSid(): ?int
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $raw = $this->wpdb->get_var("SELECT MIN(sid) FROM `{$this->table}`");
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }

    public function findLabelSr(int $sid): ?string
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $name = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT name_sr FROM `{$this->table}` WHERE sid = %d LIMIT 1",
                $sid,
            ),
        );

        if (!is_string($name) || $name === '') {
            return null;
        }

        return $name;
    }

    public function findLabelEn(int $sid): ?string
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $name = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT name_en FROM `{$this->table}` WHERE sid = %d LIMIT 1",
                $sid,
            ),
        );

        if (!is_string($name) || $name === '') {
            return null;
        }

        return $name;
    }

    /**
     * Jedinstven izvor prikaza zvaničnog naziva statusa (mejl, Moj nalog, admin): prvo šifarnik, pa snapshot samo kad nema reda.
     *
     * @param string $snapshotWhenNotInCodebook npr. tekst pre prvog notify-a (sID 0) ili istorijski zapis kad šifarnik nema sid
     */
    public function resolveOfficialShipmentStatusLabel(int $sid, string $snapshotWhenNotInCodebook = ''): string
    {
        $sr = $this->findLabelSr($sid);
        if ($sr !== null && trim($sr) !== '') {
            return trim($sr);
        }

        $en = $this->findLabelEn($sid);
        if ($en !== null && trim($en) !== '') {
            return trim($en);
        }

        $snap = trim($snapshotWhenNotInCodebook);
        if ($snap !== '') {
            return $snap;
        }

        return $this->resolveDisplayLabel($sid);
    }

    public function resolveDisplayLabel(int $sid): string
    {
        $sr = $this->findLabelSr($sid);
        if ($sr !== null && trim($sr) !== '') {
            return trim($sr);
        }

        $en = $this->findLabelEn($sid);
        if ($en !== null && trim($en) !== '') {
            return trim($en);
        }

        return sprintf(
            /* translators: %d: D Express numeric status id */
            __('Status (sID: %d)', 'dexpress-woocommerce'),
            $sid,
        );
    }
}
