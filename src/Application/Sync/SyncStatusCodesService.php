<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Sync;

use S7codedesign\DExpress\Infrastructure\Api\DExpressApiClient;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;

final class SyncStatusCodesService
{
    private const SYNC_KEY = 'sync.last_status_codes';

    public function __construct(
        private readonly DExpressApiClient   $apiClient,
        private readonly StatusCodeRepository $repository,
        private readonly OptionsRepository   $options,
    ) {}

    public function sync(): SyncResult
    {
        try {
            $rows  = $this->apiClient->get('/data/statuses');
            $count = $this->repository->upsertBatch($rows);

            $this->options->set(self::SYNC_KEY, current_time('YmdHis'));
            $this->options->save();

            return SyncResult::success('status_codes', $count);
        } catch (\Throwable $e) {
            return SyncResult::failure('status_codes', $e->getMessage());
        }
    }
}
