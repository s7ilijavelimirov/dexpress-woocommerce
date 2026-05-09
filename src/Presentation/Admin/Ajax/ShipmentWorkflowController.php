<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Ajax;

use S7codedesign\DExpress\Application\Shipment\CreateShipmentRequest;
use S7codedesign\DExpress\Application\Shipment\CreateShipmentService;
use S7codedesign\DExpress\Domain\Shipment\DeliveryType;
use S7codedesign\DExpress\Domain\Shipment\PaymentType;
use S7codedesign\DExpress\Domain\Shipment\ReturnDoc;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbShipmentRepository;
use S7codedesign\DExpress\Presentation\Frontend\Shipping\DexpressPackageShopShippingMethod;
use S7codedesign\DExpress\Presentation\Frontend\Shipping\DexpressShippingMethod;
use WC_Order;
use WC_Order_Item_Product;

final class ShipmentWorkflowController
{
    private const MAX_PACKAGES = 100;

    public function __construct(
        private readonly CreateShipmentService $service,
        private readonly WpdbShipmentRepository $shipments,
    ) {}

    public function register(): void
    {
        add_action('wp_ajax_dexpress_save_shipment_local', [$this, 'saveLocal']);
        add_action('wp_ajax_dexpress_send_saved_shipment', [$this, 'sendSaved']);
    }

    public function saveLocal(): void
    {
        check_ajax_referer('dexpress_save_shipment_local', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Nedovoljna prava.'], 403);
            return;
        }

        $orderId = (int) ($_POST['order_id'] ?? 0);
        $shipmentId = (int) ($_POST['shipment_id'] ?? 0);
        $draftRaw = (string) ($_POST['draft'] ?? '');
        if ($orderId <= 0 || $draftRaw === '') {
            wp_send_json_error(['message' => __('Nedostaju podaci za čuvanje pošiljke.', 'dexpress-woocommerce')]);
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            wp_send_json_error(['message' => __('Narudžbina nije pronađena.', 'dexpress-woocommerce')]);
            return;
        }
        if (!$this->orderUsesDexpress($order)) {
            wp_send_json_error(['message' => __('Za ovu porudžbinu nije izabrana D Express dostava.', 'dexpress-woocommerce')]);
            return;
        }

        $decoded = json_decode(wp_unslash($draftRaw), true);
        if (!is_array($decoded)) {
            wp_send_json_error(['message' => __('Podaci nisu u validnom formatu.', 'dexpress-woocommerce')]);
            return;
        }

        $normalized = $this->normalizeDraft($order, $decoded);
        if ($normalized === null) {
            wp_send_json_error(['message' => __('Podaci pošiljke nisu validni.', 'dexpress-woocommerce')]);
            return;
        }

        try {
            $request = new CreateShipmentRequest(
                orderId: $orderId,
                senderLocationId: (int) ($normalized['options']['sender_location_id'] ?? 0),
                deliveryType: DeliveryType::from((int) ($normalized['options']['delivery_type'] ?? DeliveryType::Regular->value)),
                paymentType: PaymentType::from((int) ($normalized['options']['payment_type'] ?? PaymentType::Invoice->value)),
                returnDoc: ReturnDoc::from((int) ($normalized['options']['return_doc'] ?? ReturnDoc::None->value)),
                selfDropOff: !empty($normalized['options']['self_drop_off']),
                content: (string) ($normalized['options']['content'] ?? ''),
                note: (string) ($normalized['options']['note'] ?? ''),
                packages: (array) ($normalized['packages'] ?? []),
            );
            $result = $shipmentId > 0
                ? $this->service->updatePendingShipment($shipmentId, $request)
                : $this->service->save($request);
        } catch (\ValueError) {
            wp_send_json_error(['message' => __('Nevažeća vrednost pošiljke.', 'dexpress-woocommerce')]);
            return;
        } catch (\RuntimeException $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
            return;
        }

        $shipmentId = (int) $result->shipment->id();
        wp_send_json_success([
            'message' => __('Nalepnica je kreirana lokalno. Pošiljka čeka slanje u D-Express.', 'dexpress-woocommerce'),
            'shipment_id' => $shipmentId,
            'tracking_code' => $result->trackingCode(),
            'tracking_codes' => $result->allPackageCodes(),
            'label_url' => $this->buildLabelUrl($shipmentId),
        ]);
    }

