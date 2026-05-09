<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Sync;

use S7codedesign\DExpress\Infrastructure\Api\DExpressApiClient;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\CentreRepository;

final class SyncCentresService
{
    private const SYNC_KEY = 'sync.last_centres';

    public function __construct(
        private readonly DExpressApiClient $apiClient,
        private readonly CentreRepository  $repository,
        private readonly OptionsRepository $options,
    ) {}

    public function sync(): SyncResult
    {
        try {
            $rows  = $this->apiClient->get('/data/centres');
            $stats = $this->repository->replaceAll($rows);

            $this->options->set(self::SYNC_KEY, current_time('YmdHis'));
            $this->options->save();

            return SyncResult::success('centres', $stats);
        } catch (\Throwable $e) {
            return SyncResult::failure('centres', $e->getMessage());
        }
    }
}
