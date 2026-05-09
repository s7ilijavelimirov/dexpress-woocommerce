<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Frontend\Checkout;

use S7codedesign\DExpress\Application\Address\RecipientAddressCheckService;
use S7codedesign\DExpress\Domain\Address\PhoneNumber;
use S7codedesign\DExpress\Domain\Address\StreetNumber;
use S7codedesign\DExpress\Presentation\Frontend\Shipping\DexpressPackageShopShippingMethod;
use WC_Order;
use WP_Error;

final class CheckoutValidator
{
    /** @see _v2-prep/api-docs/shipments-endpoint.md (RTownID / CTownID) */
    private const DEXPRESS_MIN_TOWN_ID = 100_000;

    private const DEXPRESS_MAX_TOWN_ID = 10_000_000;

    /** @see _v2-prep/api-docs/shipments-endpoint.md (RAddress) */
    private const DEXPRESS_MAX_STREET_LEN = 50;

    public function __construct(
        private readonly RecipientAddressCheckService $addressCheck,
    ) {}

    public function register(): void
    {
        add_action('woocommerce_checkout_process', [$this, 'validatePackageShopSelectionClassicProcess'], 20);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate'], 10, 2);

        if (\defined('WC_VERSION') && version_compare(\WC_VERSION, '9.9.0', '>=')) {
            add_action('woocommerce_checkout_validate_order_before_payment', [$this, 'validateOrderBeforePayment'], 10, 2);
        }
    }

    /**
     * Works for both classic checkout ($data from get_posted_data / $_POST)
     * and block checkout ($data from Store API JSON body via get_request_data).
     * Never read $_POST directly — block checkout sends JSON, not form-encoded data.
     *
     * @param array<string, mixed> $data
     */
    public function validate(array $data, WP_Error $errors): void
    {
        $this->validateCheckoutData($data, $errors);
    }

