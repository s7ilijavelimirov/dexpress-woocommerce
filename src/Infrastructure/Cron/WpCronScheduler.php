<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Cron;

use S7codedesign\DExpress\Application\Sync\SyncCentresService;
use S7codedesign\DExpress\Application\Sync\SyncDispensersService;
use S7codedesign\DExpress\Application\Sync\SyncLocationsService;
use S7codedesign\DExpress\Application\Sync\SyncMunicipalitiesService;
use S7codedesign\DExpress\Application\Sync\SyncShopsService;
use S7codedesign\DExpress\Application\Sync\SyncStatusCodesService;
use S7codedesign\DExpress\Application\Sync\SyncStreetsService;
use S7codedesign\DExpress\Application\Sync\SyncTownsService;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbWebhookLogRepository;

/**
 * Manages WP Cron hooks for reference data sync.
 *
 * Schedule:
 *   - Daily  (night): dispensers, locations
 *   - Weekly (Sunday): streets, centres, shops, status_codes
 *   - Monthly (1st):   towns, municipalities
 */
final class WpCronScheduler
{
    private const HOOK_DAILY   = 'dexpress_cron_daily';
    private const HOOK_WEEKLY  = 'dexpress_cron_weekly';
    private const HOOK_MONTHLY = 'dexpress_cron_monthly';

    private const WEBHOOK_LOG_RETENTION_DAYS = 30;

    public function __construct(
        private readonly SyncTownsService          $towns,
        private readonly SyncStreetsService         $streets,
        private readonly SyncMunicipalitiesService  $municipalities,
        private readonly SyncStatusCodesService     $statusCodes,
        private readonly SyncDispensersService      $dispensers,
        private readonly SyncLocationsService       $locations,
        private readonly SyncCentresService         $centres,
        private readonly SyncShopsService           $shops,
        private readonly WpdbWebhookLogRepository   $webhookLogs,
        private readonly Logger                     $logger,
    ) {}

    /**
     * Registers the custom 'monthly' cron interval and hooks the cron callbacks.
     * Called on Plugin::boot().
     */
    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'addMonthlySchedule']);

        add_action(self::HOOK_DAILY, [$this, 'runDaily']);
        add_action(self::HOOK_WEEKLY, [$this, 'runWeekly']);
        add_action(self::HOOK_MONTHLY, [$this, 'runMonthly']);

        // Manual sync actions fired by ManualSyncController via do_action().
        add_action('dexpress_sync_towns', [$this->towns, 'sync']);
        add_action('dexpress_sync_streets', [$this->streets, 'sync']);
        add_action('dexpress_sync_municipalities', [$this->municipalities, 'sync']);
        add_action('dexpress_sync_status_codes', [$this->statusCodes, 'sync']);
        add_action('dexpress_sync_dispensers', [$this->dispensers, 'sync']);
        add_action('dexpress_sync_locations', [$this->locations, 'sync']);
        add_action('dexpress_sync_centres', [$this->centres, 'sync']);
        add_action('dexpress_sync_shops', [$this->shops, 'sync']);
        add_action('dexpress_sync_all', [$this, 'runAll']);
    }

    /** Schedules all three cron events. Called on plugin activation. */
    public function scheduleEvents(): void
    {
        if (!wp_next_scheduled(self::HOOK_DAILY)) {
            wp_schedule_event(strtotime('tomorrow 02:00:00'), 'daily', self::HOOK_DAILY);
        }

        if (!wp_next_scheduled(self::HOOK_WEEKLY)) {
            wp_schedule_event(strtotime('next sunday 03:00:00'), 'weekly', self::HOOK_WEEKLY);
        }

        if (!wp_next_scheduled(self::HOOK_MONTHLY)) {
            wp_schedule_event(strtotime('first day of next month 04:00:00'), 'monthly', self::HOOK_MONTHLY);
        }
    }

    /** Unschedules all cron events. Called on plugin deactivation. */
    public function unscheduleEvents(): void
    {
        foreach ([self::HOOK_DAILY, self::HOOK_WEEKLY, self::HOOK_MONTHLY] as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
        }
    }

    /** @param array<string, array<string, mixed>> $schedules */
    public function addMonthlySchedule(array $schedules): array
    {
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __('Jednom mesečno', 'dexpress-woocommerce'),
            ];
        }

        return $schedules;
    }

    public function runDaily(): void
    {
        $this->dispensers->sync();
        $this->locations->sync();
        $this->logger->purgeOldLogs();

        $cutoffTs = time() - (self::WEBHOOK_LOG_RETENTION_DAYS * DAY_IN_SECONDS);
        $cutoff   = wp_date('Y-m-d H:i:s', $cutoffTs);
        $removed  = $this->webhookLogs->deleteProcessedWhereProcessedAtBefore($cutoff);
        if ($removed > 0) {
            $this->logger->info('[WEBHOOK LOG] Obrisano obradenih zapisa starijih od ' . (string) self::WEBHOOK_LOG_RETENTION_DAYS . ' dana: ' . (string) $removed . '.');
        }
    }

    public function runWeekly(): void
    {
        $this->streets->sync();
        $this->centres->sync();
        $this->shops->sync();
        $this->statusCodes->sync();
    }

    public function runMonthly(): void
    {
        $this->towns->sync();
        $this->municipalities->sync();
    }

    public function runAll(): void
    {
        $this->runMonthly();
        $this->runWeekly();
        $this->runDaily();
    }
}
