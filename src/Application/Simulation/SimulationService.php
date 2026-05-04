<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Simulation;

use S7codedesign\DExpress\Domain\Events\ShipmentCreated;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Infrastructure\Async\WebhookJobScheduler;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbWebhookLogRepository;

/**
 * TEST pošiljka → Action Scheduler koraci → INSERT webhook_logs → AS obrada → {@see \S7codedesign\DExpress\Application\Webhook\ProcessWebhookService}.
 */
final class SimulationService
{
    /** Poller uklonjen; ostaje za čišćenje starog WP-Cron-a. */
    public const LEGACY_POLL_HOOK = 'dexpress/simulate_shipments';

    /** Zastareo: WP-Cron inject; deaktivator i dalje čisti. */
    public const LEGACY_DISPATCH_HOOK = 'dexpress/simulated_webhook';

    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly WpdbWebhookLogRepository $webhookLogs,
        private readonly OptionsRepository $options,
        private readonly Logger $logger,
        private readonly WebhookJobScheduler $jobs,
    ) {}

    public function register(): void
    {
        add_action('dexpress/shipment.created', [$this, 'onShipmentCreated'], 20, 1);
        add_action(WebhookJobScheduler::HOOK_SIMULATION_INJECT, [$this, 'injectSimulationWebhookLog'], 10, 2);
    }

    public function unschedule(): void
    {
        $this->jobs->unscheduleSimulationInjects();
        wp_clear_scheduled_hook(self::LEGACY_POLL_HOOK);
        wp_clear_scheduled_hook(self::LEGACY_DISPATCH_HOOK);
    }

    public function isActive(): bool
    {
        return $this->options->getString('api.environment', 'test') === 'test'
            && (bool) $this->options->get('simulation.enabled', false);
    }

    public function onShipmentCreated(ShipmentCreated $event): void
    {
        if (!$this->isActive()) {
            return;
        }

        $shipment = $event->shipment;
        if ($shipment->apiResponse() !== 'TEST' || $shipment->id() === null) {
            return;
        }

        $base   = time();
        $shipId = (int) $shipment->id();
        $code   = $shipment->trackingCode();

        foreach ($this->timelineOffsets() as $step) {
            $at  = $base + $step['offset'];
            $sid = $step['sid'];
            $this->jobs->scheduleSimulationInject($shipId, $sid, $at);
            $this->logger->info(sprintf(
                '[SIMULATION] AS korak: shipment %d, paket %s, sID %s, ~%s',
                $shipId,
                $code,
                $sid,
                wp_date('Y-m-d H:i:s', $at),
            ));
        }
    }

    public function injectSimulationWebhookLog(int $shipmentId, mixed $sid): void
    {
        if ($this->options->getString('api.environment', 'test') !== 'test' || !$this->isActive()) {
            return;
        }

        $sid = trim((string) $sid);
        if ($sid === '') {
            return;
        }

        $shipment = $this->shipments->findById($shipmentId);
        if ($shipment === null || $shipment->apiResponse() !== 'TEST' || $shipment->id() === null) {
            return;
        }

        $packageCode    = $shipment->trackingCode();
        $notificationId = 'SIM_' . (string) $shipment->id() . '_' . $sid . '_' . uniqid('', true);

        if ($this->webhookLogs->existsByNotificationId($notificationId)) {
            return;
        }

        $raw = [
            'simulation'      => true,
            'package_code'    => $packageCode,
            'sID'             => $sid,
            'reference_id'    => $shipment->referenceId,
            'notification_id' => $notificationId,
        ];

        try {
            $logId = $this->webhookLogs->insert([
                'notification_id' => $notificationId,
                'package_code'    => $packageCode,
                'reference_id'    => $shipment->referenceId !== '' ? $shipment->referenceId : null,
                'sid'             => $sid,
                'occurred_at'     => current_time('mysql', true),
                'received_at'     => current_time('mysql', true),
                'raw_payload'     => wp_json_encode($raw, JSON_UNESCAPED_UNICODE) ?: '{}',
                'processed'       => 0,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[SIMULATION] Webhook log insert: ' . $e->getMessage());

            return;
        }

        $this->jobs->scheduleProcessWebhookLog($logId, 0);
    }

    /**
     * @return list<array{offset: int, sid: string}>
     */
    private function timelineOffsets(): array
    {
        if ($this->options->getBool('simulation.quick_timeline', true)) {
            return [
                ['offset' => 30, 'sid' => '0'],
                ['offset' => 60, 'sid' => '3'],
                ['offset' => 120, 'sid' => '4'],
                ['offset' => 180, 'sid' => '1'],
            ];
        }

        return [
            ['offset' => 120, 'sid' => '0'],
            ['offset' => 600, 'sid' => '3'],
            ['offset' => 1500, 'sid' => '4'],
            ['offset' => 2700, 'sid' => '1'],
        ];
    }
}
