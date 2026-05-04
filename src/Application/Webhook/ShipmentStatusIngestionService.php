<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Webhook;

use S7codedesign\DExpress\Application\Shipment\ShipmentStatusOrderNoteFormatter;
use S7codedesign\DExpress\Domain\Events\StatusUpdated;
use S7codedesign\DExpress\Domain\Shipment\Shipment;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Domain\Shipment\StatusEmailBucket;
use S7codedesign\DExpress\Domain\Status\StatusMapper;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbShipmentStatusRepository;
use WC_Order;

/**
 * Jedina poslovna logika primene sID na pošiljku (istorija, terminal, beleška, hookovi).
 */
final class ShipmentStatusIngestionService
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly WpdbShipmentStatusRepository $statusHistory,
        private readonly StatusMapper $mapper,
        private readonly StatusCodeRepository $statusCodes,
        private readonly Logger $logger,
    ) {}

    public function applyInboundStatus(Shipment $shipment, InboundStatusNotification $inbound): void
    {
        if ($shipment->id() === null) {
            return;
        }

        $packageCode    = $inbound->packageCode;
        $sidRaw         = $inbound->sidRaw;
        $rawSid         = $this->parseSidInt($sidRaw);
        $bucket         = $this->mapper->emailBucketForSid($rawSid);
        $labelSnapshot  = $this->statusCodes->resolveDisplayLabel($rawSid);

        $wasTerminal = $this->mapper->isTerminalSid($shipment->currentSid());

        $this->statusHistory->insert(
            $shipment->id(),
            $rawSid,
            $bucket,
            $labelSnapshot,
            $inbound->webhookLogId,
            $inbound->occurredAtUtcMysql,
        );

        if ($wasTerminal) {
            $this->logger->info(
                '[WEBHOOK] Događaj zabeležen u istoriji; pošiljka već terminalna — bez promene statusa, beleške i hookova. '
                . 'Paket: ' . $packageCode . ', sID: ' . $sidRaw,
            );

            return;
        }

        if ($rawSid === $shipment->currentSid() && $bucket === $shipment->emailBucket()) {
            return;
        }

        $shipment->applyPresentationFromWebhook($bucket, $rawSid, $labelSnapshot);
        $this->shipments->updateStatusPresentation(
            (int) $shipment->id(),
            $bucket,
            $rawSid,
            $labelSnapshot,
        );

        $order = wc_get_order($shipment->orderId);
        if ($order instanceof WC_Order) {
            $order->add_order_note(ShipmentStatusOrderNoteFormatter::format($packageCode, $labelSnapshot, $bucket));
            $order->save();
        }

        do_action(
            'dexpress/shipment.status_updated',
            new StatusUpdated($shipment, $shipment->orderId, $bucket, $rawSid, $labelSnapshot),
        );
        do_action('dexpress_shipment_status_updated', $shipment->orderId, $shipment->id(), $bucket->value, $rawSid);
    }

    private function parseSidInt(string $sidRaw): int
    {
        return (int) trim($sidRaw);
    }
}
