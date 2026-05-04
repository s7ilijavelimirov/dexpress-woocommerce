<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Ajax;

use S7codedesign\DExpress\Application\Shipment\CreateShipmentRequest;
use S7codedesign\DExpress\Application\Shipment\CreateShipmentService;
use S7codedesign\DExpress\Domain\Shipment\DeliveryType;
use S7codedesign\DExpress\Domain\Shipment\PaymentType;
use S7codedesign\DExpress\Domain\Shipment\ReturnDoc;
use S7codedesign\DExpress\Presentation\Frontend\Shipping\DexpressShippingMethod;
use WC_Order;
use WC_Order_Item_Product;

final class CreateShipmentController
{
    private const MAX_PACKAGES = 100;

    public function __construct(
        private readonly CreateShipmentService $service,
    ) {}

    public function register(): void
    {
        add_action('wp_ajax_dexpress_create_shipment', [$this, 'handle']);
    }

    public function handle(): void
    {
        check_ajax_referer('dexpress_create_shipment', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Nedovoljna prava.'], 403);
            return;
        }

        $orderId          = (int) ($_POST['order_id'] ?? 0);
        $senderLocationId = (int) ($_POST['sender_location_id'] ?? 0);
        $selfDropOff      = !empty($_POST['self_drop_off']);
        $content          = sanitize_text_field($_POST['content'] ?? '');
        $note             = sanitize_text_field($_POST['note'] ?? '');

        if ($orderId <= 0 || $senderLocationId <= 0) {
            wp_send_json_error(['message' => 'Narudžbina i lokacija pošiljaoca su obavezni.']);
            return;
        }

        if ($content === '') {
            wp_send_json_error(['message' => 'Sadržaj pošiljke je obavezan.']);
            return;
        }

        try {
            $deliveryType = DeliveryType::from((int) ($_POST['delivery_type'] ?? DeliveryType::Regular->value));
            $paymentType  = PaymentType::from((int) ($_POST['payment_type'] ?? PaymentType::Invoice->value));
            $returnDoc    = ReturnDoc::from((int) ($_POST['return_doc'] ?? ReturnDoc::None->value));
        } catch (\ValueError) {
            wp_send_json_error(['message' => 'Nevažeća vrednost parametra.']);
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            wp_send_json_error(['message' => 'Narudžbina nije pronađena.']);
            return;
        }

        if (!DexpressShippingMethod::orderUsesDexpress($order)) {
            wp_send_json_error(['message' => __('Za ovu porudžbinu nije izabrana D Express dostava.', 'dexpress-woocommerce')]);
            return;
        }

        // Parse per-package data from wizard (JSON array)
        $packages = $this->parsePackages($_POST['packages'] ?? '');

        if (count($packages) > self::MAX_PACKAGES) {
            wp_send_json_error([
                'message' => sprintf(
                    __('Maksimalno %d paketa po pošiljci.', 'dexpress-woocommerce'),
                    self::MAX_PACKAGES,
                ),
            ]);
            return;
        }

        $allocationError = $this->validatePackageLineAllocations($order, $packages);
        if ($allocationError !== null) {
            wp_send_json_error(['message' => $allocationError]);
            return;
        }

        // Derive total mass from per-package data; fall back to explicit POST param
        $totalMassGrams = array_sum(array_column($packages, 'mass'));
        if ($totalMassGrams <= 0) {
            $totalMassGrams = (int) ($_POST['total_mass_grams'] ?? 0);
        }

        // Persist total mass to order meta so the service can read it
        if ($totalMassGrams > 0) {
            $order->update_meta_data('_dexpress_total_mass', $totalMassGrams);
            $order->save_meta_data();
        }

        $request = new CreateShipmentRequest(
            orderId:          $orderId,
            senderLocationId: $senderLocationId,
            deliveryType:     $deliveryType,
            paymentType:      $paymentType,
            returnDoc:        $returnDoc,
            selfDropOff:      $selfDropOff,
            content:          $content,
            note:             $note,
            packages:         $packages,
        );

        try {
            $result = $this->service->execute($request);
        } catch (\RuntimeException $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
            return;
        }

        $message = $result->isTestMode()
            ? 'Pošiljka je kreirana u TEST modu. Nije kreiran pravi kurir nalog.'
            : 'Pošiljka je kreirana.';

        wp_send_json_success([
            'message'         => $message,
            'tracking_code'   => $result->trackingCode(),
            'tracking_codes'  => $result->allPackageCodes(),
            'shipment_id'     => $result->shipment->id(),
            'test_mode'       => $result->isTestMode(),
        ]);
    }

