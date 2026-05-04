<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Sync;

use S7codedesign\DExpress\Infrastructure\Api\DExpressApiClient;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\LocationRepository;

final class SyncLocationsService
{
    private const SYNC_KEY = 'sync.last_locations';

    public function __construct(
        private readonly DExpressApiClient $apiClient,
        private readonly LocationRepository $repository,
        private readonly OptionsRepository  $options,
    ) {}

    public function sync(): SyncResult
    {
        try {
            $rows  = $this->apiClient->get('/data/locations');
            $count = $this->repository->replaceAll($rows);

            $this->options->set(self::SYNC_KEY, current_time('YmdHis'));
            $this->options->save();

            return SyncResult::success('locations', $count);
        } catch (\Throwable $e) {
            return SyncResult::failure('locations', $e->getMessage());
        }
    }
}
