<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Ajax;

use S7codedesign\DExpress\Application\Sync\SyncCentresService;
use S7codedesign\DExpress\Application\Sync\SyncDispensersService;
use S7codedesign\DExpress\Application\Sync\SyncLocationsService;
use S7codedesign\DExpress\Application\Sync\SyncMunicipalitiesService;
use S7codedesign\DExpress\Application\Sync\SyncResult;
use S7codedesign\DExpress\Application\Sync\SyncShopsService;
use S7codedesign\DExpress\Application\Sync\SyncStatusCodesService;
use S7codedesign\DExpress\Application\Sync\SyncStreetsService;
use S7codedesign\DExpress\Application\Sync\SyncTownsService;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;

final class ManualSyncController
{
    public function __construct(
        private readonly SyncTownsService          $towns,
        private readonly SyncStreetsService         $streets,
        private readonly SyncMunicipalitiesService  $municipalities,
        private readonly SyncStatusCodesService     $statusCodes,
        private readonly SyncDispensersService      $dispensers,
        private readonly SyncLocationsService       $locations,
        private readonly SyncCentresService         $centres,
        private readonly SyncShopsService           $shops,
        private readonly Logger                     $logger,
    ) {}

    public function register(): void
    {
        add_action('wp_ajax_dexpress_manual_sync', [$this, 'handle']);
    }

    public function handle(): void
    {
        check_ajax_referer('dexpress_manual_sync', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Nedovoljna prava.'], 403);
        }

        $type = sanitize_key($_POST['type'] ?? 'all');

        $result = $this->runSync($type);

        if (!$result->success) {
            $this->logger->error('Sync failed', ['type' => $type, 'error' => $result->errorMessage]);
            wp_send_json_error(['message' => $result->errorMessage]);
        }

        $this->logger->info('Sync completed', ['type' => $type, 'count' => $result->total()]);

        wp_send_json_success([
            'message' => sprintf(
                'Sinhronizovano %d zapisa.',
                $result->total(),
            ),
        ]);
    }

    private function runSync(string $type): SyncResult
    {
        return match ($type) {
            'towns'          => $this->towns->sync(),
            'streets'        => $this->streets->sync(),
            'municipalities' => $this->municipalities->sync(),
            'status_codes'   => $this->statusCodes->sync(),
            'dispensers'     => $this->dispensers->sync(),
            'locations'      => $this->locations->sync(),
            'centres'        => $this->centres->sync(),
            'shops'          => $this->shops->sync(),
            default          => $this->syncAll(),
        };
    }

    private function syncAll(): SyncResult
    {
        $total = 0;

        foreach ([
            $this->municipalities->sync(),
            $this->centres->sync(),
            $this->towns->sync(),
            $this->streets->sync(),
            $this->statusCodes->sync(),
            $this->dispensers->sync(),
            $this->locations->sync(),
            $this->shops->sync(),
        ] as $r) {
            if (!$r->success) {
                return $r;
            }
            $total += $r->total();
        }

        return SyncResult::success('all', $total);
    }
}
