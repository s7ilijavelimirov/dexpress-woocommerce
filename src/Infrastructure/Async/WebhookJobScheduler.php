<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Async;

use S7codedesign\DExpress\Infrastructure\Logging\Logger;

/**
 * Zakazivanje obrade webhook loga preko WooCommerce Action Scheduler-a (grupa {@see AS_GROUP}).
 */
final class WebhookJobScheduler
{
    public const AS_GROUP = 'dexpress';

    public const HOOK_PROCESS_LOG = 'dexpress_process_webhook';

    public const HOOK_SIMULATION_INJECT = 'dexpress/simulation/inject';

    public function __construct(
        private readonly Logger $logger,
    ) {}

    public function scheduleProcessWebhookLog(int $logId, int $delaySeconds): void
    {
        if (!function_exists('as_schedule_single_action')) {
            $this->logger->error('[AS] Action Scheduler nedostaje — webhook log ' . $logId . ' neće biti obrađen automatski.');

            return;
        }

        $when = time() + max(0, $delaySeconds);
        as_schedule_single_action($when, self::HOOK_PROCESS_LOG, [$logId], self::AS_GROUP);
    }

    /**
     * @param positive-int $executeAt Unix timestamp
     */
    public function scheduleSimulationInject(int $shipmentId, string $sid, int $executeAt): void
    {
        if (!function_exists('as_schedule_single_action')) {
            $this->logger->error('[AS] Action Scheduler nedostaje — simulacija korak nije zakazan.');

            return;
        }

        as_schedule_single_action($executeAt, self::HOOK_SIMULATION_INJECT, [$shipmentId, $sid], self::AS_GROUP);
    }

    public function unscheduleSimulationInjects(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK_SIMULATION_INJECT);
        }
    }
}
