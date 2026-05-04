<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Webhook;

use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbWebhookLogRepository;

final class ProcessWebhookService
{
    public function __construct(
        private readonly WpdbWebhookLogRepository $webhookLogs,
        private readonly ShipmentRepository $shipments,
        private readonly ShipmentStatusIngestionService $ingestion,
        private readonly Logger $logger,
    ) {}

    public function process(int $logId): void
    {
        $log = $this->webhookLogs->findById($logId);

        if ($log === null) {
            $this->logger->warning('[WEBHOOK] Log id ' . $logId . ' not found.');
            return;
        }

        if ((int) ($log['processed'] ?? 0) === 1) {
            return;
        }

        $packageCode = (string) ($log['package_code'] ?? '');
        $sidRaw      = (string) ($log['sid'] ?? '');

        $shipment = $this->shipments->findByPackageCode($packageCode);

        if ($shipment === null || $shipment->id() === null) {
            $this->logger->warning(
                '[WEBHOOK] Unknown package code — marking processed. Code: ' . $packageCode .
                ', rID: ' . ($log['reference_id'] ?? ''),
            );
            $this->webhookLogs->markProcessed($logId);
            return;
        }

        $occurredUtc = $this->occurredAtUtcMysql((string) ($log['occurred_at'] ?? ''));

        $this->ingestion->applyInboundStatus(
            $shipment,
            new InboundStatusNotification(
                webhookLogId: $logId,
                packageCode: $packageCode,
                sidRaw: $sidRaw,
                occurredAtUtcMysql: $occurredUtc,
            ),
        );

        $this->webhookLogs->markProcessed($logId);
    }

    private function occurredAtUtcMysql(string $storedOccurredAt): string
    {
        if ($storedOccurredAt === '') {
            return current_time('mysql', true);
        }

        return $storedOccurredAt;
    }
}
