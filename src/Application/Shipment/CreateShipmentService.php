<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Shipment;

use S7codedesign\DExpress\Domain\Events\ShipmentCreated;
use S7codedesign\DExpress\Domain\Address\PhoneNumber;
use S7codedesign\DExpress\Domain\Address\StreetNumber;
use S7codedesign\DExpress\Domain\Money\Grams;
use S7codedesign\DExpress\Domain\Money\Money;
use S7codedesign\DExpress\Domain\Shipment\Package;
use S7codedesign\DExpress\Domain\Shipment\PaymentBy;
use S7codedesign\DExpress\Domain\Shipment\Shipment;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Domain\Shipment\StatusEmailBucket;
use S7codedesign\DExpress\Infrastructure\Api\DExpressApiClient;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\TransactionRunner;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPackageItemRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPackageRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbSenderLocationRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbShipmentItemRepository;
use S7codedesign\DExpress\Application\Address\RecipientAddressCheckService;

final class CreateShipmentService
{
    private const PACKAGE_SHOP_METHOD_ID = 'dexpress_package_shop';
    private const PACKAGE_SHOP_LOCATION_TYPE_DISPENSER = '2';
    private const PACKAGE_SHOP_MAX_BUYOUT_PARA = 20_000_000;
    private const PACKAGE_SHOP_MAX_WEIGHT_GRAMS = 20_000;
    private const PACKAGE_SHOP_MAX_DIM_X_MM = 470;
    private const PACKAGE_SHOP_MAX_DIM_Y_MM = 440;
    private const PACKAGE_SHOP_MAX_DIM_Z_MM = 440;

    public function __construct(
        private readonly ShipmentRepository           $shipmentRepo,
        private readonly WpdbSenderLocationRepository $locationRepo,
        private readonly DExpressApiClient            $apiClient,
        private readonly OptionsRepository            $options,
        private readonly TransactionRunner            $tx,
        private readonly OrderRecipientResolver       $recipientResolver,
        private readonly WpdbPackageRepository        $packageRepo,
        private readonly WpdbPackageItemRepository     $packageItemRepo,
        private readonly WpdbShipmentItemRepository  $shipmentItemRepo,
        private readonly Logger                       $logger,
        private readonly RecipientAddressCheckService $addressCheck,
        private readonly ShipmentCodeAllocator        $codeAllocator,
    ) {}

    /**
     * @throws \RuntimeException on validation failure, DB error, or API error
     */
    public function execute(CreateShipmentRequest $req): CreateShipmentResult
    {
        $saved = $this->save($req);
        $shipmentId = (int) $saved->shipment->id();
        if ($shipmentId <= 0) {
            throw new \RuntimeException('Pošiljka nije sačuvana ispravno.');
        }

        return $this->send($shipmentId);
    }

