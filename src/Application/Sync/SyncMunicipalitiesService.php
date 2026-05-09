<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Sync;

use S7codedesign\DExpress\Infrastructure\Api\DExpressApiClient;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\MunicipalityRepository;

final class SyncMunicipalitiesService
{
    private const SYNC_KEY        = 'sync.last_municipalities';
    private const FIRST_SYNC_DATE = '20000101000000';

    public function __construct(
        private readonly DExpressApiClient      $apiClient,
        private readonly MunicipalityRepository $repository,
        private readonly OptionsRepository      $options,
    ) {}

    public function sync(): SyncResult
    {
        try {
            $since = $this->options->getString(self::SYNC_KEY, self::FIRST_SYNC_DATE);
            $rows  = $this->apiClient->get('/data/municipalities', ['date' => $since]);
            $stats = $this->repository->upsertBatch($rows);

            $this->options->set(self::SYNC_KEY, current_time('YmdHis'));
            $this->options->save();

            return SyncResult::success('municipalities', $stats);
        } catch (\Throwable $e) {
            return SyncResult::failure('municipalities', $e->getMessage());
        }
    }
}
