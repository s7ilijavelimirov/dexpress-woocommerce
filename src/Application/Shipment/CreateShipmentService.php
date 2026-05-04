<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Application\Shipment;

use S7codedesign\DExpress\Domain\Events\ShipmentCreated;
use S7codedesign\DExpress\Domain\Money\Grams;
use S7codedesign\DExpress\Domain\Money\Money;
use S7codedesign\DExpress\Domain\Shipment\Package;
use S7codedesign\DExpress\Domain\Shipment\PackageCode;
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
    ) {}

    /**
     * @throws \RuntimeException on validation failure, DB error, or API error
     */
    public function execute(CreateShipmentRequest $req): CreateShipmentResult
    {
        $order = wc_get_order($req->orderId);

        if (!$order instanceof \WC_Order) {
            throw new \RuntimeException('Narudžbina nije pronađena.');
        }

        if ($this->shipmentRepo->findByOrderId($req->orderId) !== []) {
            throw new \RuntimeException('Za ovu narudžbinu već postoji D Express pošiljka.');
        }

        $location = $this->locationRepo->findById($req->senderLocationId);

        if ($location === null) {
            throw new \RuntimeException('Lokacija pošiljaoca nije pronađena.');
        }

        $prefix     = $this->options->getString('shipment.prefix');
        $rangeStart = (int) $this->options->get('shipment.range_start', 1);
        $rangeEnd   = (int) $this->options->get('shipment.range_end', 9999999999);
        $clientId   = trim($this->options->getString('api.client_id'));

        if ($clientId === '') {
            throw new \RuntimeException('ID klijenta (CClientID) nije konfigurisan. Unesite vrednost u podešavanjima.');
        }

        if ($prefix === '' || $rangeStart <= 0 || $rangeEnd <= 0) {
            throw new \RuntimeException('Opseg kodova pošiljaka nije konfigurisan. Proverite podešavanja.');
        }

        // -----------------------------------------------------------------------
        // Recipient — shipping only when complete; else billing (parity with labels / API)
        // -----------------------------------------------------------------------
        $recipient = $this->recipientResolver->resolve($order);

        $rName        = $recipient['name'];
        $rAddress     = $recipient['street'];
        $rAddressNum  = $recipient['street_number'];
        $rAddressDesc = $recipient['address_desc'];
        $rTownId      = $recipient['town_id'];
        $rPhone       = $recipient['phone'];

        if ($rPhone === '') {
            $raw = (string) ($order->get_meta('_billing_phone_api_format') ?: $order->get_billing_phone());
            throw new \RuntimeException(
                'Telefon primaoca nije validan srpski broj ("' . $raw . '").',
            );
        }

        $this->validateRecipient($rName, $rAddress, $rAddressNum, $rTownId, $rPhone);

        $this->addressCheck->logPreflightForShipment($req->orderId, [
            'name'           => $rName,
            'street'         => $rAddress,
            'street_number'  => $rAddressNum,
            'address_desc'   => $rAddressDesc,
            'town_id'        => $rTownId,
            'phone'          => $rPhone,
        ]);

        // -----------------------------------------------------------------------
        // COD — only for WC "cod" payment method; for invoice/card BuyOut = 0
        // -----------------------------------------------------------------------
        $isCod     = $order->get_payment_method() === 'cod';
        $codAmount = $isCod
            ? Money::fromRsd((float) $order->get_total())
            : Money::fromRsd(0.0);

        $bankAccount = ($location['bank_account'] ?? '') !== '' ? (string) $location['bank_account'] : null;

        if ($codAmount->toPara() > 0) {
            if ($bankAccount === null) {
                throw new \RuntimeException(
                    'Narudžbina je plaćanje pouzećem (COD), ali lokacija pošiljaoca nema podešen tekući račun. ' .
                    'Dodajte broj računa u Podešavanja → Lokacije pošiljaoca.'
                );
            }

            // Validate bank account format before sending to API
            $this->validateBankAccount($bankAccount);
        }

        // -----------------------------------------------------------------------
        // Declared value — sum of order item totals (after discount), excl. tax.
        // get_total() on WC_Order_Item_Product is always pre-tax; adding
        // get_total_tax() gives the inclusive price that appears on the invoice.
        // -----------------------------------------------------------------------
        $declaredValue = $this->calculateDeclaredValue($order);

        // -----------------------------------------------------------------------
        // Mass — per-package from wizard; fall back to order meta
        // -----------------------------------------------------------------------
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

        // One D Express shipment per order — ReferenceID is deterministic (order id).
        $orderRef = $this->shipmentReferenceId($order);

        // -----------------------------------------------------------------------
        // Transaction: allocate code(s) → create domain objects → persist to DB
        // -----------------------------------------------------------------------
        $shipment = $this->tx->run(function () use (
            $req, $orderRef, $pkgDataList, $bankAccount, $prefix, $rangeStart, $rangeEnd,
            $codAmount, $declaredValue, $totalMass
        ): Shipment {
            $packages = [];
            // allocatePackageCode() uses MAX(code) before any INSERT; multiple calls in one transaction
            // would see the same MAX → duplicate codes. Reserve first code from DB, then bump locally.
            $pkgCode = null;

            foreach ($pkgDataList as $i => $pkgData) {
                if ($pkgCode === null) {
                    $pkgCode = $this->shipmentRepo->allocatePackageCode($prefix, $rangeStart, $rangeEnd);
                } else {
                    $nextNum = $pkgCode->number() + 1;
                    if ($nextNum > $rangeEnd) {
                        throw new \RuntimeException(
                            sprintf(
                                'Opseg kodova pošiljaka je iscrpljen za više paketa (prefix: %s, max: %d).',
                                $prefix,
                                $rangeEnd,
                            ),
                        );
                    }
                    $pkgCode = PackageCode::fromPrefixAndIndex($prefix, $nextNum);
                }

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
                initialLabelSnapshot: $this->pendingStatusDisplayLabel(),
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

        // -----------------------------------------------------------------------
        // API call — outside the DB transaction to avoid holding locks.
        // On failure: compensating delete removes the Pending shipment so there
        // is no dangling record and the admin can retry cleanly.
        // -----------------------------------------------------------------------
        $payload = $this->buildApiPayload(
            $shipment, $location, $clientId,
            $rName, $rAddress, $rAddressNum, $rAddressDesc, $rTownId, $rPhone
        );

        $configuredEnv = $this->options->getString('api.environment', 'test') === 'production' ? 'PRODUCTION' : 'TEST';
        $this->logger->info($this->formatCreateShipmentLog($req->orderId, $configuredEnv, $payload));

        try {
            $apiResponse = $this->apiClient->addShipment($payload);
        } catch (\Throwable $e) {
            $this->shipmentRepo->deleteById((int) $shipment->id());
            $this->logger->error(implode("\n", [
                '[ERROR - CREATE SHIPMENT]',
                'Order: ' . $req->orderId,
                'Message: ' . $e->getMessage(),
            ]));
            throw new \RuntimeException('Pošiljka nije poslata D Express API-ju. ' . $e->getMessage());
        }

        $pendingLabel = $this->pendingStatusDisplayLabel();
        $shipment       = $shipment->withApiResponse(
            $apiResponse,
            StatusEmailBucket::Other,
            0,
            $pendingLabel,
        );
        $this->shipmentRepo->save($shipment);

        $trackingCode = $shipment->trackingCode();
        $this->logger->info(implode("\n", [
            '[API RESPONSE]',
            $apiResponse,
        ]));
        $this->logger->info(implode("\n", [
            '[RESULT]',
            'Tracking code: ' . $trackingCode,
        ]));

        do_action('dexpress_shipment_created', new ShipmentCreated($shipment, $req->orderId));
        do_action('dexpress/shipment.created', new ShipmentCreated($shipment, $req->orderId));

        $order->add_order_note(
            sprintf(
                /* translators: %s: tracking / package code */
                __('D Express: Kreirana je pošiljka. Kod za praćenje: %s.', 'dexpress-woocommerce'),
                $trackingCode,
            ),
        );
        $order->save();

        return new CreateShipmentResult($shipment);
    }

    /**
     * @param array<int, array<string, mixed>> $pkgDataList
     */
    private function persistPackageAllocations(Shipment $shipment, array $pkgDataList): void
    {
        $sid = $shipment->id();
        if ($sid === null || $sid <= 0) {
            throw new \RuntimeException('Pošiljka nema ID — čuvanje stavki paketa nije moguće.');
        }

        $this->tx->run(function () use ($sid, $pkgDataList): void {
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
        string   $rPhone,
    ): array {
        $clientIdVal = is_numeric($clientId) ? (int) $clientId : $clientId;

        return [
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
            'RName'        => $rName,
            'RAddress'     => $rAddress,
            'RAddressNum'  => $rAddressNum,
            'RAddressDesc' => $rAddressDesc,
            'RTownID'      => $rTownId,
            'RCName'       => $rName,
            'RCPhone'      => $rPhone,
            'RClientID'    => '',

            // ---- Shipment ----
            'Note'        => $shipment->note,
            'DlTypeID'    => $shipment->deliveryType->value,
            'PaymentBy'   => $shipment->paymentBy->value,
            'PaymentType' => $shipment->paymentType->value,
            'Value'       => $shipment->declaredValue->toPara(),
            // Jedno obavezno polje za ceo shipment; spajamo korak 3 + opcione napomene po paketu (max 50, regex API).
            'Content'     => $this->buildShipmentContentForApi($shipment),
            'Mass'        => $shipment->totalMass->value(),
            'ReferenceID' => $shipment->referenceId,
            'ReturnDoc'   => $shipment->returnDoc->value,
            'SelfDropOff' => $shipment->selfDropOff ? 1 : 0,

            // ---- COD ----
            'BuyOut'        => $shipment->codAmount->toPara(),
            'BuyOutFor'     => 0,
            'BuyOutAccount' => $shipment->codBankAccount !== null
                ? $this->formatBankAccount($shipment->codBankAccount)
                : '',

            // ---- Packages ----
            'PackageList' => $shipment->packagesToApiArray(),
        ];
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
}
