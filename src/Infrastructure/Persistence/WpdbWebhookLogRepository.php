<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence;

use wpdb;

final class WpdbWebhookLogRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'dexpress_webhook_logs';
    }

    public function existsByNotificationId(string $notificationId): bool
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT 1 FROM `{$this->table}` WHERE notification_id = %s LIMIT 1",
            $notificationId,
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $this->wpdb->get_var($sql) !== null;
    }

    /**
     * @param array{notification_id: string, package_code: string, reference_id?: string|null, sid: string, occurred_at: string, received_at: string, raw_payload: string, processed: int} $row
     */
    public function insert(array $row): int
    {
        $ref = isset($row['reference_id']) ? (string) $row['reference_id'] : '';

        $ok = $this->wpdb->insert(
            $this->table,
            [
                'notification_id' => $row['notification_id'],
                'package_code'    => $row['package_code'],
                'reference_id'    => $ref,
                'sid'             => $row['sid'],
                'occurred_at'     => $row['occurred_at'],
                'received_at'     => $row['received_at'],
                'raw_payload'     => $row['raw_payload'],
                'processed'       => $row['processed'],
                'created_at'      => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'],
        );

        if ($ok === false) {
            throw new \RuntimeException('Webhook log insert failed: ' . $this->wpdb->last_error);
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM `{$this->table}` WHERE id = %d", $id),
            ARRAY_A,
        );

        return is_array($row) ? $row : null;
    }

    public function markProcessed(int $id): void
    {
        $now = current_time('mysql');
        $this->wpdb->update(
            $this->table,
            [
                'processed'    => 1,
                'processed_at' => $now,
            ],
            ['id' => $id],
            ['%d', '%s'],
            ['%d'],
        );
    }

    /**
     * Deletes processed webhook rows with processed_at strictly older than $cutoffMysql (site timezone, MySQL format).
     *
     * @return int Rows deleted, or 0 on failure.
     */
    public function deleteProcessedWhereProcessedAtBefore(string $cutoffMysql): int
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "DELETE FROM `{$this->table}` WHERE processed = 1 AND processed_at IS NOT NULL AND processed_at < %s",
            $cutoffMysql,
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $deleted = $this->wpdb->query($sql);

        return $deleted === false ? 0 : (int) $deleted;
    }
}
