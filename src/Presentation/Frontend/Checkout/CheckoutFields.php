<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Frontend\Checkout;

use S7codedesign\DExpress\Domain\Address\PhoneNumber;
use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Persistence\AddressSearchRepository;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class CheckoutFields
{
    /**
     * Dodatna polja blok checkuta (_wc_billing/… u adminu) — bez prikaza kupcu i bez duplog „Broja“ u adresi.
     *
     * @var list<string>
     */
    private const TECHNICAL_CHECKOUT_FIELD_IDS = [
        'dexpress/town-id',
        'dexpress/street-id',
        'dexpress/street-number',
    ];

    private static bool $blocksCheckoutDisplayWired = false;

    public function __construct(
        private readonly Logger $logger,
        private readonly AddressSearchRepository $addressSearch,
    ) {}

    public function register(): void
    {
        add_filter('woocommerce_checkout_fields', [$this, 'modifyFields']);
        // Run after all other filters (priority 200) to override any autocomplete values WC sets
        add_filter('woocommerce_checkout_fields', [$this, 'disableAutocomplete'], 200);

        // Billing hidden fields (town_id, street_id)
        add_action('woocommerce_after_checkout_billing_form', [$this, 'renderBillingHiddenFields']);

        // Shipping hidden fields — same, for "ship to different address" section
        add_action('woocommerce_after_checkout_shipping_form', [$this, 'renderShippingHiddenFields']);

        // woocommerce_checkout_posted_data is registered in Plugin::registerFrontendHooks() with a closure.

        // Classic checkout order save (shortcode checkout)
        add_action('woocommerce_checkout_create_order', [$this, 'saveOrderMeta'], 10, 2);

        // Runs after WC persists core address fields — ensures D Express meta is not overwritten / missed.
        add_action('woocommerce_checkout_update_order_meta', [$this, 'saveOrderMetaLate'], 10, 2);

        // Block checkout order save (Store API — fires instead of woocommerce_checkout_create_order)
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'saveOrderMetaBlock'], 10, 2);

        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);

        // Register street-number as an additional block checkout field (WC 8.6+)
        add_action('woocommerce_init', [$this, 'registerBlockField']);

        add_filter('woocommerce_email_order_meta_fields', [$this, 'hideInternalIdsFromEmailOrderMeta'], 10, 3);

        // Posle WC Blocks Bootstrap-a: zamena renderera + Store API (blok „Thank you“).
        add_action('wp_loaded', [$this, 'wireBlocksCheckoutFieldDisplay'], 20);

        add_filter('woocommerce_filter_fields_for_order_confirmation', [$this, 'hideInternalCheckoutFieldOrderConfirmation'], 10, 5);

        // Blok „Thank you“ / Order Confirmation učitava adresu preko Store API — BillingAddressSchema uvek spaja sva dodatna polja.
        add_filter('rest_post_dispatch', [$this, 'stripInternalIdsFromStoreApiOrderResponse'], 10, 3);

        // SSR blokovi order-confirmation-billing/shipping-address ne koriste filter_fields_for_order_confirmation (WC core).
        add_filter('render_block', [$this, 'stripInternalIdsFromOrderConfirmationBlocks'], 10, 2);

        add_filter('woocommerce_address_to_edit', [$this, 'stripInternalIdsFromAddressEditor'], 9999, 2);

        add_action('woocommerce_checkout_create_order', [$this, 'normalizeCheckoutOrderPhones'], 5, 2);

        add_filter('woocommerce_admin_billing_fields', [$this, 'stripTechnicalAdditionalFieldsFromAdmin'], 9999, 3);
        add_filter('woocommerce_admin_shipping_fields', [$this, 'stripTechnicalAdditionalFieldsFromAdmin'], 9999, 3);
    }

    /**
     * Reuse WC's existing billing AND shipping fields for D Express address structure.
     * Only billing/shipping_dexpress_street_number is added (no WC equivalent exists).
     *
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    public function modifyFields(array $fields): array
    {
        foreach (['billing', 'shipping'] as $section) {
            // city → Mesto (town autocomplete)
            if (isset($fields[$section]["{$section}_city"])) {
                $fields[$section]["{$section}_city"] = array_merge($fields[$section]["{$section}_city"], [
                    'label'             => __('Mesto', 'dexpress-woocommerce'),
                    'placeholder'       => __('Unesite naziv mesta...', 'dexpress-woocommerce'),
                    'required'          => false,
                    'autocomplete'      => 'off',
                    'priority'          => 50,
                    'custom_attributes' => [
                        'data-dexpress-role'    => 'town-input',
                        'data-dexpress-section' => $section,
                        'spellcheck'            => 'false',
                    ],
                ]);
            }

            // address_1 → Ulica (street autocomplete, disabled until town is chosen)
            if (isset($fields[$section]["{$section}_address_1"])) {
                $fields[$section]["{$section}_address_1"] = array_merge($fields[$section]["{$section}_address_1"], [
                    'label'             => __('Ulica', 'dexpress-woocommerce'),
                    'placeholder'       => __('Unesite naziv ulice...', 'dexpress-woocommerce'),
                    'required'          => false,
                    'autocomplete'      => 'off',
                    'priority'          => 60,
                    'custom_attributes' => [
                        'data-dexpress-role'    => 'street-input',
                        'data-dexpress-section' => $section,
                        'spellcheck'            => 'false',
                    ],
                ]);
            }

            // New field: street number (no WC equivalent)
            $fields[$section]["{$section}_dexpress_street_number"] = [
                'label'       => __('Broj', 'dexpress-woocommerce'),
                'placeholder' => __('npr. 15, 15a, 23/4, bb', 'dexpress-woocommerce'),
                'required'    => false,
                'class'       => ['form-row-wide'],
                'priority'    => 65,
                'type'        => 'text',
            ];

            // address_2 → Opis adrese (optional)
            if (isset($fields[$section]["{$section}_address_2"])) {
                $fields[$section]["{$section}_address_2"] = array_merge($fields[$section]["{$section}_address_2"], [
                    'label'       => __('Opis adrese', 'dexpress-woocommerce'),
                    'placeholder' => __('Stan, sprat, interfon... (opciono)', 'dexpress-woocommerce'),
                    'required'    => false,
                    'priority'    => 70,
                ]);
            }

            // postcode → auto-filled from selected town (readonly)
            if (isset($fields[$section]["{$section}_postcode"])) {
                $fields[$section]["{$section}_postcode"] = array_merge($fields[$section]["{$section}_postcode"], [
                    'label'             => __('Poštanski broj', 'dexpress-woocommerce'),
                    'required'          => false,
                    'priority'          => 80,
                    'custom_attributes' => ['readonly' => 'readonly', 'tabindex' => '-1'],
                ]);
            }

            // Serbia has no provinces
            unset($fields[$section]["{$section}_state"]);
        }

        // Phone: numeric keyboard on mobile, helpful placeholder, letter-blocking in JS
        if (isset($fields['billing']['billing_phone'])) {
            $existing = $fields['billing']['billing_phone']['custom_attributes'] ?? [];
            $fields['billing']['billing_phone']['placeholder']       = __('npr. +381 64 123 4567', 'dexpress-woocommerce');
            $fields['billing']['billing_phone']['custom_attributes'] = array_merge($existing, [
                'inputmode' => 'numeric',
                'pattern'   => '[0-9+\s\-\/()]*',
            ]);
        }

        return $fields;
    }

    public function renderBillingHiddenFields(): void
    {
        echo '<input type="hidden" name="billing_dexpress_town_id" id="billing_dexpress_town_id" value="0">';
        echo '<input type="hidden" name="billing_dexpress_street_id" id="billing_dexpress_street_id" value="0">';
    }

    public function renderShippingHiddenFields(): void
    {
        echo '<input type="hidden" name="shipping_dexpress_town_id" id="shipping_dexpress_town_id" value="0">';
        echo '<input type="hidden" name="shipping_dexpress_street_id" id="shipping_dexpress_street_id" value="0">';
    }

    /**
     * Merge D Express POST fields into WC posted data so validators and saves see the same keys.
     *
     * @param array<string, mixed> $posted_data
     * @return array<string, mixed>
     */
    public function injectPostedData(array $posted_data): array
    {
        $posted_data = $this->mergeClassicCheckoutPostOverrides($posted_data);

        // Billing: never wipe WC-provided values on AJAX partial updates; fill from $_POST only when empty.
        if ($this->isEmptyPostedScalar($posted_data['billing_dexpress_street_number'] ?? null)
            && !$this->isEmptyPostedScalar($_POST['billing_dexpress_street_number'] ?? null)) {
            $posted_data['billing_dexpress_street_number'] = wc_clean(
                wp_unslash((string) $_POST['billing_dexpress_street_number']),
            );
        }

        if ($this->isEmptyPostedScalar($posted_data['billing_dexpress_town_id'] ?? null)
            && !$this->isEmptyPostedScalar($_POST['billing_dexpress_town_id'] ?? null)) {
            $posted_data['billing_dexpress_town_id'] = wc_clean(
                wp_unslash((string) $_POST['billing_dexpress_town_id']),
            );
        }

        if ($this->isEmptyPostedScalar($posted_data['billing_dexpress_street_id'] ?? null)
            && !$this->isEmptyPostedScalar($_POST['billing_dexpress_street_id'] ?? null)) {
            $posted_data['billing_dexpress_street_id'] = wc_clean(
                wp_unslash((string) $_POST['billing_dexpress_street_id']),
            );
        }

        // Ensure IDs exist as strings for WC posted_data consistency when only POST has them.
        if (!isset($posted_data['billing_dexpress_town_id'])) {
            $posted_data['billing_dexpress_town_id'] = '0';
        }
        if (!isset($posted_data['billing_dexpress_street_id'])) {
            $posted_data['billing_dexpress_street_id'] = '0';
        }

        $shipDifferent = !empty($posted_data['ship_to_different_address']) || !empty($_POST['ship_to_different_address']);

        if ($shipDifferent) {
            if ($this->isEmptyPostedScalar($posted_data['shipping_dexpress_street_number'] ?? null)
                && !$this->isEmptyPostedScalar($_POST['shipping_dexpress_street_number'] ?? null)) {
                $posted_data['shipping_dexpress_street_number'] = wc_clean(
                    wp_unslash((string) $_POST['shipping_dexpress_street_number']),
                );
            }

            if ($this->isEmptyPostedScalar($posted_data['shipping_dexpress_town_id'] ?? null)
                && !$this->isEmptyPostedScalar($_POST['shipping_dexpress_town_id'] ?? null)) {
                $posted_data['shipping_dexpress_town_id'] = wc_clean(
                    wp_unslash((string) $_POST['shipping_dexpress_town_id']),
                );
            }

            if ($this->isEmptyPostedScalar($posted_data['shipping_dexpress_street_id'] ?? null)
                && !$this->isEmptyPostedScalar($_POST['shipping_dexpress_street_id'] ?? null)) {
                $posted_data['shipping_dexpress_street_id'] = wc_clean(
                    wp_unslash((string) $_POST['shipping_dexpress_street_id']),
                );
            }

            if (!isset($posted_data['shipping_dexpress_town_id'])) {
                $posted_data['shipping_dexpress_town_id'] = '0';
            }
            if (!isset($posted_data['shipping_dexpress_street_id'])) {
                $posted_data['shipping_dexpress_street_id'] = '0';
            }
        } else {
            // Same as billing — parity with block “single address” behaviour and fixes shipment read when WC sets shipping from billing.
            if ($this->isEmptyPostedScalar($posted_data['shipping_dexpress_town_id'] ?? null)) {
                $posted_data['shipping_dexpress_town_id'] = (string) ($posted_data['billing_dexpress_town_id'] ?? '0');
            }
            if ($this->isEmptyPostedScalar($posted_data['shipping_dexpress_street_id'] ?? null)) {
                $posted_data['shipping_dexpress_street_id'] = (string) ($posted_data['billing_dexpress_street_id'] ?? '0');
            }
            if ($this->isEmptyPostedScalar($posted_data['shipping_dexpress_street_number'] ?? null)) {
                $posted_data['shipping_dexpress_street_number'] = (string) ($posted_data['billing_dexpress_street_number'] ?? '');
            }
        }

        return $posted_data;
    }

    /**
     * Classic shortcode checkout: WooCommerce sometimes omits fields from $posted_data during AJAX passes.
     * Mirror block checkout by hydrating from raw POST when merged data is empty.
     *
     * @param array<string, mixed> $posted_data
     * @return array<string, mixed>
     */
    private function mergeClassicCheckoutPostOverrides(array $posted_data): array
    {
        $billingKeys = [
            'billing_city',
            'billing_address_1',
            'billing_address_2',
            'billing_dexpress_street_number',
            'billing_dexpress_town_id',
            'billing_dexpress_street_id',
        ];

        foreach ($billingKeys as $key) {
            if (!$this->isEmptyPostedScalar($posted_data[$key] ?? null)) {
                continue;
            }

            if (!isset($_POST[$key])) {
                continue;
            }

            $posted_data[$key] = sanitize_text_field(wp_unslash((string) $_POST[$key]));
        }

        $shipDifferent = !empty($posted_data['ship_to_different_address']) || !empty($_POST['ship_to_different_address']);

        if ($shipDifferent) {
            $shippingKeys = [
                'shipping_city',
                'shipping_address_1',
                'shipping_address_2',
                'shipping_dexpress_street_number',
                'shipping_dexpress_town_id',
                'shipping_dexpress_street_id',
            ];

            foreach ($shippingKeys as $key) {
                if (!$this->isEmptyPostedScalar($posted_data[$key] ?? null)) {
                    continue;
                }

                if (!isset($_POST[$key])) {
                    continue;
                }

                $posted_data[$key] = sanitize_text_field(wp_unslash((string) $_POST[$key]));
            }
        }

        return $posted_data;
    }

    /**
     * Classic checkout: save D Express address meta for billing and shipping.
     *
     * @param array<string, mixed> $data Posted + injected checkout data
     */
    public function saveOrderMeta(WC_Order $order, array $data): void
    {
        // Logging happens in saveOrderMetaLate after WC core meta updates.
        $this->persistClassicCheckoutMeta($order, $data, false);
    }

    /**
     * Second pass after WooCommerce updates core order meta — keeps classic checkout aligned with blocks.
     *
     * @param array<string, mixed> $data
     */
    public function saveOrderMetaLate(int $order_id, array $data): void
    {
        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order) {
            return;
        }

        $this->persistClassicCheckoutMeta($order, $data, true);
    }

    /**
     * Block checkout (Store API): fires instead of woocommerce_checkout_create_order.
     * Billing/shipping data comes from JSON request body, not $_POST.
     */
    public function saveOrderMetaBlock(WC_Order $order, WP_REST_Request $request): void
    {
        $billing  = (array) ($request->get_param('billing_address') ?? []);
        $shipping = (array) ($request->get_param('shipping_address') ?? []);

        // Additional fields registered via woocommerce_register_additional_checkout_field
        // are merged into billing_address / shipping_address under their full 'namespace/key'.
        $bTownId = absint($billing['dexpress/town-id'] ?? 0);
        $bStreetId = absint($billing['dexpress/street-id'] ?? 0);
        $bStreetName = sanitize_text_field((string) ($billing['address_1'] ?? ''));
        $bStreetId = $this->resolveStreetIdIfMissing($bTownId, $bStreetName, $bStreetId);

        $billingAddr = [
            'town_name'     => sanitize_text_field((string) ($billing['city'] ?? '')),
            'street_name'   => $bStreetName,
            'street_number' => sanitize_text_field((string) ($billing['dexpress/street-number'] ?? '')),
            'address_desc'  => sanitize_text_field((string) ($billing['address_2'] ?? '')),
            'town_id'       => $bTownId,
            'street_id'     => $bStreetId,
        ];

        $this->saveBillingMeta($order, $billingAddr);

        if (!empty($shipping)) {
            $sTownId = absint($shipping['dexpress/town-id'] ?? 0);
            $sStreetId = absint($shipping['dexpress/street-id'] ?? 0);
            $sStreetName = sanitize_text_field((string) ($shipping['address_1'] ?? ''));
            $sStreetId = $this->resolveStreetIdIfMissing($sTownId, $sStreetName, $sStreetId);

            $this->saveShippingMeta($order, [
                'town_name'     => sanitize_text_field((string) ($shipping['city'] ?? '')),
                'street_name'   => $sStreetName,
                'street_number' => sanitize_text_field((string) ($shipping['dexpress/street-number'] ?? '')),
                'address_desc'  => sanitize_text_field((string) ($shipping['address_2'] ?? '')),
                'town_id'       => $sTownId,
                'street_id'     => $sStreetId,
            ]);
        } else {
            $this->saveShippingMeta($order, $billingAddr);
        }

        $phone = sanitize_text_field((string) ($billing['phone'] ?? ''));
        if ($phone !== '') {
            try {
                $canonical = PhoneNumber::fromString($phone)->canonical();
                $order->set_billing_phone($canonical);
                $order->update_meta_data('_billing_phone_api_format', $canonical);
            } catch (\InvalidArgumentException) {
            }
        }

        $order->save();
        $this->logCheckoutPersistedSummary($order, 'block_store_api');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function persistClassicCheckoutMeta(WC_Order $order, array $data, bool $logSavedMeta): void
    {
        $billingTownId = $this->intFromData($data, 'billing_dexpress_town_id', $_POST['billing_dexpress_town_id'] ?? null);
        $billingStreetId = $this->intFromData($data, 'billing_dexpress_street_id', $_POST['billing_dexpress_street_id'] ?? null);
        $billingTownName = $this->checkoutTextField($data, $_POST, 'billing_city');
        $billingStreetName = $this->checkoutTextField($data, $_POST, 'billing_address_1');
        $billingStreetNumber = $this->checkoutTextField($data, $_POST, 'billing_dexpress_street_number');
        $billingDesc = $this->checkoutTextField($data, $_POST, 'billing_address_2');

        $billingStreetId = $this->resolveStreetIdIfMissing($billingTownId, $billingStreetName, $billingStreetId);

        $billingAddr = [
            'town_name'     => $billingTownName,
            'street_name'   => $billingStreetName,
            'street_number' => $billingStreetNumber,
            'address_desc'  => $billingDesc,
            'town_id'       => $billingTownId,
            'street_id'     => $billingStreetId,
        ];

        $this->saveBillingMeta($order, $billingAddr);

        $shipToDifferent = !empty($data['ship_to_different_address']) || !empty($_POST['ship_to_different_address']);

        if ($shipToDifferent) {
            $shippingTownId = $this->intFromData($data, 'shipping_dexpress_town_id', $_POST['shipping_dexpress_town_id'] ?? null);
            $shippingStreetId = $this->intFromData($data, 'shipping_dexpress_street_id', $_POST['shipping_dexpress_street_id'] ?? null);
            $shippingTownName = $this->checkoutTextField($data, $_POST, 'shipping_city');
            $shippingStreetName = $this->checkoutTextField($data, $_POST, 'shipping_address_1');
            $shippingStreetNumber = $this->checkoutTextField($data, $_POST, 'shipping_dexpress_street_number');
            $shippingDesc = $this->checkoutTextField($data, $_POST, 'shipping_address_2');

            $shippingStreetId = $this->resolveStreetIdIfMissing($shippingTownId, $shippingStreetName, $shippingStreetId);

            $this->saveShippingMeta($order, [
                'town_name'     => $shippingTownName,
                'street_name'   => $shippingStreetName,
                'street_number' => $shippingStreetNumber,
                'address_desc'  => $shippingDesc,
                'town_id'       => $shippingTownId,
                'street_id'     => $shippingStreetId,
            ]);
        } else {
            // Same-address checkout: WC may still set has_shipping_address() — persist shipping meta = billing (block parity).
            $this->saveShippingMeta($order, $billingAddr);
        }

        $rawPhone = trim($order->get_billing_phone());
        if ($rawPhone === '') {
            $rawPhone = $this->checkoutTextField($data, $_POST, 'billing_phone');
        }
        if ($rawPhone !== '') {
            try {
                $phone = PhoneNumber::fromString($rawPhone);
                $order->update_meta_data('_billing_phone_api_format', $phone->canonical());
            } catch (\InvalidArgumentException) {
                // CheckoutValidator already flagged invalid phones
            }
        }

        $order->save();

        if ($logSavedMeta) {
            $this->logCheckoutPersistedSummary($order, 'classic_checkout');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function intFromData(array $data, string $key, mixed $postFallback): int
    {
        if (isset($data[$key]) && $data[$key] !== '') {
            return absint($data[$key]);
        }

        return absint($postFallback ?? 0);
    }

    /**
     * Classic / AJAX checkout: $data may omit fields during some WC passes — always fall back to raw POST.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $post
     */
    private function checkoutTextField(array $data, array $post, string $key): string
    {
        $fromData = isset($data[$key]) ? wp_unslash((string) $data[$key]) : '';
        if (trim($fromData) !== '') {
            return sanitize_text_field($fromData);
        }

        return sanitize_text_field(wp_unslash((string) ($post[$key] ?? '')));
    }

    private function logCheckoutPersistedSummary(WC_Order $order, string $context): void
    {
        $phone = (string) $order->get_meta('_billing_phone_api_format');
        $masked = $phone !== '' && strlen($phone) > 5 ? substr($phone, 0, 5) . '***' : ($phone !== '' ? '***' : '');

        $line = sprintf(
            '[CHECKOUT] ctx=%s order=%d billing town=%s street_id=%s street=%s num=%s ship town=%s street_id=%s street=%s num=%s phone=%s',
            $context,
            $order->get_id(),
            (string) $order->get_meta('_billing_town_id'),
            (string) $order->get_meta('_billing_street_id'),
            mb_substr((string) $order->get_meta('_billing_street_name'), 0, 50),
            (string) $order->get_meta('_billing_street_number'),
            (string) $order->get_meta('_shipping_town_id'),
            (string) $order->get_meta('_shipping_street_id'),
            mb_substr((string) $order->get_meta('_shipping_street_name'), 0, 50),
            (string) $order->get_meta('_shipping_street_number'),
            $masked,
        );

        $this->logger->info($line);
    }

    /**
     * @param array{town_name: string, street_name: string, street_number: string, address_desc: string, town_id: int, street_id: int} $addr
     */
    private function saveBillingMeta(WC_Order $order, array $addr): void
    {
        $fullAddress = trim($addr['street_name'] . ($addr['street_number'] !== '' ? ' ' . $addr['street_number'] : ''));
        $order->set_billing_address_1($fullAddress);

        $order->update_meta_data('_billing_town_id', $addr['town_id']);
        $order->update_meta_data('_billing_street_name', $addr['street_name']);
        $order->update_meta_data('_billing_street_number', $addr['street_number']);
        $order->update_meta_data('_billing_address_desc', $addr['address_desc']);

        if ($addr['street_id'] > 0) {
            $order->update_meta_data('_billing_street_id', $addr['street_id']);
        } else {
            $order->delete_meta_data('_billing_street_id');
        }
    }

    /**
     * @param array{town_name: string, street_name: string, street_number: string, address_desc: string, town_id: int, street_id: int} $addr
     */
    private function saveShippingMeta(WC_Order $order, array $addr): void
    {
        $fullAddress = trim($addr['street_name'] . ($addr['street_number'] !== '' ? ' ' . $addr['street_number'] : ''));
        $order->set_shipping_address_1($fullAddress);

        $order->update_meta_data('_shipping_town_id', $addr['town_id']);
        $order->update_meta_data('_shipping_street_name', $addr['street_name']);
        $order->update_meta_data('_shipping_street_number', $addr['street_number']);
        $order->update_meta_data('_shipping_address_desc', $addr['address_desc']);

        if ($addr['street_id'] > 0) {
            $order->update_meta_data('_shipping_street_id', $addr['street_id']);
        } else {
            $order->delete_meta_data('_shipping_street_id');
        }
    }

    /**
     * Register "Broj" as an additional block checkout field (WC 8.6+).
     * Appears in both billing and shipping sections in the block checkout.
     */
    public function registerBlockField(): void
    {
        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        // Street number — na checkout formi vidljivo; u potvrdi naloga sakriveno (broj je u address_1).
        woocommerce_register_additional_checkout_field([
            'id'                         => 'dexpress/street-number',
            'label'                      => __('Broj', 'dexpress-woocommerce'),
            'location'                   => 'address',
            'required'                   => false,
            'type'                       => 'text',
            'show_in_order_confirmation' => false,
        ]);

        // Town ID and Street ID — hidden via CSS, populated by JS autocomplete.
        // This is the only way to pass custom IDs through the Store API JSON payload.
        woocommerce_register_additional_checkout_field([
            'id'                         => 'dexpress/town-id',
            'label'                      => __('Interni ID mesta', 'dexpress-woocommerce'),
            'location'                   => 'address',
            'required'                   => false,
            'type'                       => 'text',
            'show_in_order_confirmation' => false,
        ]);

        woocommerce_register_additional_checkout_field([
            'id'                         => 'dexpress/street-id',
            'label'                      => __('Interni ID ulice', 'dexpress-woocommerce'),
            'location'                   => 'address',
            'required'                   => false,
            'type'                       => 'text',
            'show_in_order_confirmation' => false,
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    public function hideInternalIdsFromEmailOrderMeta(array $fields, bool $sentToAdmin, WC_Order $order): array
    {
        unset($sentToAdmin, $order);

        foreach (array_keys($fields) as $key) {
            if ($this->isInternalCheckoutMetaKey((string) $key)) {
                unset($fields[$key]);
            }
        }

        return $fields;
    }

    private function isInternalCheckoutMetaKey(string $key): bool
    {
        if ($key === '_billing_town_id'
            || $key === '_billing_street_id'
            || $key === '_shipping_town_id'
            || $key === '_shipping_street_id') {
            return true;
        }

        return str_contains($key, 'dexpress/town-id')
            || str_contains($key, 'dexpress/street-id')
            || str_contains($key, 'dexpress/street-number')
            || str_contains($key, 'dexpress_town_id')
            || str_contains($key, 'dexpress_street_id');
    }

    /**
     * Uklanja tehnička dodatna polja blok checkuta iz admin ekrana porudžbine (duplikat meta već u _billing_* / adresi).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function stripTechnicalAdditionalFieldsFromAdmin(array $fields, mixed $order = null, string $context = 'edit'): array
    {
        unset($order, $context);

        foreach ($fields as $key => $field) {
            if (!is_array($field)) {
                continue;
            }

            $fid = isset($field['id']) ? (string) $field['id'] : '';
            if ($fid !== '' && $this->isTechnicalWooCommerceAdditionalFieldId($fid)) {
                unset($fields[$key]);
            }
        }

        return $fields;
    }

    private function isTechnicalWooCommerceAdditionalFieldId(string $fieldId): bool
    {
        foreach (['_wc_billing/', '_wc_shipping/'] as $prefix) {
            if (!str_starts_with($fieldId, $prefix)) {
                continue;
            }

            $suffix = substr($fieldId, strlen($prefix));

            return in_array($suffix, self::TECHNICAL_CHECKOUT_FIELD_IDS, true);
        }

        return false;
    }

    public function wireBlocksCheckoutFieldDisplay(): void
    {
        if (self::$blocksCheckoutDisplayWired) {
            return;
        }

        if (!class_exists(\Automattic\WooCommerce\Blocks\Package::class)) {
            return;
        }

        try {
            $container = \Automattic\WooCommerce\Blocks\Package::container();
            $frontend  = $container->get(\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsFrontend::class);
        } catch (\Throwable) {
            return;
        }

        remove_action('woocommerce_order_details_after_customer_address', [$frontend, 'render_order_address_fields'], 10);
        add_action('woocommerce_order_details_after_customer_address', [$this, 'renderOrderAddressFieldsWithoutInternalIds'], 10, 2);

        remove_action('woocommerce_my_account_after_my_address', [$frontend, 'render_address_fields'], 10);
        add_action('woocommerce_my_account_after_my_address', [$this, 'renderMyAccountAddressFieldsWithoutInternalIds'], 10, 1);

        self::$blocksCheckoutDisplayWired = true;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed>                $context
     */
    public function hideInternalCheckoutFieldOrderConfirmation(
        bool $show,
        array $field,
        array $fields,
        array $context,
        mixed $checkoutFieldsService,
    ): bool {
        unset($fields, $context, $checkoutFieldsService);

        $id = (string) ($field['id'] ?? '');

        return in_array($id, self::TECHNICAL_CHECKOUT_FIELD_IDS, true) ? false : $show;
    }

    /**
     * Uklanja interne ID-jeve iz JSON odgovora Store API rute order (blok „Thank you“, npr. /wc/store/v1/order/123).
     */
    public function stripInternalIdsFromStoreApiOrderResponse(
        mixed $response,
        WP_REST_Server $server,
        WP_REST_Request $request,
    ): mixed {
        unset($server);

        if (!$response instanceof WP_REST_Response) {
            return $response;
        }

        $route = (string) $request->get_route();
        if ($route === '' || !preg_match('#^/wc/store/v\d+/order/\d+#', $route)) {
            return $response;
        }

        $data = $response->get_data();
        if (!is_array($data)) {
            return $response;
        }

        foreach (['billing_address', 'shipping_address'] as $addrKey) {
            if (!isset($data[$addrKey]) || !is_array($data[$addrKey])) {
                continue;
            }
            foreach (self::TECHNICAL_CHECKOUT_FIELD_IDS as $k) {
                unset($data[$addrKey][$k]);
            }
        }

        $response->set_data($data);

        return $response;
    }

    /**
     * Uklanja interna polja iz SSR izlaza blokova potvrde porudžbine (WC ne primenjuje filter_fields_for_order_confirmation).
     *
     * @param array<string, mixed> $parsed_block
     */
    public function stripInternalIdsFromOrderConfirmationBlocks(string $block_content, array $parsed_block): string
    {
        $name = (string) ($parsed_block['blockName'] ?? '');
        if (!in_array($name, [
            'woocommerce/order-confirmation-billing-address',
            'woocommerce/order-confirmation-shipping-address',
        ], true)) {
            return $block_content;
        }

        $labels = [
            __('Interni ID mesta', 'dexpress-woocommerce'),
            __('Interni ID ulice', 'dexpress-woocommerce'),
            __('Broj', 'dexpress-woocommerce'),
        ];

        $out = $block_content;
        foreach ($labels as $label) {
            $q = preg_quote($label, '#');
            $replaced = preg_replace('#<dt>\s*' . $q . '\s*</dt>\s*<dd>[^<]*</dd>#iu', '', $out);
            if (is_string($replaced)) {
                $out = $replaced;
            }
        }

        return $out;
    }

    /**
     * Klasičan checkout: telefon na porudžbini kao cifre 381… (bez +), u skladu sa D Express API.
     *
     * @param array<string, mixed> $data
     */
    public function normalizeCheckoutOrderPhones(WC_Order $order, array $data): void
    {
        if ((string) ($data['billing_country'] ?? '') !== 'RS') {
            return;
        }

        $raw = trim((string) ($data['billing_phone'] ?? ''));
        if ($raw !== '') {
            try {
                $order->set_billing_phone(PhoneNumber::fromString($raw)->canonical());
            } catch (\InvalidArgumentException) {
            }
        }

        $shipDifferent = !empty($data['ship_to_different_address']);
        $shipPhone     = trim((string) ($data['shipping_phone'] ?? ''));
        if ($shipDifferent && $shipPhone !== '' && (string) ($data['shipping_country'] ?? '') === 'RS') {
            try {
                $order->set_shipping_phone(PhoneNumber::fromString($shipPhone)->canonical());
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function renderOrderAddressFieldsWithoutInternalIds(string $addressType, WC_Order $order): void
    {
        if (!class_exists(\Automattic\WooCommerce\Blocks\Package::class)) {
            return;
        }

        try {
            $controller = \Automattic\WooCommerce\Blocks\Package::container()->get(
                \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class,
            );
        } catch (\Throwable) {
            return;
        }

        $fields = $controller->get_order_additional_fields_with_values($order, 'address', $addressType, 'view');
        foreach (self::TECHNICAL_CHECKOUT_FIELD_IDS as $k) {
            unset($fields[$k]);
        }

        if ($fields === []) {
            return;
        }

        echo '<dl class="wc-block-components-additional-fields-list">';

        foreach ($fields as $field) {
            printf(
                '<dt>%s</dt><dd>%s</dd>',
                esc_html((string) ($field['label'] ?? '')),
                esc_html((string) ($field['value'] ?? '')),
            );
        }

        echo '</dl>';
    }

    public function renderMyAccountAddressFieldsWithoutInternalIds(string $addressType): void
    {
        if (!in_array($addressType, ['billing', 'shipping'], true)) {
            return;
        }

        if (!class_exists(\Automattic\WooCommerce\Blocks\Package::class)) {
            return;
        }

        try {
            $controller = \Automattic\WooCommerce\Blocks\Package::container()->get(
                \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class,
            );
        } catch (\Throwable) {
            return;
        }

        $customer = new \WC_Customer(get_current_user_id());

        if ($customer->get_id() <= 0) {
            return;
        }

        $documentObject = new \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsSchema\DocumentObject();
        $documentObject->set_customer($customer);
        $documentObject->set_context($addressType . '_address');
        $fields = $controller->get_contextual_fields_for_location('address', $documentObject);

        if ($fields === []) {
            return;
        }

        foreach ($fields as $key => $field) {
            if (in_array($key, self::TECHNICAL_CHECKOUT_FIELD_IDS, true)) {
                continue;
            }

            $value = $controller->format_additional_field_value(
                $controller->get_field_from_object($key, $customer, $addressType),
                $field,
            );

            if ($value === '' || $value === null) {
                continue;
            }

            printf(
                '<br><strong>%s</strong>: %s',
                wp_kses_post((string) ($field['label'] ?? '')),
                wp_kses_post((string) $value),
            );
        }
    }

    /**
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    public function stripInternalIdsFromAddressEditor(array $address, string $addressType): array
    {
        unset($addressType);

        foreach (array_keys($address) as $key) {
            if (!is_string($key)) {
                continue;
            }

            foreach (self::TECHNICAL_CHECKOUT_FIELD_IDS as $internal) {
                if (str_contains($key, $internal)) {
                    unset($address[$key]);
                    break;
                }
            }
        }

        return $address;
    }

    /**
     * Force autocomplete="off" on every checkout field so browsers won't pre-fill them.
     * Runs at priority 200, after WC and our own modifyFields have set their values.
     *
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    public function disableAutocomplete(array $fields): array
    {
        foreach ($fields as $section => $sectionFields) {
            foreach (array_keys($sectionFields) as $key) {
                $fields[$section][$key]['autocomplete'] = 'off';
            }
        }

        return $fields;
    }

    private function isEmptyPostedScalar(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 0;
        }

        return trim((string) $value) === '';
    }

    private function resolveStreetIdIfMissing(int $townId, string $streetName, int $streetId): int
    {
        if ($streetId > 0 || $townId <= 0 || trim($streetName) === '') {
            return $streetId;
        }

        return $this->addressSearch->findStreetIdExact($townId, $streetName) ?? 0;
    }

    private function isBlockCheckoutPage(): bool
    {
        $pageId = wc_get_page_id('checkout');
        return $pageId > 0 && has_block('woocommerce/checkout', $pageId);
    }

    public function enqueueAssets(): void
    {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_style(
            'dexpress-checkout',
            DEXPRESS_PLUGIN_URL . 'assets/css/checkout.css',
            ['woocommerce-layout'],
            DEXPRESS_VERSION,
        );

        wp_enqueue_script(
            'dexpress-checkout',
            DEXPRESS_PLUGIN_URL . 'assets/js/checkout.js',
            ['jquery'],
            DEXPRESS_VERSION,
            true,
        );

        wp_localize_script('dexpress-checkout', 'dexpress_checkout', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('dexpress_checkout'),
            'is_block' => $this->isBlockCheckoutPage(),
            'i18n'     => [
                'select_town_first'  => __('Najpre odaberite mesto iz liste.', 'dexpress-woocommerce'),
                'no_results'         => __('Nema rezultata.', 'dexpress-woocommerce'),
                'min_chars'          => __('Unesite najmanje 2 karaktera.', 'dexpress-woocommerce'),
                'street_placeholder' => __('Unesite naziv ulice...', 'dexpress-woocommerce'),
                'phone_hint'         => __('Unesite srpski broj (npr. +381 64 123 4567)', 'dexpress-woocommerce'),
            ],
        ]);
    }
}
