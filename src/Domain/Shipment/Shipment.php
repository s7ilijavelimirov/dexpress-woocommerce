<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Domain\Shipment;

use S7codedesign\DExpress\Domain\Money\Grams;
use S7codedesign\DExpress\Domain\Money\Money;

final class Shipment
{
    private ?int $id;
    private StatusEmailBucket $emailBucket;
    private int $currentSid;
    private string $statusLabelSnapshot;
    private ?string $apiResponse;

    /**
     * @param Package[] $packages
     */
    private function __construct(
        ?int $id,
        public readonly int $orderId,
        public readonly string $referenceId,
        public readonly int $senderLocationId,
        StatusEmailBucket $emailBucket,
        int $currentSid,
        string $statusLabelSnapshot,
        public readonly DeliveryType $deliveryType,
        public readonly PaymentBy $paymentBy,
        public readonly PaymentType $paymentType,
        public readonly Money $declaredValue,
        public readonly Money $codAmount,
        public readonly ?string $codBankAccount,
        public readonly Grams $totalMass,
        public readonly string $content,
        public readonly string $note,
        public readonly ReturnDoc $returnDoc,
        public readonly bool $selfDropOff,
        public readonly int $splitIndex,
        public readonly int $totalSplits,
        ?string $apiResponse,
        public readonly array $packages,
        public readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id                   = $id;
        $this->emailBucket          = $emailBucket;
        $this->currentSid           = $currentSid;
        $this->statusLabelSnapshot  = $statusLabelSnapshot;
        $this->apiResponse          = $apiResponse;
    }

    /**
     * @param Package[] $packages
     */
    public static function create(
        int $orderId,
        string $referenceId,
        int $senderLocationId,
        DeliveryType $deliveryType,
        PaymentBy $paymentBy,
        PaymentType $paymentType,
        Money $declaredValue,
        Money $codAmount,
        ?string $codBankAccount,
        Grams $totalMass,
        string $content,
        string $note,
        ReturnDoc $returnDoc,
        bool $selfDropOff,
        int $splitIndex,
        int $totalSplits,
        array $packages,
        string $initialLabelSnapshot,
    ): self {
        return new self(
            id:                  null,
            orderId:             $orderId,
            referenceId:         $referenceId,
            senderLocationId:    $senderLocationId,
            emailBucket:         StatusEmailBucket::Other,
            currentSid:          0,
            statusLabelSnapshot: $initialLabelSnapshot,
            deliveryType:        $deliveryType,
            paymentBy:           $paymentBy,
            paymentType:         $paymentType,
            declaredValue:       $declaredValue,
            codAmount:           $codAmount,
            codBankAccount:      $codBankAccount,
            totalMass:           $totalMass,
            content:             $content,
            note:                $note,
            returnDoc:           $returnDoc,
            selfDropOff:         $selfDropOff,
            splitIndex:          $splitIndex,
            totalSplits:         $totalSplits,
            apiResponse:         null,
            packages:            $packages,
            createdAt:           new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    /**
     * @param Package[] $packages
     */
    public static function reconstitute(
        int $id,
        int $orderId,
        string $referenceId,
        int $senderLocationId,
        StatusEmailBucket $emailBucket,
        int $currentSid,
        string $statusLabelSnapshot,
        DeliveryType $deliveryType,
        PaymentBy $paymentBy,
        PaymentType $paymentType,
        Money $declaredValue,
        Money $codAmount,
        ?string $codBankAccount,
        Grams $totalMass,
        string $content,
        string $note,
        ReturnDoc $returnDoc,
        bool $selfDropOff,
        int $splitIndex,
        int $totalSplits,
        ?string $apiResponse,
        array $packages,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id:                  $id,
            orderId:             $orderId,
            referenceId:         $referenceId,
            senderLocationId:    $senderLocationId,
            emailBucket:         $emailBucket,
            currentSid:          $currentSid,
            statusLabelSnapshot: $statusLabelSnapshot,
            deliveryType:        $deliveryType,
            paymentBy:           $paymentBy,
            paymentType:         $paymentType,
            declaredValue:       $declaredValue,
            codAmount:           $codAmount,
            codBankAccount:      $codBankAccount,
            totalMass:           $totalMass,
            content:             $content,
            note:                $note,
            returnDoc:           $returnDoc,
            selfDropOff:         $selfDropOff,
            splitIndex:          $splitIndex,
            totalSplits:         $totalSplits,
            apiResponse:         $apiResponse,
            packages:            $packages,
            createdAt:           $createdAt,
        );
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function emailBucket(): StatusEmailBucket
    {
        return $this->emailBucket;
    }

    public function currentSid(): int
    {
        return $this->currentSid;
    }

    /**
     * Snapshot iz poslednjeg notify-a (perzistencija / istorija). Za prikaz korisniku koristiti
     * {@see \S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository::resolveOfficialShipmentStatusLabel()}.
     */
    public function displayStatusLabel(): string
    {
        return $this->statusLabelSnapshot;
    }

    /**
     * Returns a new instance with the DB-assigned ID set.
     * Called by the repository after a successful INSERT.
     */
    public function withId(int $id): self
    {
        $clone     = clone $this;
        $clone->id = $id;

        return $clone;
    }

    /**
     * @return $this
     */
    public function withApiResponse(
        string $response,
        StatusEmailBucket $bucket,
        int $currentSid,
        string $statusLabelSnapshot,
    ): self {
        $clone                     = clone $this;
        $clone->apiResponse        = $response;
        $clone->emailBucket        = $bucket;
        $clone->currentSid         = $currentSid;
        $clone->statusLabelSnapshot = $statusLabelSnapshot;

        return $clone;
    }

    public function apiResponse(): ?string
    {
        return $this->apiResponse;
    }

    public function applyPresentationFromWebhook(
        StatusEmailBucket $bucket,
        int $rawSid,
        string $labelSnapshot,
    ): void {
        $this->emailBucket         = $bucket;
        $this->currentSid          = $rawSid;
        $this->statusLabelSnapshot = $labelSnapshot;
    }

    public function trackingCode(): string
    {
        return $this->packages[0]->code->value();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function packagesToApiArray(): array
    {
        return array_map(static fn (Package $p) => $p->toApiArray(), $this->packages);
    }
}
