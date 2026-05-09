<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Cron;

use S7codedesign\DExpress\Application\Sync\SyncCatalogOrder;
use S7codedesign\DExpress\Application\Sync\SyncCentresService;
use S7codedesign\DExpress\Application\Sync\SyncDispensersService;
use S7codedesign\DExpress\Application\Sync\SyncLocationsService;
use S7codedesign\DExpress\Application\Sync\SyncMunicipalitiesService;
use S7codedesign\DExpress\Application\Sync\SyncPaymentsService;
use S7codedesign\DExpress\Application\Sync\SyncResult;
use S7codedesign\DExpress\Application\Sync\SyncShopsService;
use S7codedesign\DExpress\Application\Sync\SyncStatusCodesService;
use S7codedesign\DExpress\Application\Sync\SyncStreetsService;
use S7codedesign\DExpress\Application\Sync\SyncTownsService;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbWebhookLogRepository;

/**
 * WP Cron za šifarnike — ista tri intervala (mesečno / tromesečno / polugodišnje).
 *
 * Redosled prati hijerarhiju API polja: opštine & centri → gradovi → ulice → statusni kodovi;
 * paketomati/prodavnice koriste TownID (posle gradova); lokacije isto (GET bez datuma = pun skup).
 *
 *   - Monthly:     municipalities → centres → towns → streets (+ čišćenje logova)
 *   - Daily:       dispensers, locations
 *   - Quarterly:   shops
 *   - Semi-annual: status_codes
 *
 * {@see ManualSyncController::syncAll()} ručno: isti red kao sync osim što ručni „all“ radi odjednom.
 */
final class WpCronScheduler
{
    private const HOOK_MONTHLY     = 'dexpress_cron_monthly';
    private const HOOK_DAILY       = 'dexpress_cron_daily';
    private const HOOK_QUARTERLY   = 'dexpress_cron_quarterly';
    private const HOOK_SEMI_ANNUAL = 'dexpress_cron_semi_annual';

    private const WEBHOOK_LOG_RETENTION_DAYS = 30;

    public function __construct(
        private readonly SyncTownsService          $towns,
        private readonly SyncStreetsService         $streets,
        private readonly SyncMunicipalitiesService  $municipalities,
        private readonly SyncStatusCodesService     $statusCodes,
        private readonly SyncDispensersService      $dispensers,
        private readonly SyncLocationsService       $locations,
        private readonly SyncPaymentsService        $payments,
        private readonly SyncCentresService         $centres,
        private readonly SyncShopsService           $shops,
        private readonly WpdbWebhookLogRepository   $webhookLogs,
        private readonly Logger                     $logger,
    ) {}