    /**
     * Blok checkout (Store API): ista pravila kao klasičan, pre naplate (WC 9.9+).
     */
    public function validateOrderBeforePayment(WC_Order $order, WP_Error $errors): void
    {
        if ($order->get_billing_country() !== 'RS') {
            return;
        }

        $data = $this->buildValidationDataFromOrder($order);
        $this->validateCheckoutData($data, $errors);

        if ($errors->has_errors()) {
            return;
        }

        $this->normalizeOrderPhones($order);
        $this->syncBillingPhoneApiMeta($order);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateCheckoutData(array $data, WP_Error $errors): void
    {
        $country = (string) ($data['billing_country'] ?? '');
        if ($country !== 'RS') {
            return;
        }

        // billing_city = town (label modified in CheckoutFields::modifyFields)
        $town = trim((string) ($data['billing_city'] ?? ''));
        if ($town === '') {
            $errors->add(
                'billing_city',
                __('<strong>Mesto</strong> je obavezno polje.', 'dexpress-woocommerce')
            );
        }

        // billing_address_1 = street name
        $street = trim((string) ($data['billing_address_1'] ?? ''));
        if ($street === '') {
            $errors->add(
                'billing_address_1',
                __('<strong>Ulica</strong> je obavezno polje.', 'dexpress-woocommerce')
            );
        } elseif (
            $this->addressCheck->checkoutUsesDexpressShipping($data)
            && mb_strlen($street, 'UTF-8') > self::DEXPRESS_MAX_STREET_LEN
        ) {
            $errors->add(
                'billing_address_1',
                sprintf(
                    /* translators: %d: max characters per D Express API */
                    __('Ulica: najviše %d karaktera (ulica bez broja, za D Express).', 'dexpress-woocommerce'),
                    self::DEXPRESS_MAX_STREET_LEN,
                ),
            );
        }

        // Street number — classic checkout uses billing_dexpress_street_number;
        // block checkout may nest additional fields under billing_address.
        $billingStreetNum = trim((string) ($data['billing_dexpress_street_number'] ?? ''));
        if ($billingStreetNum === '') {
            $billingStreetNum = trim((string) ($data['dexpress/street-number'] ?? ''));
        }
        if ($billingStreetNum === '' && isset($data['billing_address']['dexpress/street-number'])) {
            $billingStreetNum = trim((string) $data['billing_address']['dexpress/street-number']);
        }

        if ($billingStreetNum === '') {
            $errors->add(
                'billing_dexpress_street_number',
                __('<strong>Broj</strong> je obavezno polje.', 'dexpress-woocommerce')
            );
        } else {
            try {
                StreetNumber::fromString($billingStreetNum);
            } catch (\InvalidArgumentException $e) {
                $errors->add('billing_dexpress_street_number', $e->getMessage());
            }
        }

        if ($this->addressCheck->checkoutUsesDexpressShipping($data)) {
            $billingTownId = absint($data['billing_dexpress_town_id'] ?? 0);
            if ($billingTownId <= 0) {
                $errors->add(
                    'billing_dexpress_town_id',
                    __('<strong>Mesto</strong> mora biti izabrano iz liste (D Express).', 'dexpress-woocommerce'),
                );
            } elseif ($billingTownId < self::DEXPRESS_MIN_TOWN_ID || $billingTownId > self::DEXPRESS_MAX_TOWN_ID) {
                $errors->add(
                    'billing_dexpress_town_id',
                    __('ID mesta nije u opsegu koji D Express prihvata.', 'dexpress-woocommerce'),
                );
            }
        }

        $shipDiff    = !empty($data['ship_to_different_address']);
        $shipCountry = (string) ($data['shipping_country'] ?? '');

        if ($shipDiff && $shipCountry === 'RS') {
            $sTown = trim((string) ($data['shipping_city'] ?? ''));
            if ($sTown === '') {
                $errors->add(
                    'shipping_city',
                    __('<strong>Mesto (isporuka)</strong> je obavezno polje.', 'dexpress-woocommerce')
                );
            }

            $sStreet = trim((string) ($data['shipping_address_1'] ?? ''));
            if ($sStreet === '') {
                $errors->add(
                    'shipping_address_1',
                    __('<strong>Ulica (isporuka)</strong> je obavezno polje.', 'dexpress-woocommerce')
                );
            } elseif (
                $this->addressCheck->checkoutUsesDexpressShipping($data)
                && mb_strlen($sStreet, 'UTF-8') > self::DEXPRESS_MAX_STREET_LEN
            ) {
                $errors->add(
                    'shipping_address_1',
                    sprintf(
                        /* translators: %d: max characters per D Express API */
                        __('Ulica (isporuka): najviše %d karaktera (za D Express).', 'dexpress-woocommerce'),
                        self::DEXPRESS_MAX_STREET_LEN,
                    ),
                );
            }

            $sNum = trim((string) ($data['shipping_dexpress_street_number'] ?? ''));
            if ($sNum === '' && isset($data['shipping_address']['dexpress/street-number'])) {
                $sNum = trim((string) $data['shipping_address']['dexpress/street-number']);
            }

            if ($sNum === '') {
                $errors->add(
                    'shipping_dexpress_street_number',
                    __('<strong>Broj (isporuka)</strong> je obavezno polje.', 'dexpress-woocommerce')
                );
            } else {
                try {
                    StreetNumber::fromString($sNum);
                } catch (\InvalidArgumentException $e) {
                    $errors->add('shipping_dexpress_street_number', $e->getMessage());
                }
            }

            if ($this->addressCheck->checkoutUsesDexpressShipping($data)) {
                $shippingTownId = absint($data['shipping_dexpress_town_id'] ?? 0);
                if ($shippingTownId <= 0) {
                    $errors->add(
                        'shipping_dexpress_town_id',
                        __('<strong>Mesto (isporuka)</strong> mora biti izabrano iz liste (D Express).', 'dexpress-woocommerce'),
                    );
                } elseif ($shippingTownId < self::DEXPRESS_MIN_TOWN_ID || $shippingTownId > self::DEXPRESS_MAX_TOWN_ID) {
                    $errors->add(
                        'shipping_dexpress_town_id',
                        __('ID mesta isporuke nije u opsegu koji D Express prihvata.', 'dexpress-woocommerce'),
                    );
                }
            }
        }

        $phone = trim((string) ($data['billing_phone'] ?? ''));
        if ($phone !== '') {
            try {
                PhoneNumber::fromString($phone);
            } catch (\InvalidArgumentException $e) {
                $errors->add('billing_phone', $e->getMessage());
            }
        }

        if ($shipDiff) {
            $sPhone = trim((string) ($data['shipping_phone'] ?? ''));
            if ($sPhone !== '') {
                try {
                    PhoneNumber::fromString($sPhone);
                } catch (\InvalidArgumentException $e) {
                    $errors->add('shipping_phone', $e->getMessage());
                }
            }
        }

        if ($this->checkoutUsesPackageShopShipping($data)
            && did_action('woocommerce_checkout_process') === 0) {
            $selectedDispenserId = $this->extractCheckoutDispenserId($data);
            if ($selectedDispenserId === '') {
                $errors->add(
                    'dexpress_package_shop_dispenser',
                    __('Molimo vas odaberite paketomat pre naručivanja.', 'dexpress-woocommerce'),
                );
            }
        }

        if (!$errors->has_errors()) {
            $this->addressCheck->validateCheckoutBlocking($data, $errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildValidationDataFromOrder(WC_Order $order): array
    {
        $shippingMethods = [];
        foreach ($order->get_shipping_methods() as $shipItem) {
            $shippingMethods[] = $shipItem->get_method_id() . ':' . $shipItem->get_instance_id();
        }

        $shipDiff = $this->orderUsesDistinctShippingAddress($order);

        $data = [
            'shipping_method'               => $shippingMethods,
            'billing_country'             => $order->get_billing_country(),
            'billing_city'                => $order->get_billing_city(),
            'billing_address_1'           => (string) $order->get_meta('_billing_street_name'),
            'billing_address_2'           => $order->get_billing_address_2(),
            'billing_phone'               => $order->get_billing_phone(),
            'billing_first_name'          => $order->get_billing_first_name(),
            'billing_last_name'           => $order->get_billing_last_name(),
            'billing_dexpress_street_number' => (string) $order->get_meta('_billing_street_number'),
            'billing_dexpress_town_id'      => (string) (int) $order->get_meta('_billing_town_id'),
            'billing_dexpress_street_id'    => (string) (int) $order->get_meta('_billing_street_id'),
            'dexpress_checkout_dispenser_id' => (string) $order->get_meta('_dexpress_package_shop_location_id'),
            'ship_to_different_address'     => $shipDiff ? 1 : 0,
        ];

        if ($shipDiff) {
            $data['shipping_country']             = $order->get_shipping_country();
            $data['shipping_city']                = $order->get_shipping_city();
            $data['shipping_address_1']           = (string) $order->get_meta('_shipping_street_name');
            $data['shipping_address_2']           = $order->get_shipping_address_2();
            $data['shipping_dexpress_street_number'] = (string) $order->get_meta('_shipping_street_number');
            $data['shipping_dexpress_town_id']      = (string) (int) $order->get_meta('_shipping_town_id');
            $data['shipping_dexpress_street_id']    = (string) (int) $order->get_meta('_shipping_street_id');
            $data['shipping_first_name']           = $order->get_shipping_first_name();
            $data['shipping_last_name']            = $order->get_shipping_last_name();
            $data['shipping_phone']                = $order->get_shipping_phone() ?: $order->get_billing_phone();
        }

        return $data;
    }

    public function validatePackageShopSelectionClassicProcess(): void
    {
        $methods = isset($_POST['shipping_method']) ? (array) wp_unslash($_POST['shipping_method']) : [];
        if ($methods === []) {
            return;
        }

        $usesPackageShop = false;
        foreach ($methods as $method) {
            if (!is_string($method)) {
                continue;
            }

            if (str_starts_with($method, DexpressPackageShopShippingMethod::METHOD_ID)) {
                $usesPackageShop = true;
                break;
            }
        }

        if (!$usesPackageShop) {
            return;
        }

        $selectedDispenserId = sanitize_text_field(wp_unslash((string) ($_POST['dexpress_checkout_dispenser_id'] ?? $_POST['dexpress_checkout_location_id'] ?? '')));
        if ($selectedDispenserId === '') {
            wc_add_notice(__('Molimo vas odaberite paketomat pre naručivanja.', 'dexpress-woocommerce'), 'error');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function checkoutUsesPackageShopShipping(array $data): bool
    {
        $methods = (array) ($data['shipping_method'] ?? []);

        foreach ($methods as $method) {
            if (!is_string($method)) {
                continue;
            }

            if (str_starts_with($method, DexpressPackageShopShippingMethod::METHOD_ID)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractCheckoutDispenserId(array $data): string
    {
        $id = trim((string) ($data['dexpress_checkout_dispenser_id'] ?? $data['dexpress_checkout_location_id'] ?? ''));
        if ($id !== '') {
            return $id;
        }

        $additional = $data['additional_fields'] ?? null;
        if (is_array($additional)) {
            $id = trim((string) ($additional['dexpress_checkout_dispenser_id'] ?? $additional['dexpress_checkout_location_id'] ?? ''));
            if ($id !== '') {
                return $id;
            }
        }

        return '';
    }

    private function orderUsesDistinctShippingAddress(WC_Order $order): bool
    {
        if (!$order->needs_shipping_address()) {
            return false;
        }

        return (string) $order->get_meta('_shipping_street_name') !== (string) $order->get_meta('_billing_street_name')
            || (int) $order->get_meta('_shipping_town_id') !== (int) $order->get_meta('_billing_town_id')
            || (string) $order->get_meta('_shipping_street_number') !== (string) $order->get_meta('_billing_street_number');
    }

    private function normalizeOrderPhones(WC_Order $order): void
    {
        $b = trim($order->get_billing_phone());
        if ($b !== '') {
            try {
                $order->set_billing_phone(PhoneNumber::fromString($b)->canonical());
            } catch (\InvalidArgumentException) {
            }
        }

        $s = trim((string) $order->get_shipping_phone());
        if ($s !== '') {
            try {
                $order->set_shipping_phone(PhoneNumber::fromString($s)->canonical());
            } catch (\InvalidArgumentException) {
            }
        }
    }

    private function syncBillingPhoneApiMeta(WC_Order $order): void
    {
        $p = trim($order->get_billing_phone());
        if ($p === '') {
            return;
        }

        try {
            $order->update_meta_data('_billing_phone_api_format', PhoneNumber::fromString($p)->canonical());
        } catch (\InvalidArgumentException) {
        }
    }
}