    /**
     * Parses the JSON packages array from the wizard.
     *
     * @return array<int, array{
     *   mass: int,
     *   dim_x: int|null,
     *   dim_y: int|null,
     *   dim_z: int|null,
     *   content: string|null,
     *   items: list<array{order_item_id: int, qty: int}>
     * }>
     */
    private function parsePackages(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode(wp_unslash($raw), true);

        if (!is_array($decoded) || empty($decoded)) {
            return [];
        }

        $packages = [];

        foreach ($decoded as $p) {
            if (!is_array($p)) {
                continue;
            }

            $mass = max(1, (int) ($p['mass'] ?? 1));

            $contentRaw = isset($p['content']) ? sanitize_text_field((string) $p['content']) : '';
            $content    = $contentRaw !== '' ? mb_substr($contentRaw, 0, 50) : null;

            $itemsMerged = [];
            if (isset($p['items']) && is_array($p['items'])) {
                foreach ($p['items'] as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    $oid = (int) ($it['order_item_id'] ?? 0);
                    $qty = max(0, (int) ($it['qty'] ?? 0));
                    if ($oid <= 0 || $qty <= 0) {
                        continue;
                    }
                    $itemsMerged[$oid] = ($itemsMerged[$oid] ?? 0) + $qty;
                }
            }

            $itemsList = [];
            foreach ($itemsMerged as $oid => $qty) {
                $itemsList[] = ['order_item_id' => $oid, 'qty' => $qty];
            }

            $packages[] = [
                'mass'    => $mass,
                'dim_x'   => $this->optionalPositiveInt($p['dim_x'] ?? null),
                'dim_y'   => $this->optionalPositiveInt($p['dim_y'] ?? null),
                'dim_z'   => $this->optionalPositiveInt($p['dim_z'] ?? null),
                'content' => $content,
                'items'   => $itemsList,
            ];
        }

        return $packages;
    }

    /**
     * @param array<int, array{mass: int, dim_x: int|null, dim_y: int|null, dim_z: int|null, content: string|null, items: list<array{order_item_id: int, qty: int}>}> $packages
     */
    private function validatePackageLineAllocations(WC_Order $order, array $packages): ?string
    {
        $allowedQty = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $allowedQty[$item->get_id()] = (int) $item->get_quantity();
        }

        if ($allowedQty === []) {
            foreach ($packages as $pkg) {
                if ($pkg['items'] !== []) {
                    return __('Narudžbina nema stavki proizvoda za raspodelu po paketima.', 'dexpress-woocommerce');
                }
            }

            return null;
        }

        $totals = [];
        foreach ($packages as $pkg) {
            foreach ($pkg['items'] as $row) {
                $oid = $row['order_item_id'];
                $qty = $row['qty'];
                if (!isset($allowedQty[$oid])) {
                    return __('Jedna ili više stavki ne pripadaju ovoj narudžbini.', 'dexpress-woocommerce');
                }
                $totals[$oid] = ($totals[$oid] ?? 0) + $qty;
            }
        }

        foreach ($allowedQty as $oid => $max) {
            if (($totals[$oid] ?? 0) > $max) {
                return sprintf(
                    /* translators: %d: ordered quantity */
                    __('Ukupna raspodeljena količina premašuje poručenu za jednu stavku (max %d).', 'dexpress-woocommerce'),
                    $max,
                );
            }
        }

        return null;
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $n = (int) $value;

        return $n > 0 ? $n : null;
    }
}
