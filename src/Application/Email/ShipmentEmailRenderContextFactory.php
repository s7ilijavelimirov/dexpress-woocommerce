<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Email;

use S7codedesign\DExpress\Domain\Shipment\Shipment;
use S7codedesign\DExpress\Domain\Shipment\StatusEmailBucket;
use S7codedesign\DExpress\Domain\Status\StatusMapper;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;

final class ShipmentEmailRenderContextFactory
{
    /** @var list<int> */
    private const EARLY_PROBLEM_SIDS = [
        5, 8, 9, 10, 11, 19, 25, 106, 107, 119, 125, 822, 841,
    ];

    public function __construct(
        private readonly StatusCodeRepository $codes,
        private readonly StatusMapper $mapper,
    ) {}

    /**
     * @param Shipment[] $shipments
     */
    public function fromShipments(array $shipments): ShipmentEmailRenderContext
    {
        $list = array_values(array_filter($shipments, static fn ($s): bool => $s instanceof Shipment));
        usort(
            $list,
            static fn (Shipment $a, Shipment $b): int => $a->splitIndex <=> $b->splitIndex,
        );

        $rows     = [];
        $isTest   = false;
        foreach ($list as $shipment) {
            $rows[] = $this->rowFromShipment($shipment);
            if ($shipment->apiResponse() === 'TEST') {
                $isTest = true;
            }
        }

        return new ShipmentEmailRenderContext($rows, $isTest);
    }

    public function forPreview(): ShipmentEmailRenderContext
    {
        $sid = $this->codes->findLowestSid() ?? 3;
        if ($this->mapper->isDelayedSid($sid)) {
            $sid = 3;
        }

        $bucket = $this->mapper->emailBucketForSid($sid);
        $label  = $this->codes->resolveOfficialShipmentStatusLabel($sid, '');

        $row = new ShipmentEmailRowContext(
            trackingCode: 'TT9999999999',
            statusLabel: $label,
            leadMessage: $this->leadMessageForBucket($bucket),
            steps: $this->buildSteps($bucket, $sid),
            showProblemBanner: $bucket === StatusEmailBucket::ProblemFailed,
        );

        return new ShipmentEmailRenderContext([$row], false);
    }

    private function rowFromShipment(Shipment $shipment): ShipmentEmailRowContext
    {
        $bucket = $shipment->emailBucket();
        $sid    = $shipment->currentSid();
        $label  = $this->codes->resolveOfficialShipmentStatusLabel($sid, $shipment->displayStatusLabel());

        return new ShipmentEmailRowContext(
            trackingCode: $shipment->trackingCode(),
            statusLabel: $label,
            leadMessage: $this->leadMessageForBucket($bucket),
            steps: $this->buildSteps($bucket, $sid),
            showProblemBanner: $bucket === StatusEmailBucket::ProblemFailed,
        );
    }

    private function leadMessageForBucket(StatusEmailBucket $bucket): string
    {
        return match ($bucket) {
            StatusEmailBucket::Delivered => __('Pošiljka je isporučena.', 'dexpress-woocommerce'),
            StatusEmailBucket::InTransit => __('Vaša pošiljka je trenutno u tranzitu.', 'dexpress-woocommerce'),
            StatusEmailBucket::OutForDelivery => __(
                'Vaša pošiljka je na isporuci — kurir je u putu ka vama.',
                'dexpress-woocommerce',
            ),
            StatusEmailBucket::ProblemFailed => __('Došlo je do problema sa isporukom.', 'dexpress-woocommerce'),
            StatusEmailBucket::Other => __('Vaša pošiljka je prijavljena kod D Express kurira.', 'dexpress-woocommerce'),
        };
    }

    /**
     * @return list<array{label: string, state: 'done'|'current'|'pending'|'problem'}>
     */
    private function buildSteps(StatusEmailBucket $bucket, int $sid): array
    {
        $labels = $this->stepLabels();

        if ($bucket === StatusEmailBucket::Delivered) {
            $steps = [];
            foreach ($labels as $label) {
                $steps[] = ['label' => $label, 'state' => 'done'];
            }

            return $steps;
        }

        if ($bucket === StatusEmailBucket::ProblemFailed) {
            $problemIdx = $this->problemProgressStepIndex($sid);
            $steps      = [];
            for ($i = 0; $i < 4; $i++) {
                if ($i < $problemIdx) {
                    $state = 'done';
                } elseif ($i === $problemIdx) {
                    $state = 'problem';
                } else {
                    $state = 'pending';
                }
                $steps[] = ['label' => $labels[$i], 'state' => $state];
            }

            return $steps;
        }

        $currentIdx = match ($bucket) {
            StatusEmailBucket::InTransit => 1,
            StatusEmailBucket::OutForDelivery => 2,
            StatusEmailBucket::Other => 0,
            default => 0,
        };

        $steps = [];
        for ($i = 0; $i < 4; $i++) {
            if ($i < $currentIdx) {
                $state = 'done';
            } elseif ($i === $currentIdx) {
                $state = 'current';
            } else {
                $state = 'pending';
            }
            $steps[] = ['label' => $labels[$i], 'state' => $state];
        }

        return $steps;
    }

    private function problemProgressStepIndex(int $sid): int
    {
        if ($sid >= 108 && $sid <= 112) {
            return 1;
        }

        if (in_array($sid, self::EARLY_PROBLEM_SIDS, true)) {
            return 1;
        }

        return 2;
    }

    /**
     * @return list<string>
     */
    private function stepLabels(): array
    {
        return [
            __('Kreirana pošiljka', 'dexpress-woocommerce'),
            __('U tranzitu', 'dexpress-woocommerce'),
            __('Na isporuci', 'dexpress-woocommerce'),
            __('Isporučeno', 'dexpress-woocommerce'),
        ];
    }
}
