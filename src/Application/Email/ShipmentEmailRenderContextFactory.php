<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Email;

use S7codedesign\DExpress\Application\Simulation\SimulationService;
use S7codedesign\DExpress\Domain\Shipment\Shipment;
use S7codedesign\DExpress\Domain\Shipment\StatusEmailBucket;
use S7codedesign\DExpress\Domain\Status\StatusMapper;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;

final class ShipmentEmailRenderContextFactory
{
    /**
     * Problem sIDs where the failure occurred before the courier collected the package
     * (at sender or pickup stage). Used by {@see problemProgressStepIndex()} to place
     * the problem marker at step 1 ("pickup") rather than step 2 ("in transit").
     *
     * sIDs 108–112 are an additional early-problem range handled by the range check
     * in problemProgressStepIndex() and are not repeated here.
     *
     * Official label for each sID is resolved at runtime from wp_dexpress_status_codes
     * (synced from GET /data/statuses). The grouping below reflects the stage at which
     * the problem is reported:
     *
     *   5         — shipment refused / problem recorded at sender before pickup
     *   8–11      — pre-collection failures (package not ready, address issue, etc.)
     *   19, 25    — early-stage problem codes (before courier handoff)
     *   106, 107  — pre-transit problem variants
     *   119, 125  — pre-transit problem variants
     *   822, 841  — extended problem codes mapped to ProblemFailed in StatusMapper;
     *               both occur before or at the point of collection
     *
     * @var list<int>
     */
    private const EARLY_PROBLEM_SIDS = [
        5,           // problem at sender before courier pickup
        8, 9, 10, 11, // pre-collection failures
        19, 25,      // early-stage problem codes
        106, 107,    // pre-transit problem variants
        119, 125,    // pre-transit problem variants
        822, 841,    // extended ProblemFailed codes occurring at/before collection
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
     * Isti sID redosled kao simulacija test webhook-a (0 → 3 → 4 → 1); nazivi isključivo iz šifarnika.
     *
     * @return list<string>
     */
    private function stepLabels(): array
    {
        return array_map(
            fn (int $sid): string => $this->codes->resolveOfficialShipmentStatusLabel($sid, ''),
            SimulationService::SIMULATION_STEP_SIDS,
        );
    }
}
