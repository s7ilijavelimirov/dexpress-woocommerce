<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Sync;

use S7codedesign\DExpress\Infrastructure\Api\DExpressApiClient;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StreetRepository;

final class SyncStreetsService
{
    private const SYNC_KEY        = 'sync.last_streets';
    private const FIRST_SYNC_DATE = '20000101000000';

    public function __construct(
        private readonly DExpressApiClient $apiClient,
        private readonly StreetRepository  $repository,
        private readonly OptionsRepository $options,
    ) {}

    public function sync(): SyncResult
    {
        try {
            $since = $this->options->getString(self::SYNC_KEY, self::FIRST_SYNC_DATE);
            $rows  = $this->apiClient->get('/data/streets', ['date' => $since]);
            $count = $this->repository->upsertBatch($rows);

            $this->options->set(self::SYNC_KEY, current_time('YmdHis'));
            $this->options->save();

            return SyncResult::success('streets', $count);
        } catch (\Throwable $e) {
            return SyncResult::failure('streets', $e->getMessage());
        }
    }
}
