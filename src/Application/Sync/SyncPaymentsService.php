<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Sync;

use S7codedesign\DExpress\Infrastructure\Api\DExpressApiClient;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPaymentRepository;

final class SyncPaymentsService
{
    private const SYNC_KEY = 'sync.last_payments';
    private const KNOWN_REFS_KEY = 'payments.references';

    public function __construct(
        private readonly DExpressApiClient $apiClient,
        private readonly WpdbPaymentRepository $repository,
        private readonly OptionsRepository $options,
    ) {}

    public function syncByReference(string $paymentReference): SyncResult
    {
        $reference = trim($paymentReference);
        if ($reference === '') {
            return SyncResult::failure('payments', __('Unesite referencu uplate.', 'dexpress-woocommerce'));
        }

        try {
            $rows = $this->apiClient->viewPayments($reference);
            $stats = $this->repository->upsertByReference($reference, $rows);

            $this->rememberPaymentReference($reference);
            $this->options->set(self::SYNC_KEY, current_time('YmdHis'));
            $this->options->save();

            return SyncResult::success('payments', $stats);
        } catch (\Throwable $e) {
            return SyncResult::failure('payments', $e->getMessage());
        }
    }

    public function syncKnownReferences(): SyncResult
    {
        $references = $this->knownReferences();
        if ($references === []) {
            return SyncResult::success('payments', new RowChangeStats());
        }

        $merged = new RowChangeStats();
        foreach ($references as $reference) {
            $result = $this->syncByReference($reference);
            if (!$result->success) {
                return $result;
            }
            $merged = RowChangeStats::merge($merged, $result->changes);
        }

        $this->options->set(self::SYNC_KEY, current_time('YmdHis'));
        $this->options->save();

        return SyncResult::success('payments', $merged);
    }

    /**
     * @return list<string>
     */
    public function knownReferences(): array
    {
        $raw = (string) $this->options->getString(self::KNOWN_REFS_KEY, '');
        $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
        $refs = [];
        foreach ($parts as $part) {
            $ref = trim((string) $part);
            if ($ref !== '') {
                $refs[] = $ref;
            }
        }

        $refs = array_values(array_unique($refs));
        if ($refs !== []) {
            return $refs;
        }

        return $this->repository->latestPaymentReferences(30);
    }

    public function rememberPaymentReference(string $paymentReference): void
    {
        $reference = trim($paymentReference);
        if ($reference === '') {
            return;
        }

        $refs = $this->knownReferences();
        array_unshift($refs, $reference);
        $refs = array_slice(array_values(array_unique($refs)), 0, 50);

        $this->options->set(self::KNOWN_REFS_KEY, implode("\n", $refs));
        $this->options->save();
    }
}
