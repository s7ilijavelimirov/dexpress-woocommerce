<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence;

use S7codedesign\DExpress\Domain\Shipment\StatusEmailBucket;
use wpdb;

final class WpdbShipmentStatusRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'dexpress_shipment_statuses';
    }

    public function insert(
        int $shipmentId,
        int $rawSid,
        StatusEmailBucket $bucket,
        string $labelSnapshot,
        ?int $webhookLogId,
        string $occurredAtMysqlUtc,
    ): void {
        $now = current_time('mysql');

        $data = [
            'shipment_id'             => $shipmentId,
            'sid'                     => $rawSid,
            'status'                  => $bucket->value,
            'status_label_snapshot'   => $labelSnapshot,
            'occurred_at'             => $occurredAtMysqlUtc,
            'created_at'              => $now,
        ];
        $formats = ['%d', '%d', '%s', '%s', '%s', '%s'];

        if ($webhookLogId !== null) {
            $data['webhook_log_id'] = $webhookLogId;
            $formats[]              = '%d';
        }

        $ok = $this->wpdb->insert($this->table, $data, $formats);

        if ($ok === false) {
            throw new \RuntimeException('Shipment status history insert failed: ' . $this->wpdb->last_error);
        }
    }
}
