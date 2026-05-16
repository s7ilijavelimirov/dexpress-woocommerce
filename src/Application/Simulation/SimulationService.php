<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Simulation;

use S7codedesign\DExpress\Domain\Events\ShipmentCreated;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Infrastructure\Async\WebhookJobScheduler;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;
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

    /** @var list<int> Redosled sID u test simulaciji i u vizuelnim koracima mejla (šifarnik). */
    public const SIMULATION_STEP_SIDS = [0, 3, 4, 1];

    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly WpdbWebhookLogRepository $webhookLogs,
        private readonly OptionsRepository $options,
        private readonly Logger $logger,
        private readonly WebhookJobScheduler $jobs,
        private readonly StatusCodeRepository $statusCodes,
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
        return $this->resolveMode() === 'dry_run'
            && (bool) $this->options->get('simulation.enabled', false);
    }

    public function onShipmentCreated(ShipmentCreated $event): void
    {
        if (!$this->isActive()) {
            return;
        }

        $shipment = $event->shipment;
        if (!in_array($shipment->apiResponse(), ['TEST', 'DRYRUN'], true) || $shipment->id() === null) {
            return;
        }

        $base   = time();
        $shipId = (int) $shipment->id();
        $code   = $shipment->trackingCode();

        foreach ($this->timelineOffsetsForMode($this->options->getBool('simulation.quick_timeline', true)) as $step) {
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
        if (!$this->isActive()) {
            return;
        }

        $sid = trim((string) $sid);
        if ($sid === '') {
            return;
        }

        $shipment = $this->shipments->findById($shipmentId);
        if ($shipment === null || !in_array($shipment->apiResponse(), ['TEST', 'DRYRUN'], true) || $shipment->id() === null) {
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
     * Labele koraka u istom redosledu kao u simulaciji / vizuelni tok u podešavanjima (šifarnik).
     *
     * @return list<string>
     */
    public function simulationFlowStepLabels(): array
    {
        $out = [];
        foreach (self::SIMULATION_STEP_SIDS as $sid) {
            $out[] = $this->statusCodes->resolveOfficialShipmentStatusLabel($sid, '');
        }

        return $out;
    }

    /**
     * Koraci za admin prikaz: tačan offset iz rasporeda + zvanična labela iz baze.
     *
     * @return list<array{offset_seconds: int, sid: string, label: string, delay_phrase: string}>
     */
    public function buildAdminTimelinePreview(bool $quick): array
    {
        $rows = [];
        foreach ($this->timelineOffsetsForMode($quick) as $step) {
            $sid    = (int) $step['sid'];
            $offset = (int) $step['offset'];
            $rows[] = [
                'offset_seconds' => $offset,
                'sid'            => (string) $step['sid'],
                'label'          => $this->statusCodes->resolveOfficialShipmentStatusLabel($sid, ''),
                'delay_phrase'   => $this->formatSimulationOffsetPhrase($offset),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{offset: int, sid: string}>
     */
    private function timelineOffsetsForMode(bool $quick): array
    {
        if ($quick) {
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

    private function formatSimulationOffsetPhrase(int $offsetSeconds): string
    {
        if ($offsetSeconds < 60) {
            return sprintf(
                /* translators: %d: seconds after shipment creation */
                __('nakon %d s od kreiranja pošiljke', 'dexpress-woocommerce'),
                $offsetSeconds,
            );
        }

        if ($offsetSeconds % 60 === 0) {
            $minutes = (int) ($offsetSeconds / 60);

            return sprintf(
                /* translators: %d: full minutes after shipment creation */
                __('nakon %d min od kreiranja pošiljke', 'dexpress-woocommerce'),
                $minutes,
            );
        }

        return sprintf(
            /* translators: %d: seconds */
            __('nakon %d s od kreiranja pošiljke', 'dexpress-woocommerce'),
            $offsetSeconds,
        );
    }

    private function resolveMode(): string
    {
        $mode = $this->options->getString('api.mode', '');
        if ($mode !== '') {
            return $mode;
        }
        return $this->options->getString('api.environment', 'test') === 'production' ? 'live' : 'dry_run';
    }
}
