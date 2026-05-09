<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Ajax;

use S7codedesign\DExpress\Application\Sync\RowChangeStats;
use S7codedesign\DExpress\Application\Sync\SyncCentresService;
use S7codedesign\DExpress\Application\Sync\SyncDispensersService;
use S7codedesign\DExpress\Application\Sync\SyncLocationsService;
use S7codedesign\DExpress\Application\Sync\SyncMunicipalitiesService;
use S7codedesign\DExpress\Application\Sync\SyncPaymentsService;
use S7codedesign\DExpress\Application\Sync\SyncResult;
use S7codedesign\DExpress\Application\Sync\SyncShopsService;
use S7codedesign\DExpress\Application\Sync\SyncStatusCodesService;
use S7codedesign\DExpress\Application\Sync\SyncStreetsService;
use S7codedesign\DExpress\Application\Sync\SyncCatalogOrder;
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
        private readonly SyncPaymentsService        $payments,
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

        $c = $result->changes;
        $this->logger->info('Sync completed', [
            'type'      => $type,
            'inserted'  => $c->inserted,
            'updated'   => $c->updated,
            'unchanged' => $c->unchanged,
            'deleted'   => $c->deleted,
            'changed'   => $result->total(),
        ]);

        wp_send_json_success([
            'message'   => $this->formatSuccessMessage($result),
            'inserted'  => $c->inserted,
            'updated'   => $c->updated,
            'unchanged' => $c->unchanged,
            'deleted'   => $c->deleted,
            'changed'   => $result->total(),
        ]);
    }

    /**
     * Jedinstvena korisnička poruka za sve šifarnike (isti format brojeva).
     *
     * Za šifarnike sa punim osvežavanjem „novo“ = redovi upisani posle osvežavanja tabele.
     */
    private function formatSuccessMessage(SyncResult $result): string
    {
        $c = $result->changes;

        if ($c->inserted === 0 && $c->updated === 0 && $c->unchanged === 0) {
            return __(
                'Sinhronizacija je završena — nema novih izmena od poslednjeg preuzimanja.',
                'dexpress-woocommerce',
            );
        }

        return sprintf(
            /* translators: 1: new rows written 2: updated rows 3: unchanged rows */
            __('Sinhronizacija je uspešna. Novo: %1$d · Ažurirano: %2$d · Bez promene: %3$d.', 'dexpress-woocommerce'),
            $c->inserted,
            $c->updated,
            $c->unchanged,
        );
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
            'payments'       => $this->payments->syncKnownReferences(),
            'centres'        => $this->centres->sync(),
            'shops'          => $this->shops->sync(),
            default          => $this->syncAll(),
        };
    }

    private function syncAll(): SyncResult
    {
        $merged = new RowChangeStats();

        foreach (SyncCatalogOrder::ALL_SEQUENCE as $step) {
            $r = $this->syncOneCatalog($step);
            if (!$r->success) {
                return $r;
            }
            $merged = RowChangeStats::merge($merged, $r->changes);
        }

        return SyncResult::success('all', $merged);
    }

    private function syncOneCatalog(string $step): SyncResult
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
}