    public function save(CreateShipmentRequest $req): CreateShipmentResult
    {
        $this->logger->info('[CREATE SHIPMENT TRACE] save() called for order ' . $req->orderId);

        $order = wc_get_order($req->orderId);
        if (!$order instanceof \WC_Order) {
            throw new \RuntimeException('Narudžbina nije pronađena.');
        }

        $isPackageShopOrder = $this->orderUsesPackageShopShipping($order);
        $selectedLocationId = $isPackageShopOrder
            ? absint((string) $order->get_meta('_dexpress_package_shop_location_id'))
            : 0;
        $selectedLocationType = $isPackageShopOrder
            ? trim((string) ($order->get_meta('_dexpress_package_shop_location_type') ?: self::PACKAGE_SHOP_LOCATION_TYPE_DISPENSER))
            : '';
        $isDispenserSelection = $isPackageShopOrder && $selectedLocationType === self::PACKAGE_SHOP_LOCATION_TYPE_DISPENSER;

        if ($isPackageShopOrder && $selectedLocationId <= 0) {
            $this->throwPackageShopConstraint($order->get_id(), 'Paket Shop dostava nije moguća: lokacija nije odabrana na porudžbini.');
        }
        if ($isDispenserSelection && $selectedLocationId <= 0) {
            $this->throwPackageShopConstraint($order->get_id(), 'Paketomat dostava nije moguća: paketomat nije odabran na porudžbini.');
        }

        $existingForOrder = $this->shipmentRepo->findByOrderId($req->orderId);
        if ($existingForOrder !== []) {
            throw new \RuntimeException('Za ovu narudžbinu već postoji D Express pošiljka.');
        }

        $location = $this->locationRepo->findById($req->senderLocationId);
        if ($location === null) {
            throw new \RuntimeException('Lokacija pošiljaoca nije pronađena.');
        }

        $prefix     = $this->options->getString('shipment.prefix');
        $rangeStart = (int) $this->options->get('shipment.range_start', 0);
        $rangeEnd   = (int) $this->options->get('shipment.range_end', 0);
        if ($prefix === '' || $rangeStart <= 0 || $rangeEnd <= 0 || $rangeEnd < $rangeStart) {
            throw new \RuntimeException('Opseg kodova pošiljaka nije konfigurisan. Proverite podešavanja.');
        }

        $recipient = $this->recipientResolver->resolve($order);
        $rName        = $recipient['name'];
        $rAddress     = $recipient['street'];
        $rAddressNum  = $recipient['street_number'];
        $rAddressDesc = $recipient['address_desc'];
        $rTownId      = $recipient['town_id'];
        $recipientContactPhone = $recipient['phone'];
        if ($isPackageShopOrder) {
            [
                'name' => $rName,
                'street' => $rAddress,
                'street_number' => $rAddressNum,
                'address_desc' => $rAddressDesc,
                'town_id' => $rTownId,
            ] = $this->resolvePackageShopRecipientAddress($order);
        }
        if ($recipientContactPhone === '') {
            $raw = (string) ($order->get_meta('_billing_phone_api_format') ?: $order->get_billing_phone());
            throw new \RuntimeException('Telefon primaoca nije validan srpski broj ("' . $raw . '").');
        }
        $this->validateRecipient($rName, $rAddress, $rAddressNum, $rTownId, $recipientContactPhone);

        $isCod = $order->get_payment_method() === 'cod';
        $codAmount = $isCod ? Money::fromRsd((float) $order->get_total()) : Money::fromRsd(0.0);
        $bankAccount = ($location['bank_account'] ?? '') !== '' ? (string) $location['bank_account'] : null;
        if ($codAmount->toPara() > 0 && $bankAccount !== null) {
            $this->validateBankAccount($bankAccount);
        }

        $declaredValue = $this->calculateDeclaredValue($order);
        $pkgDataList = !empty($req->packages)
            ? $req->packages
            : [[
                'mass'    => max(1, (int) $order->get_meta('_dexpress_total_mass')),
                'dim_x'   => null,
                'dim_y'   => null,
                'dim_z'   => null,
                'content' => null,
                'items'   => [],
            ]];
        $totalMassGrams = (int) array_sum(array_column($pkgDataList, 'mass'));
        $totalMass      = Grams::fromGrams(max(1, $totalMassGrams));
        if ($isDispenserSelection) {
            $this->validatePackageShopShipmentConstraints(
                orderId: $order->get_id(),
                packageDataList: $pkgDataList,
                codAmount: $codAmount,
                recipientPhone: $recipientContactPhone,
                returnDocValue: $req->returnDoc->value,
                totalMass: $totalMass,
            );
        }

        $orderRef = $this->shipmentReferenceId($order);
        $allocator = $this->codeAllocator;
        $shipment = $this->tx->run(function () use (
            $req, $orderRef, $pkgDataList, $bankAccount, $allocator, $codAmount, $declaredValue, $totalMass
        ): Shipment {
            $packages = [];
            $pkgCode = null;
            foreach ($pkgDataList as $i => $pkgData) {
                $pkgCode = $allocator->generateNextCode($pkgCode);
                $pkgIndex = $i + 1;
                $note = isset($pkgData['content']) ? trim((string) $pkgData['content']) : '';
                $contentNote = $note !== '' ? mb_substr($note, 0, 50) : null;
                $packages[] = new Package(
                    code:        $pkgCode,
                    ordinal:     $pkgIndex,
                    mass:        $pkgData['mass'] > 0 ? Grams::fromGrams((int) $pkgData['mass']) : null,
                    dimX:        $this->nullablePositiveInt($pkgData['dim_x'] ?? null),
                    dimY:        $this->nullablePositiveInt($pkgData['dim_y'] ?? null),
                    dimZ:        $this->nullablePositiveInt($pkgData['dim_z'] ?? null),
                    referenceId: $this->packageReferenceId($req->orderId, $pkgIndex),
                    contentNote: $contentNote,
                );
            }

            $shipment = Shipment::create(
                orderId:          $req->orderId,
                referenceId:      $orderRef,
                senderLocationId: $req->senderLocationId,
                deliveryType:     $req->deliveryType,
                paymentBy:        PaymentBy::Sender,
                paymentType:      $req->paymentType,
                declaredValue:    $declaredValue,
                codAmount:        $codAmount,
                codBankAccount:   $bankAccount,
                totalMass:        $totalMass,
                content:          $req->content,
                note:             $req->note,
                returnDoc:        $req->returnDoc,
                selfDropOff:      $req->selfDropOff,
                splitIndex:       1,
                totalSplits:      1,
                packages:         $packages,
                initialLabelSnapshot: __('Pošiljka čeka slanje u D-Express.', 'dexpress-woocommerce'),
            );

            return $this->shipmentRepo->save($shipment);
        });

        try {
            $this->persistPackageAllocations($shipment, $pkgDataList);
        } catch (\Throwable $e) {
            $this->shipmentRepo->deleteById((int) $shipment->id());
            throw new \RuntimeException(
                'Čuvanje stavki po paketima nije uspelo. Pokušajte ponovo. ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $trackingCode = $shipment->trackingCode();
        $order->update_meta_data('_dexpress_last_tracking_code', $trackingCode);
        $order->add_order_note(
            __('D Express: Nalepnica kreirana, pošiljka čeka slanje u D-Express.', 'dexpress-woocommerce'),
        );
        $order->save();

        return new CreateShipmentResult($shipment);
    }

    public function updatePendingShipment(int $shipmentId, CreateShipmentRequest $req): CreateShipmentResult
    {
        $existing = $this->shipmentRepo->findById($shipmentId);
        if (!$existing instanceof Shipment) {
            throw new \RuntimeException('Pošiljka nije pronađena.');
        }
        if ($this->shipmentRepo->getSendStatus($shipmentId) !== 'pending_send') {
            throw new \RuntimeException('Moguće je menjati samo pošiljku koja čeka slanje.');
        }
        if ($existing->orderId !== $req->orderId) {
            throw new \RuntimeException('Pošiljka ne pripada zadatoj narudžbini.');
        }

        $order = wc_get_order($req->orderId);
        if (!$order instanceof \WC_Order) {
            throw new \RuntimeException('Narudžbina nije pronađena.');
        }

        $location = $this->locationRepo->findById($req->senderLocationId);
        if ($location === null) {
            throw new \RuntimeException('Lokacija pošiljaoca nije pronađena.');
        }

        $recipient = $this->recipientResolver->resolve($order);
        $recipientContactPhone = $recipient['phone'];
        if ($recipientContactPhone === '') {
            $raw = (string) ($order->get_meta('_billing_phone_api_format') ?: $order->get_billing_phone());
            throw new \RuntimeException('Telefon primaoca nije validan srpski broj ("' . $raw . '").');
        }

        $isCod = $order->get_payment_method() === 'cod';
        $codAmount = $isCod ? Money::fromRsd((float) $order->get_total()) : Money::fromRsd(0.0);
        $bankAccount = ($location['bank_account'] ?? '') !== '' ? (string) $location['bank_account'] : null;
        if ($codAmount->toPara() > 0 && $bankAccount !== null) {
            $this->validateBankAccount($bankAccount);
        }

        $pkgDataList = !empty($req->packages) ? $req->packages : [];
        if ($pkgDataList === []) {
            throw new \RuntimeException('Pošiljka mora imati najmanje jedan paket.');
        }
        if (count($pkgDataList) !== count($existing->packages)) {
            throw new \RuntimeException('Broj paketa ne može da se menja u režimu izmene. Zadržite isti broj paketa.');
        }

        $totalMassGrams = (int) array_sum(array_column($pkgDataList, 'mass'));
        $totalMass = Grams::fromGrams(max(1, $totalMassGrams));
        $declaredValue = $this->calculateDeclaredValue($order);

        $updatedPackages = [];
        foreach ($pkgDataList as $i => $pkgData) {
            $existingPackage = $existing->packages[$i] ?? null;
            if (!$existingPackage instanceof Package) {
                throw new \RuntimeException('Podaci paketa nisu validni za izmenu.');
            }
            $note = isset($pkgData['content']) ? trim((string) $pkgData['content']) : '';
            $contentNote = $note !== '' ? mb_substr($note, 0, 50) : null;
            $updatedPackages[] = new Package(
                code:        $existingPackage->code,
                ordinal:     $existingPackage->ordinal,
                mass:        $pkgData['mass'] > 0 ? Grams::fromGrams((int) $pkgData['mass']) : null,
                dimX:        $this->nullablePositiveInt($pkgData['dim_x'] ?? null),
                dimY:        $this->nullablePositiveInt($pkgData['dim_y'] ?? null),
                dimZ:        $this->nullablePositiveInt($pkgData['dim_z'] ?? null),
                referenceId: $existingPackage->referenceId,
                contentNote: $contentNote,
                id:          $existingPackage->id,
            );
        }

        $updatedShipment = Shipment::reconstitute(
            id:                  (int) $existing->id(),
            orderId:             $existing->orderId,
            referenceId:         $existing->referenceId,
            senderLocationId:    $req->senderLocationId,
            emailBucket:         $existing->emailBucket(),
            currentSid:          $existing->currentSid(),
            statusLabelSnapshot: $existing->displayStatusLabel(),
            deliveryType:        $req->deliveryType,
            paymentBy:           PaymentBy::Sender,
            paymentType:         $req->paymentType,
            declaredValue:       $declaredValue,
            codAmount:           $codAmount,
            codBankAccount:      $bankAccount,
            totalMass:           $totalMass,
            content:             $req->content,
            note:                $req->note,
            returnDoc:           $req->returnDoc,
            selfDropOff:         $req->selfDropOff,
            splitIndex:          $existing->splitIndex,
            totalSplits:         $existing->totalSplits,
            apiResponse:         $existing->apiResponse(),
            packages:            $updatedPackages,
            createdAt:           $existing->createdAt,
        );

        $this->tx->run(function () use ($updatedShipment): void {
            $this->shipmentRepo->updateDraftData($updatedShipment);
            $sid = (int) $updatedShipment->id();
            foreach ($updatedShipment->packages as $package) {
                $this->packageRepo->updateForShipment($package, $sid);
            }
        });

        $hasAnyItemAllocations = false;
        foreach ($pkgDataList as $pkgData) {
            if (!empty($pkgData['items']) && is_array($pkgData['items'])) {
                $hasAnyItemAllocations = true;
                break;
            }
        }
        if ($hasAnyItemAllocations) {
            $this->persistPackageAllocations($updatedShipment, $pkgDataList, true);
        }

        $order->add_order_note(
            __('D Express: Pošiljka je izmenjena i nalepnica je ponovo odštampana (isti TT kod).', 'dexpress-woocommerce'),
        );
        $order->save();

        return new CreateShipmentResult($updatedShipment);
    }

    public function send(int $shipmentId): CreateShipmentResult
    {
        $shipment = $this->shipmentRepo->findById($shipmentId);
        if (!$shipment instanceof Shipment) {
            throw new \RuntimeException('Pošiljka nije pronađena.');
        }
        if ($this->shipmentRepo->getSendStatus($shipmentId) !== 'pending_send') {
            throw new \RuntimeException('Pošiljka nije u stanju čekanja za slanje.');
        }

        $order = wc_get_order($shipment->orderId);
        if (!$order instanceof \WC_Order) {
            throw new \RuntimeException('Narudžbina nije pronađena.');
        }

        $location = $this->locationRepo->findById($shipment->senderLocationId);
        if ($location === null) {
            throw new \RuntimeException('Lokacija pošiljaoca nije pronađena.');
        }
        $bankAccount = ($location['bank_account'] ?? '') !== '' ? (string) $location['bank_account'] : null;
        if ($shipment->codAmount->toPara() > 0 && $bankAccount === null) {
            throw new \RuntimeException(
                'Pošiljka ima otkupninu ali lokacija pošiljaoca nema podešen tekući račun. Dodajte broj računa u Podešavanja → Lokacije pošiljaoca.'
            );
        }
        if ($shipment->codAmount->toPara() > 0 && $bankAccount !== null) {
            $this->validateBankAccount($bankAccount);
        }

        $clientId = trim($this->options->getString('api.client_id'));
        if ($clientId === '') {
            throw new \RuntimeException('ID klijenta (CClientID) nije konfigurisan. Unesite vrednost u podešavanjima.');
        }

        $isPackageShopOrder = $this->orderUsesPackageShopShipping($order);
        $selectedLocationId = $isPackageShopOrder
            ? absint((string) $order->get_meta('_dexpress_package_shop_location_id'))
            : 0;
        $selectedLocationType = $isPackageShopOrder
            ? trim((string) ($order->get_meta('_dexpress_package_shop_location_type') ?: self::PACKAGE_SHOP_LOCATION_TYPE_DISPENSER))
            : '';
        $isDispenserSelection = $isPackageShopOrder && $selectedLocationType === self::PACKAGE_SHOP_LOCATION_TYPE_DISPENSER;
        $dispenserId = $isDispenserSelection ? $selectedLocationId : 0;

        $recipient = $this->recipientResolver->resolve($order);
        $rName        = $recipient['name'];
        $rAddress     = $recipient['street'];
        $rAddressNum  = $recipient['street_number'];
        $rAddressDesc = $recipient['address_desc'];
        $rTownId      = $recipient['town_id'];
        $recipientContactName  = $recipient['name'];
        $recipientContactPhone = $recipient['phone'];
        if ($isPackageShopOrder) {
            [
                'name' => $rName,
                'street' => $rAddress,
                'street_number' => $rAddressNum,
                'address_desc' => $rAddressDesc,
                'town_id' => $rTownId,
            ] = $this->resolvePackageShopRecipientAddress($order);
        }

        $payload = $this->buildApiPayload(
            $shipment,
            $location,
            $clientId,
            $rName,
            $rAddress,
            $rAddressNum,
            $rAddressDesc,
            $rTownId,
            $recipientContactName,
            $recipientContactPhone,
            $dispenserId,
            $bankAccount,
        );

        $configuredEnv = $this->options->getString('api.environment', 'test') === 'production' ? 'PRODUCTION' : 'TEST';
        $this->logger->info($this->formatCreateShipmentLog($shipment->orderId, $configuredEnv, $payload));

        try {
            $apiResponse = $this->apiClient->addShipment($payload);
        } catch (\Throwable $e) {
            $this->logger->error(implode("\n", [
                '[ERROR - CREATE SHIPMENT]',
                'Order: ' . $shipment->orderId,
                'Message: ' . $e->getMessage(),
            ]));
            throw new \RuntimeException('Pošiljka nije poslata D Express API-ju. ' . $e->getMessage());
        }

        $shipment = $shipment->withApiResponse(
            $apiResponse,
            StatusEmailBucket::Other,
            0,
            $this->pendingStatusDisplayLabel(),
        );
        $this->shipmentRepo->save($shipment);
        $this->shipmentRepo->setSendStatus($shipmentId, 'sent');

        $trackingCode = $shipment->trackingCode();
        $this->logger->info("[API RESPONSE]\n" . $apiResponse);
        $this->logger->info("[RESULT]\nTracking code: " . $trackingCode);

        do_action('dexpress/shipment.created', new ShipmentCreated($shipment, $shipment->orderId));

        $order->update_meta_data('_dexpress_last_tracking_code', $trackingCode);
        $order->add_order_note(
            sprintf(
                __('D Express: Pošiljka je poslata u D-Express. Kod za praćenje: %s.', 'dexpress-woocommerce'),
                $trackingCode,
            ),
        );
        if ($isPackageShopOrder) {
            $order->add_order_note($this->buildPackageShopShipmentOrderNote($order, $trackingCode));
        }
        $order->save();

        return new CreateShipmentResult($shipment);
    }

    /**
     * @param array<int, array<string, mixed>> $pkgDataList
     */
    private function persistPackageAllocations(Shipment $shipment, array $pkgDataList, bool $replaceExisting = false): void
    {
        $sid = $shipment->id();
        if ($sid === null || $sid <= 0) {
            throw new \RuntimeException('Pošiljka nema ID — čuvanje stavki paketa nije moguće.');
        }

        $this->tx->run(function () use ($sid, $pkgDataList, $replaceExisting): void {
            if ($replaceExisting) {
                $this->packageItemRepo->deleteByShipmentId($sid);
            }
            $saved = $this->packageRepo->findByShipmentId($sid);
            if (count($saved) !== count($pkgDataList)) {
                throw new \RuntimeException('Broj sačuvanih paketa ne odgovara podacima čarobnjaka.');
            }

            foreach ($saved as $i => $pkgRow) {
                $pkgId = $pkgRow->id;
                if ($pkgId === null || $pkgId <= 0) {
                    throw new \RuntimeException('Paket nema validan ID.');
                }

                foreach (($pkgDataList[$i]['items'] ?? []) as $row) {
                    $oid = (int) ($row['order_item_id'] ?? 0);
                    $qty = (int) ($row['qty'] ?? 0);
                    if ($oid <= 0 || $qty <= 0) {
                        continue;
                    }

                    $this->packageItemRepo->insert($pkgId, $oid, $qty);
                }
            }

            $this->shipmentItemRepo->replaceAggregatedFromPackageItems($sid);
        });
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Tekst dok D Express ne pošalje sID preko obaveštenja (nije zvaničan naziv iz šifarnika).
     */
    private function pendingStatusDisplayLabel(): string
    {
        return __(
            'Pošiljka prijavljena D Express-u — status sledi iz obaveštenja.',
            'dexpress-woocommerce',
        );
    }

    /**
     * Shipment-level ReferenceID for addShipment (one shipment per WC order).
     */
    private function shipmentReferenceId(\WC_Order $order): string
    {
        return substr((string) $order->get_id(), 0, 50);
    }

    /**
     * Per-package ReferenceID: `{order_id}_PKG_{index}` (max 50 chars).
     */
    private function packageReferenceId(int $orderId, int $packageIndex): string
    {
        return substr((string) $orderId . '_PKG_' . (string) $packageIndex, 0, 50);
    }

    /**
     * @throws \RuntimeException when required recipient fields are missing
     */
    private function validateRecipient(
        string $name,
        string $address,
        string $addressNum,
        int    $townId,
        string $phone,
    ): void {
        if (trim($name) === '') {
            throw new \RuntimeException('Ime primaoca nije popunjeno na narudžbini.');
        }

        if (trim($address) === '') {
            throw new \RuntimeException(
                'Ulica primaoca nije pronađena. Proverite da je kupac koristio D Express checkout polja.'
            );
        }

        if (trim($addressNum) === '') {
            throw new \RuntimeException('Kućni broj primaoca nije popunjen na narudžbini.');
        }

        if ($townId <= 0) {
            throw new \RuntimeException(
                'Grad primaoca nije izabran iz šifarnika. Proverite D Express checkout polja.'
            );
        }

        if (trim($phone) === '') {
            throw new \RuntimeException('Telefon primaoca nije popunjen na narudžbini.');
        }
    }

    /**
     * Validates that the stored bank account can be sent to D Express.
     * Accepts any Serbian account that resolves to 11–18 digits.
     *
     * @throws \RuntimeException
     */
    private function validateBankAccount(string $account): void
    {
        $digits = (string) preg_replace('/\D/', '', $account);
        $len    = strlen($digits);

        if ($len < 11 || $len > 18) {
            throw new \RuntimeException('Neispravan broj računa za otkupninu.');
        }
    }

    /**
     * Formats the bank account to XXX-XXXXXXXX...-XX before sending to API.
     * Accepts 11–18 digit strings (dashes/spaces already stripped upstream).
     */
    private function formatBankAccount(string $account): string
    {
        $digits  = (string) preg_replace('/\D/', '', $account);
        $len     = strlen($digits);
        $bank    = substr($digits, 0, 3);
        $control = substr($digits, -2);
        $middle  = substr($digits, 3, $len - 5);

        return $bank . '-' . $middle . '-' . $control;
    }

    /**
     * Declared value = sum of item totals (after discount) + item taxes.
     * get_total() on WC_Order_Item_Product is always pre-tax internally;
     * get_total_tax() is the corresponding tax — together they equal the
     * invoice line value shown to the customer.
     */
    private function calculateDeclaredValue(\WC_Order $order): Money
    {
        $total = 0.0;

        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $total += (float) $item->get_total() + (float) $item->get_total_tax();
        }

        return Money::fromRsd(max(0.0, $total));
    }

    /**
     * Builds the exact payload for the D Express addShipment API endpoint.
     *
     * @param array<string, mixed> $location
     * @return array<string, mixed>
     */
    private function buildApiPayload(
        Shipment $shipment,
        array    $location,
        string   $clientId,
        string   $rName,
        string   $rAddress,
        string   $rAddressNum,
        string   $rAddressDesc,
        int      $rTownId,
        string   $recipientContactName,
        string   $recipientContactPhone,
        int      $dispenserId,
        ?string  $buyOutBankAccount,
    ): array {
        $clientIdVal = is_numeric($clientId) ? (int) $clientId : $clientId;

        $payload = [
            // ---- Payer ----
            'CClientID'   => $clientIdVal,
            'CName'       => $location['name'],
            'CAddress'    => $location['street_name'],
            'CAddressNum' => $location['street_number'],
            'CTownID'     => (int) $location['town_id'],
            'CCName'      => $location['contact_name'],
            'CCPhone'     => $location['contact_phone'],

            // ---- Pickup (same as payer) ----
            'PuClientID'    => $clientIdVal,
            'PuName'        => $location['name'],
            'PuAddress'     => $location['street_name'],
            'PuAddressNum'  => $location['street_number'],
            'PuAddressDesc' => $location['address_desc'] ?? '',
            'PuTownID'      => (int) $location['town_id'],
            'PuCName'       => $location['contact_name'],
            'PuCPhone'      => $location['contact_phone'],

            // ---- Recipient ----
            'RClientID'    => '',
            'RName'        => $rName,
            'RAddress'     => $rAddress,
            'RAddressNum'  => $rAddressNum,
            'RAddressDesc' => $rAddressDesc,
            'RTownID'      => $rTownId,
            'RCName'       => $recipientContactName,
            'RCPhone'      => $recipientContactPhone,

            // ---- Shipment ----
            'DlTypeID'    => $shipment->deliveryType->value,
            'PaymentBy'   => $shipment->paymentBy->value,
            'PaymentType' => $shipment->paymentType->value,
            'Value'       => $shipment->declaredValue->toPara(),
            // Jedno obavezno polje za ceo shipment; spajamo korak 3 + opcione napomene po paketu (max 50, regex API).
            'Content'     => $this->buildShipmentContentForApi($shipment),
            'Mass'        => $shipment->totalMass->value(),
            'Note'        => $shipment->note,
            'ReferenceID' => $shipment->referenceId,
            'ReturnDoc'   => $shipment->returnDoc->value,
            'SelfDropOff' => $shipment->selfDropOff ? 1 : 0,

            // ---- COD ----
            'BuyOut'        => $shipment->codAmount->toPara(),
            'BuyOutFor'     => 0,
            'BuyOutAccount' => null,

            // ---- Dispenser ----
            'DispenserID' => null,

            // ---- Packages ----
            'PackageList' => $shipment->packagesToApiArray(),
        ];

        if ($shipment->codAmount->toPara() > 0 && $buyOutBankAccount !== null && trim($buyOutBankAccount) !== '') {
            $payload['BuyOutAccount'] = $this->formatBankAccount($buyOutBankAccount);
        } else {
            unset($payload['BuyOutAccount']);
        }

        if ($dispenserId > 0) {
            // Paketomat constraints (Phase 2B-3 validation will enforce):
            // 1) Only 1 package is allowed.
            // 2) BuyOut must be < 20,000,000 para (200,000.00 RSD).
            // 3) Recipient phone must be a mobile number.
            // 4) ReturnDoc must be 0.
            // 5) Weight must be < 20kg.
            // 6) Dimensions must be < 470x440x440 mm.
            $payload['DispenserID'] = $dispenserId;
        } else {
            unset($payload['DispenserID']);
        }

        return $payload;
    }

    /**
     * D Express očekuje jedan {@see Content} za celu pošiljku (max 50 znakova).
     * Opcioni {@see Package::contentNote} po paketu logički spajamo ovde — na nalepnici i u DB ostaju odvojeno.
     */
    private function buildShipmentContentForApi(Shipment $shipment): string
    {
        $segments = [];

        $main = trim($shipment->content);
        if ($main !== '') {
            $segments[] = $main;
        }

        foreach ($shipment->packages as $pkg) {
            if ($pkg->contentNote === null) {
                continue;
            }
            $note = trim($pkg->contentNote);
            if ($note === '') {
                continue;
            }
            $segments[] = 'P' . (string) $pkg->ordinal . ' ' . $note;
        }

        $merged = implode(' | ', $segments);

        return $this->sanitizeApiContentDescription($merged);
    }

    /**
     * @return array{
     *   name: string,
     *   street: string,
     *   street_number: string,
     *   address_desc: string,
     *   town_id: int
     * }
     */
    private function resolvePackageShopRecipientAddress(\WC_Order $order): array
    {
        $locationName = trim((string) $order->get_meta('_dexpress_package_shop_location_name'));
        $locationAddressRaw = trim((string) $order->get_meta('_dexpress_package_shop_location_address'));
        $locationTownId = absint((string) $order->get_meta('_dexpress_package_shop_location_town_id'));

        [$street, $streetNumber] = $this->splitStreetAndNumber($locationAddressRaw);

        if ($street === '') {
            $this->throwPackageShopConstraint($order->get_id(), 'Paket Shop dostava nije moguća: adresa odabrane lokacije nije dostupna.');
        }

        if ($locationTownId <= 0) {
            $this->throwPackageShopConstraint(
                $order->get_id(),
                'Nije moguće kreirati pošiljku: nedostaje town ID za izabranu lokaciju. Pokušajte ponovo ili kontaktirajte podršku.',
            );
        }

        $recipientName = $locationName !== '' ? $locationName : __('Paket Shop lokacija', 'dexpress-woocommerce');
        // For Paket Shop / Paketomat shipments RAddressDesc must stay empty.
        $addressDesc = '';

        return [
            'name' => $recipientName,
            'street' => $street,
            'street_number' => $streetNumber,
            'address_desc' => $addressDesc,
            'town_id' => $locationTownId,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitStreetAndNumber(string $fullAddress): array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $fullAddress));
        if ($normalized === '') {
            return ['', 'bb'];
        }

        $parts = preg_split('/\s+/u', $normalized);
        if (!is_array($parts) || $parts === []) {
            return [$normalized, 'bb'];
        }

        $lastToken = (string) end($parts);
        if ($lastToken !== '' && count($parts) > 1) {
            try {
                $validStreetNumber = StreetNumber::fromString($lastToken)->value();
                array_pop($parts);
                $street = trim(implode(' ', $parts));
                if ($street !== '') {
                    return [$street, $validStreetNumber];
                }
            } catch (\InvalidArgumentException) {
            }
        }

        return [$normalized, 'bb'];
    }

    private function buildPackageShopShipmentOrderNote(\WC_Order $order, string $trackingCode): string
    {
        $locationType = trim((string) ($order->get_meta('_dexpress_package_shop_location_type_label') ?: __('Paket Shop', 'dexpress-woocommerce')));
        $locationName = trim((string) $order->get_meta('_dexpress_package_shop_location_name'));
        $locationAddress = trim((string) $order->get_meta('_dexpress_package_shop_location_address'));
        $locationCity = trim((string) $order->get_meta('_dexpress_package_shop_location_city'));
        $locationLine = trim($locationAddress . ($locationCity !== '' ? ', ' . $locationCity : ''));

        return sprintf(
            /* translators: 1: location type, 2: location name, 3: location address/city, 4: tracking code */
            __('D Express %1$s: Pošiljka je usmerena na lokaciju "%2$s" (%3$s). Kod za praćenje: %4$s.', 'dexpress-woocommerce'),
            $locationType !== '' ? $locationType : __('Paket Shop', 'dexpress-woocommerce'),
            $locationName !== '' ? $locationName : __('Odabrana lokacija', 'dexpress-woocommerce'),
            $locationLine !== '' ? $locationLine : __('adresa nije dostupna', 'dexpress-woocommerce'),
            $trackingCode,
        );
    }

    /**
     * Prilagođava tekst regex-u za Content iz API dokumentacije (srpska latinica + ograničen skup znakova).
     */
    private function sanitizeApiContentDescription(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $clean = (string) preg_replace(
            '/[^\-\,\(\)\/a-zžćčđšA-ZĐŠĆŽČ_0-9\.\s]/u',
            '',
            $text,
        );
        $clean = (string) preg_replace('/\s+/u', ' ', trim($clean));

        return mb_substr($clean, 0, 50);
    }

    private function formatCreateShipmentLog(int $orderId, string $environment, array $payload): string
    {
        return implode("\n", [
            '[CREATE SHIPMENT]',
            'Order: ' . $orderId,
            'Environment: ' . $environment,
            'Payload:',
            (string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $n = (int) $value;

        return $n > 0 ? $n : null;
    }

    private function orderUsesPackageShopShipping(\WC_Order $order): bool
    {
        foreach ($order->get_items('shipping') as $item) {
            if (!$item instanceof \WC_Order_Item_Shipping) {
                continue;
            }

            if ((string) $item->get_method_id() === self::PACKAGE_SHOP_METHOD_ID) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $packageDataList
     */
    private function validatePackageShopShipmentConstraints(
        int $orderId,
        array $packageDataList,
        Money $codAmount,
        string $recipientPhone,
        int $returnDocValue,
        Grams $totalMass,
    ): void {
        if (count($packageDataList) !== 1) {
            $this->throwPackageShopConstraint($orderId, 'Paketomat dostava nije moguća: dozvoljen je samo jedan paket.');
        }

        if ($codAmount->toPara() >= self::PACKAGE_SHOP_MAX_BUYOUT_PARA) {
            $this->throwPackageShopConstraint($orderId, 'Paketomat dostava nije moguća: otkupnina ne sme prelaziti 200.000,00 RSD.');
        }

        try {
            $phone = PhoneNumber::fromString($recipientPhone);
            if (!$phone->isMobile()) {
                $this->throwPackageShopConstraint($orderId, 'Paketomat dostava nije moguća: broj telefona primaoca mora biti mobilni broj.');
            }
        } catch (\InvalidArgumentException) {
            $this->throwPackageShopConstraint($orderId, 'Paketomat dostava nije moguća: broj telefona primaoca mora biti mobilni broj.');
        }

        if ($returnDocValue !== 0) {
            $this->throwPackageShopConstraint($orderId, 'Paketomat dostava nije moguća: povraćaj dokumenta mora biti isključen.');
        }

        if ($totalMass->value() >= self::PACKAGE_SHOP_MAX_WEIGHT_GRAMS) {
            $this->throwPackageShopConstraint($orderId, 'Paketomat dostava nije moguća: težina paketa prelazi 20kg.');
        }

        $package = $packageDataList[0] ?? [];
        $dimX = isset($package['dim_x']) ? (int) $package['dim_x'] : 0;
        $dimY = isset($package['dim_y']) ? (int) $package['dim_y'] : 0;
        $dimZ = isset($package['dim_z']) ? (int) $package['dim_z'] : 0;

        if ($dimX <= 0 || $dimY <= 0 || $dimZ <= 0) {
            $this->throwPackageShopConstraint($orderId, 'Paketomat dostava nije moguća: dimenzije paketa moraju biti unete.');
        }

        if ($dimX >= self::PACKAGE_SHOP_MAX_DIM_X_MM
            || $dimY >= self::PACKAGE_SHOP_MAX_DIM_Y_MM
            || $dimZ >= self::PACKAGE_SHOP_MAX_DIM_Z_MM) {
            $this->throwPackageShopConstraint($orderId, 'Paketomat dostava nije moguća: dimenzije paketa prelaze dozvoljene vrednosti 470x440x440mm.');
        }
    }

    private function throwPackageShopConstraint(int $orderId, string $message): void
    {
        $this->logger->warning('[PACKAGE SHOP CONSTRAINT] ' . $message, ['order_id' => $orderId]);
        throw new \RuntimeException($message);
    }
}