    /**
     * Registers custom cron intervals and hooks the cron callbacks.
     * Called on Plugin::boot().
     */
    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'addSchedules']);

        add_action(self::HOOK_MONTHLY, [$this, 'runMonthly']);
        add_action(self::HOOK_DAILY, [$this, 'runDaily']);
        add_action(self::HOOK_QUARTERLY, [$this, 'runQuarterly']);
        add_action(self::HOOK_SEMI_ANNUAL, [$this, 'runSemiAnnual']);

        // Manual sync actions fired by ManualSyncController via do_action().
        add_action('dexpress_sync_towns', [$this->towns, 'sync']);
        add_action('dexpress_sync_streets', [$this->streets, 'sync']);
        add_action('dexpress_sync_municipalities', [$this->municipalities, 'sync']);
        add_action('dexpress_sync_status_codes', [$this->statusCodes, 'sync']);
        add_action('dexpress_sync_dispensers', [$this->dispensers, 'sync']);
        add_action('dexpress_sync_locations', [$this->locations, 'sync']);
        add_action('dexpress_sync_payments', [$this->payments, 'syncKnownReferences']);
        add_action('dexpress_sync_centres', [$this->centres, 'sync']);
        add_action('dexpress_sync_shops', [$this->shops, 'sync']);
        add_action('dexpress_sync_all', [$this, 'runAll']);

        $this->scheduleEvents();
    }

    /** Schedules all cron events. Called on plugin activation. */
    public function scheduleEvents(): void
    {
        if (!wp_next_scheduled(self::HOOK_MONTHLY)) {
            wp_schedule_event(strtotime('first day of next month 04:00:00'), 'monthly', self::HOOK_MONTHLY);
        }

        if (!wp_next_scheduled(self::HOOK_DAILY)) {
            wp_schedule_event($this->nextDailyTwoAmTimestamp(), 'daily', self::HOOK_DAILY);
        }

        if (!wp_next_scheduled(self::HOOK_QUARTERLY)) {
            wp_schedule_event(strtotime('+90 days 02:00:00'), 'quarterly', self::HOOK_QUARTERLY);
        }

        if (!wp_next_scheduled(self::HOOK_SEMI_ANNUAL)) {
            wp_schedule_event(strtotime('+180 days 03:00:00'), 'semi_annual', self::HOOK_SEMI_ANNUAL);
        }
    }

    /** Unschedules all cron events. Called on plugin deactivation. */
    public function unscheduleEvents(): void
    {
        foreach ([
            self::HOOK_MONTHLY,
            self::HOOK_DAILY,
            self::HOOK_QUARTERLY,
            self::HOOK_SEMI_ANNUAL,
        ] as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
            wp_clear_scheduled_hook($hook);
        }
    }

    /** @param array<string, array<string, mixed>> $schedules */
    public function addSchedules(array $schedules): array
    {
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __('Jednom mesečno', 'dexpress-woocommerce'),
            ];
        }

        if (!isset($schedules['quarterly'])) {
            $schedules['quarterly'] = [
                'interval' => 90 * DAY_IN_SECONDS,
                'display'  => __('Na tri meseca', 'dexpress-woocommerce'),
            ];
        }

        if (!isset($schedules['semi_annual'])) {
            $schedules['semi_annual'] = [
                'interval' => 180 * DAY_IN_SECONDS,
                'display'  => __('Na šest meseci', 'dexpress-woocommerce'),
            ];
        }

        return $schedules;
    }

    public function runMonthly(): void
    {
        $hook = self::HOOK_MONTHLY;

        $this->logCronSync($hook, 'municipalities', $this->municipalities->sync());
        $this->logCronSync($hook, 'centres', $this->centres->sync());
        $this->logCronSync($hook, 'towns', $this->towns->sync());
        $this->logCronSync($hook, 'streets', $this->streets->sync());
        $this->logger->purgeOldLogs();

        $cutoffTs = time() - (self::WEBHOOK_LOG_RETENTION_DAYS * DAY_IN_SECONDS);
        $cutoff   = wp_date('Y-m-d H:i:s', $cutoffTs);
        $removed  = $this->webhookLogs->deleteProcessedWhereProcessedAtBefore($cutoff);
        if ($removed > 0) {
            $this->logger->info('[WEBHOOK LOG] Obrisano obradenih zapisa starijih od ' . (string) self::WEBHOOK_LOG_RETENTION_DAYS . ' dana: ' . (string) $removed . '.');
        }
    }

    public function runQuarterly(): void
    {
        $hook = self::HOOK_QUARTERLY;

        $this->logCronSync($hook, 'shops', $this->shops->sync());
    }

    public function runDaily(): void
    {
        $this->logCronSync(self::HOOK_DAILY, 'dispensers', $this->dispensers->sync());
        $this->logCronSync(self::HOOK_DAILY, 'locations', $this->locations->sync());
    }

    public function runSemiAnnual(): void
    {
        $this->logCronSync(self::HOOK_SEMI_ANNUAL, 'status_codes', $this->statusCodes->sync());
    }

    /**
     * Jedan pun prolaz kroz sve šifarnike u redosledu zavisnosti (isto kao ručni „sync all“).
     */
    public function runAll(): void
    {
        $hook = 'dexpress_sync_all';

        foreach (SyncCatalogOrder::ALL_SEQUENCE as $step) {
            $r = $this->syncCatalogStep($step);
            $this->logCronSync($hook, $step, $r);
            if (!$r->success) {
                break;
            }
        }
    }

    private function syncCatalogStep(string $step): SyncResult
    {
        return match ($step) {
            'municipalities' => $this->municipalities->sync(),
            'centres'        => $this->centres->sync(),
            'towns'          => $this->towns->sync(),
            'streets'        => $this->streets->sync(),
            'status_codes'   => $this->statusCodes->sync(),
            'dispensers'     => $this->dispensers->sync(),
            'locations'      => $this->locations->sync(),
            'payments'       => $this->payments->syncKnownReferences(),
            'shops'          => $this->shops->sync(),
            default          => SyncResult::failure($step, 'Nepoznat šifarnik.'),
        };
    }

    /** Samo greške u log — uspešan cron sync ne spamuje fajl (admin poruke su samo za ručni sync). */
    private function logCronSync(string $cronHook, string $entity, SyncResult $result): void
    {
        if ($result->success) {
            return;
        }

        $this->logger->error(
            'Cron sync failed',
            [
                'cron'   => $cronHook,
                'entity' => $entity,
                'error'  => $result->errorMessage,
            ],
        );
    }

    private function nextDailyTwoAmTimestamp(): int
    {
        $next = strtotime('tomorrow 02:00:00');
        if (!is_int($next) || $next <= 0) {
            return time() + DAY_IN_SECONDS;
        }

        return $next;
    }
}