    public function sendSaved(): void
    {
        check_ajax_referer('dexpress_send_saved_shipment', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Nedovoljna prava.'], 403);
            return;
        }

        $shipmentId = (int) ($_POST['shipment_id'] ?? 0);
        if ($shipmentId <= 0) {
            wp_send_json_error(['message' => __('Pošiljka nije validna.', 'dexpress-woocommerce')]);
            return;
        }

        $shipment = $this->shipments->findById($shipmentId);
        if ($shipment === null) {
            wp_send_json_error(['message' => __('Pošiljka nije pronađena.', 'dexpress-woocommerce')]);
            return;
        }

        try {
            $result = $this->service->send($shipmentId);
        } catch (\RuntimeException $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
            return;
        }

        wp_send_json_success([
            'message' => $result->isTestMode()
                ? __('Pošiljka je poslata u TEST modu.', 'dexpress-woocommerce')
                : __('Pošiljka je uspešno poslata u D-Express.', 'dexpress-woocommerce'),
            'shipment_id' => $shipmentId,
            'tracking_code' => $result->trackingCode(),
            'tracking_codes' => $result->allPackageCodes(),
            'label_url' => $this->buildLabelUrl($shipmentId),
        ]);
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array{
     *   options: array{sender_location_id:int,delivery_type:int,payment_type:int,return_doc:int,self_drop_off:int,content:string,note:string},
     *   packages: array<int, array{mass:int,dim_x:?int,dim_y:?int,dim_z:?int,content:?string,items:list<array{order_item_id:int,qty:int}>}>
     * }|null
     */
    private function normalizeDraft(WC_Order $order, array $decoded): ?array
    {
        $options = is_array($decoded['options'] ?? null) ? $decoded['options'] : null;
        $packagesRaw = is_array($decoded['packages'] ?? null) ? $decoded['packages'] : null;
        if ($options === null || $packagesRaw === null || $packagesRaw === []) {
            return null;
        }

        $senderLocationId = (int) ($options['sender_location_id'] ?? 0);
        $content = sanitize_text_field((string) ($options['content'] ?? ''));
        if ($senderLocationId <= 0 || $content === '') {
            return null;
        }

        try {
            $deliveryType = DeliveryType::from((int) ($options['delivery_type'] ?? DeliveryType::Regular->value));
            $paymentType = PaymentType::from((int) ($options['payment_type'] ?? PaymentType::Invoice->value));
            $returnDoc = ReturnDoc::from((int) ($options['return_doc'] ?? ReturnDoc::None->value));
        } catch (\ValueError) {
            return null;
        }

        $packages = [];
        foreach ($packagesRaw as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }
            $items = [];
            if (is_array($pkg['items'] ?? null)) {
                foreach ($pkg['items'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $oid = (int) ($row['order_item_id'] ?? 0);
                    $qty = max(0, (int) ($row['qty'] ?? 0));
                    if ($oid > 0 && $qty > 0) {
                        $items[] = ['order_item_id' => $oid, 'qty' => $qty];
                    }
                }
            }

            $packages[] = [
                'mass' => max(1, (int) ($pkg['mass'] ?? 0)),
                'dim_x' => $this->optionalPositiveInt($pkg['dim_x'] ?? null),
                'dim_y' => $this->optionalPositiveInt($pkg['dim_y'] ?? null),
                'dim_z' => $this->optionalPositiveInt($pkg['dim_z'] ?? null),
                'content' => (($pkg['content'] ?? '') !== '')
                    ? mb_substr(sanitize_text_field((string) $pkg['content']), 0, 50)
                    : null,
                'items' => $items,
            ];
        }

        if ($packages === [] || count($packages) > self::MAX_PACKAGES) {
            return null;
        }
        if ($this->validatePackageLineAllocations($order, $packages) !== null) {
            return null;
        }

        return [
            'options' => [
                'sender_location_id' => $senderLocationId,
                'delivery_type' => $deliveryType->value,
                'payment_type' => $paymentType->value,
                'return_doc' => $returnDoc->value,
                'self_drop_off' => !empty($options['self_drop_off']) ? 1 : 0,
                'content' => $content,
                'note' => sanitize_text_field((string) ($options['note'] ?? '')),
            ],
            'packages' => $packages,
        ];
    }

    /**
     * @param array<int, array{mass:int,dim_x:?int,dim_y:?int,dim_z:?int,content:?string,items:list<array{order_item_id:int,qty:int}>}> $packages
     */
    private function validatePackageLineAllocations(WC_Order $order, array $packages): ?string
    {
        $allowedQty = [];
        foreach ($order->get_items() as $item) {
            if ($item instanceof WC_Order_Item_Product) {
                $allowedQty[$item->get_id()] = (int) $item->get_quantity();
            }
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
                    __('Ukupna raspodeljena količina premašuje poručenu za jednu stavku (max %d).', 'dexpress-woocommerce'),
                    $max,
                );
            }
        }

        return null;
    }

    private function buildLabelUrl(int $shipmentId): string
    {
        return add_query_arg(
            [
                'page' => 'dexpress-label',
                'shipment_id' => $shipmentId,
                'nonce' => wp_create_nonce('dexpress_print_label_' . $shipmentId),
            ],
            admin_url('admin.php'),
        );
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        $n = (int) $value;
        return $n > 0 ? $n : null;
    }

    private function orderUsesDexpress(WC_Order $order): bool
    {
        return DexpressShippingMethod::orderUsesDexpress($order)
            || DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order);
    }
}
