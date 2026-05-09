<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Sync;

use S7codedesign\DExpress\Infrastructure\Api\DExpressApiClient;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\ShopRepository;

final class SyncShopsService
{
    private const SYNC_KEY = 'sync.last_shops';

    public function __construct(
        private readonly DExpressApiClient $apiClient,
        private readonly ShopRepository    $repository,
        private readonly OptionsRepository $options,
    ) {}

    public function sync(): SyncResult
    {
        try {
            $rows  = $this->apiClient->get('/data/shops');
            $stats = $this->repository->replaceAll($rows);

            $this->options->set(self::SYNC_KEY, current_time('YmdHis'));
            $this->options->save();

            return SyncResult::success('shops', $stats);
        } catch (\Throwable $e) {
            return SyncResult::failure('shops', $e->getMessage());
        }
    }
}
