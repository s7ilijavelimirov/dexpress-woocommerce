<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Ajax;

use S7codedesign\DExpress\Application\Shipment\CreateShipmentRequest;
use S7codedesign\DExpress\Application\Shipment\CreateShipmentService;
use S7codedesign\DExpress\Domain\Shipment\DeliveryType;
use S7codedesign\DExpress\Domain\Shipment\PaymentType;
use S7codedesign\DExpress\Domain\Shipment\ReturnDoc;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;

/**
 * AJAX handler za grupno kreiranje pošiljaka.
 *
 * Koristi CreateShipmentService::save() + send() bez modifikacija —
 * ista logika kao i za pojedinačno kreiranje iz metaboxa.
 *
 * Svaki poziv obrađuje JEDNU narudžbinu — JS petlja poziva po redu.
 */
final class BulkShipmentController
{
    public function __construct(
        private readonly CreateShipmentService $createShipment,
        private readonly OptionsRepository $options,
        private readonly Logger $logger,
    ) {}

    public function register(): void
    {
        add_action('wp_ajax_dexpress_bulk_save_shipment', [$this, 'handleSave']);
        add_action('wp_ajax_dexpress_bulk_send_shipment', [$this, 'handleSend']);
    }

    /**
     * Kreira jednu pošiljku (alokira TT kod, snima u bazu, status = pending_send).
     * Poziva CreateShipmentService::save() — ne šalje u API.
     */
    public function handleSave(): void
    {
        check_ajax_referer('dexpress_bulk_save_shipment', 'nonce', false);

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Nemate dozvolu.', 'dexpress-woocommerce')], 403);
        }

        $orderId          = absint($_POST['order_id'] ?? 0);
        $senderLocationId = absint($_POST['sender_location_id'] ?? 0);
        $deliveryTypeInt  = $this->options->getInt('shipment.default_delivery_type', DeliveryType::Regular->value);
        $paymentTypeInt   = $this->options->getInt('shipment.default_payment_type', PaymentType::Invoice->value);
        $returnDocInt     = $this->options->getInt('shipment.default_return_doc', ReturnDoc::None->value);
        $selfDropOff      = isset($_POST['self_drop_off'])
            ? ($_POST['self_drop_off'] === '1')
            : $this->options->getBool('shipment.default_self_drop_off');
        $content          = sanitize_text_field($_POST['content'] ?? '');
        $note             = sanitize_text_field($_POST['note'] ?? '');
        $weightKg         = (float) str_replace(',', '.', $_POST['weight_kg'] ?? '0');
        $weightGrams      = (int) round($weightKg * 1000);
        $dimXRaw          = trim($_POST['dim_x'] ?? '');
        $dimYRaw          = trim($_POST['dim_y'] ?? '');
        $dimZRaw          = trim($_POST['dim_z'] ?? '');
        $dimX             = $dimXRaw !== '' ? (int) round((float) str_replace(',', '.', $dimXRaw) * 10) : null;
        $dimY             = $dimYRaw !== '' ? (int) round((float) str_replace(',', '.', $dimYRaw) * 10) : null;
        $dimZ             = $dimZRaw !== '' ? (int) round((float) str_replace(',', '.', $dimZRaw) * 10) : null;

        if ($orderId === 0) {
            wp_send_json_error(['message' => __('Nevažeći ID narudžbine.', 'dexpress-woocommerce')], 422);
        }

        if ($senderLocationId === 0) {
            wp_send_json_error(['message' => __('Lokacija pošiljaoca je obavezna.', 'dexpress-woocommerce')], 422);
        }

        if ($content === '') {
            wp_send_json_error(['message' => __('Sadržaj pošiljke je obavezan.', 'dexpress-woocommerce')], 422);
        }

        $deliveryType = DeliveryType::tryFrom($deliveryTypeInt) ?? DeliveryType::Regular;
        $paymentType  = PaymentType::tryFrom($paymentTypeInt) ?? PaymentType::Invoice;
        $returnDoc    = ReturnDoc::tryFrom($returnDocInt) ?? ReturnDoc::None;

        $req = new CreateShipmentRequest(
            orderId:          $orderId,
            senderLocationId: $senderLocationId,
            deliveryType:     $deliveryType,
            paymentType:      $paymentType,
            returnDoc:        $returnDoc,
            selfDropOff:      $selfDropOff,
            content:          $content,
            note:             $note,
            packages:         [[
                'mass'  => $weightGrams,
                'dim_x' => $dimX,
                'dim_y' => $dimY,
                'dim_z' => $dimZ,
            ]],
        );

        try {
            $result     = $this->createShipment->save($req);
            $shipmentId = (int) $result->shipment->id();

            wp_send_json_success([
                'shipment_id'   => $shipmentId,
                'tracking_code' => $result->trackingCode(),
                'order_id'      => $orderId,
                'label_url'     => add_query_arg(
                    [
                        'page'        => 'dexpress-label',
                        'shipment_id' => $shipmentId,
                        'nonce'       => wp_create_nonce('dexpress_print_label_' . $shipmentId),
                    ],
                    admin_url('admin.php'),
                ),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[BULK SAVE] Order ' . $orderId . ': ' . $e->getMessage());
            wp_send_json_error([
                'message'  => $e->getMessage(),
                'order_id' => $orderId,
            ], 422);
        }
    }

    /**
     * Šalje jednu pending_send pošiljku u D-Express API.
     * Poziva CreateShipmentService::send($shipmentId).
     */
    public function handleSend(): void
    {
        check_ajax_referer('dexpress_bulk_send_shipment', 'nonce', false);

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Nemate dozvolu.', 'dexpress-woocommerce')], 403);
        }

        $shipmentId = absint($_POST['shipment_id'] ?? 0);
        if ($shipmentId === 0) {
            wp_send_json_error(['message' => __('Nevažeći ID pošiljke.', 'dexpress-woocommerce')], 422);
        }

        try {
            $result = $this->createShipment->send($shipmentId);

            wp_send_json_success([
                'shipment_id'   => $shipmentId,
                'tracking_code' => $result->trackingCode(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[BULK SEND] Shipment ' . $shipmentId . ': ' . $e->getMessage());
            wp_send_json_error([
                'message'     => $e->getMessage(),
                'shipment_id' => $shipmentId,
            ], 422);
        }
    }
}
