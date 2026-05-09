<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Frontend\Checkout;

use S7codedesign\DExpress\Presentation\Frontend\Shipping\DexpressPackageShopShippingMethod;

final class PackageShopInfoPanel
{
    public function register(): void
    {
        add_action('woocommerce_after_shipping_rate', [$this, 'renderForClassicCheckoutRate'], 30, 2);
        add_action('woocommerce_after_checkout_form', [$this, 'renderBlockCheckoutHost'], 30, 1);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_footer', [$this, 'renderModalTemplate'], 40);
    }

    public function renderForClassicCheckoutRate(mixed $rate, mixed $index): void
    {
        unset($index);

        if (!is_checkout() || $this->isBlockCheckoutPage()) {
            return;
        }

        if (!$rate instanceof \WC_Shipping_Rate) {
            return;
        }

        if ($rate->get_method_id() !== DexpressPackageShopShippingMethod::METHOD_ID) {
            return;
        }

        $method = new DexpressPackageShopShippingMethod($rate->get_instance_id());
        $this->renderTemplate(
            $rate->get_id(),
            $method->getIntroText(),
            $this->resolveDeliveryPriceText((float) $rate->get_cost(), $method),
            $method->getDeliveryTimeText(),
            $method->getStepsText(),
            $method->getModalInfoText(),
            $method->getGoogleMapsApiKey(),
        );
    }

    public function renderBlockCheckoutHost(mixed $checkout = null): void
    {
        unset($checkout);

        if (!is_checkout() || !$this->isBlockCheckoutPage()) {
            return;
        }

        $panels = $this->buildPanelsForAvailableRates();
        if ($panels === []) {
            return;
        }

        echo '<div id="dexpress-package-shop-block-host" class="dexpress-package-shop-block-host" data-dexpress-package-shop-host="1">';

        foreach ($panels as $panel) {
            $this->renderTemplate(
                (string) $panel['rate_id'],
                (string) $panel['intro_text'],
                (string) $panel['delivery_price_text'],
                (string) $panel['delivery_time_text'],
                (string) $panel['steps_text'],
                (string) $panel['modal_info_text'],
                (string) $panel['google_maps_api_key'],
            );
        }

        echo '</div>';
    }

    public function enqueueAssets(): void
    {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_script(
            'dexpress-google-maps',
            $this->resolveGoogleMapsScriptUrl(),
            [],
            null,
            true,
        );

        wp_enqueue_script(
            'dexpress-google-markerclusterer',
            'https://unpkg.com/@googlemaps/markerclusterer/dist/index.min.js',
            ['dexpress-google-maps'],
            null,
            true,
        );

        wp_enqueue_style(
            'dexpress-package-shop-checkout',
            DEXPRESS_PLUGIN_URL . 'assets/css/package-shop-checkout.css',
            ['dexpress-checkout'],
            DEXPRESS_VERSION,
        );

        wp_enqueue_script(
            'dexpress-package-shop-checkout',
            DEXPRESS_PLUGIN_URL . 'assets/js/package-shop-checkout.js',
            ['jquery', 'dexpress-google-maps', 'dexpress-google-markerclusterer'],
            DEXPRESS_VERSION,
            true,
        );

        wp_localize_script('dexpress-package-shop-checkout', 'dexpress_package_shop', [
            'method_id'         => DexpressPackageShopShippingMethod::METHOD_ID,
            'ajax_url'          => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce('dexpress_package_shop'),
            'marker_icon_url'   => DEXPRESS_PLUGIN_URL . 'assets/images/pin-1-svgrepo-com.svg',
            'loading_logo_url'  => $this->resolveLoadingLogoUrl(),
            'i18n'              => [
                'loading'          => __('Učitavanje...', 'dexpress-woocommerce'),
                'search_placeholder' => __('Pretražite po gradu ili opštini', 'dexpress-woocommerce'),
                'search_caption'   => __('Pretraga paketomata', 'dexpress-woocommerce'),
                'empty'            => __('Nema rezultata za zadatu pretragu.', 'dexpress-woocommerce'),
                'map_init_failed'  => __('Mapa trenutno nije dostupna.', 'dexpress-woocommerce'),
                'select_required'  => __('Molimo vas odaberite paketomat pre naručivanja.', 'dexpress-woocommerce'),
            ],
        ]);
    }

    public function renderModalTemplate(): void
    {
        if (!is_checkout()) {
            return;
        }

        wc_get_template(
            'checkout/package-shop-modal.php',
            [
                'modal_title' => apply_filters(
                    'dexpress/package_shop_onboarding_modal_title',
                    __('Odaberite paketomat', 'dexpress-woocommerce'),
                ),
            ],
            '',
            trailingslashit(DEXPRESS_PLUGIN_DIR) . 'templates/',
        );

        wc_get_template(
            'checkout/package-shop-browser-modal.php',
            [
                'modal_title' => apply_filters(
                    'dexpress/package_shop_browser_modal_title',
                    __('ODABERITE PAKETOMAT', 'dexpress-woocommerce'),
                ),
            ],
            '',
            trailingslashit(DEXPRESS_PLUGIN_DIR) . 'templates/',
        );
    }

    private function renderTemplate(
        string $rateId,
        string $introText,
        string $deliveryPriceText,
        string $deliveryTimeText,
        string $stepsText,
        string $modalInfoText,
        string $googleMapsApiKey,
    ): void {
        if ($introText === '' && $deliveryPriceText === '' && $deliveryTimeText === '' && $stepsText === '' && $modalInfoText === '' && $googleMapsApiKey === '') {
            return;
        }

        wc_get_template(
            'checkout/package-shop-info-panel.php',
            [
                'rate_id'         => $rateId,
                'intro_text'      => $introText,
                'delivery_price_text' => $deliveryPriceText,
                'delivery_time_text'  => $deliveryTimeText,
                'steps_text'      => $stepsText,
                'modal_info_text' => $modalInfoText,
                'google_maps_api_key' => $googleMapsApiKey,
            ],
            '',
            trailingslashit(DEXPRESS_PLUGIN_DIR) . 'templates/',
        );
    }

    /**
     * @return list<array{rate_id: string, intro_text: string, delivery_price_text: string, delivery_time_text: string, steps_text: string, modal_info_text: string, google_maps_api_key: string}>
     */
    private function buildPanelsForAvailableRates(): array
    {
        $cart = WC()->cart;
        if (!$cart instanceof \WC_Cart) {
            return [];
        }

        $packages = WC()->shipping()->get_packages();
        if (!is_array($packages) || $packages === []) {
            return [];
        }

        /** @var array<string, array{rate_id: string, intro_text: string, delivery_price_text: string, delivery_time_text: string, steps_text: string, modal_info_text: string, google_maps_api_key: string}> $panels */
        $panels = [];

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $rates = $package['rates'] ?? [];
            if (!is_array($rates)) {
                continue;
            }

            foreach ($rates as $rate) {
                if (!$rate instanceof \WC_Shipping_Rate) {
                    continue;
                }

                if ($rate->get_method_id() !== DexpressPackageShopShippingMethod::METHOD_ID) {
                    continue;
                }

                $rateId = $rate->get_id();
                if ($rateId === '' || isset($panels[$rateId])) {
                    continue;
                }

                $method = new DexpressPackageShopShippingMethod($rate->get_instance_id());
                $panels[$rateId] = [
                    'rate_id'    => $rateId,
                    'intro_text' => $method->getIntroText(),
                    'delivery_price_text' => $this->resolveDeliveryPriceText((float) $rate->get_cost(), $method),
                    'delivery_time_text' => $method->getDeliveryTimeText(),
                    'steps_text' => $method->getStepsText(),
                    'modal_info_text' => $method->getModalInfoText(),
                    'google_maps_api_key' => $method->getGoogleMapsApiKey(),
                ];
            }
        }

        return array_values($panels);
    }

    private function isBlockCheckoutPage(): bool
    {
        $pageId = wc_get_page_id('checkout');

        return $pageId > 0 && has_block('woocommerce/checkout', $pageId);
    }

    private function resolveDeliveryPriceText(float $rateCost, DexpressPackageShopShippingMethod $method): string
    {
        $defaultFormat = __('Cena isporuke je %s RSD.', 'dexpress-woocommerce');
        $format = $method->getDeliveryPriceFormat();
        if ($format === '') {
            $format = $defaultFormat;
        }

        $decimals = abs($rateCost - round($rateCost)) < 0.00001 ? 0 : 2;
        $price = number_format_i18n($rateCost, $decimals);

        if (str_contains($format, '%s')) {
            return sprintf($format, $price);
        }

        return sprintf($defaultFormat, $price);
    }

    private function resolveLoadingLogoUrl(): string
    {
        $customLogoId = (int) get_theme_mod('custom_logo');
        if ($customLogoId > 0) {
            $url = wp_get_attachment_image_url($customLogoId, 'full');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function resolveGoogleMapsScriptUrl(): string
    {
        $params = [
            'v' => 'weekly',
            'libraries' => 'geometry',
        ];

        $apiKey = $this->resolveCheckoutGoogleMapsApiKey();
        if ($apiKey !== '') {
            $params['key'] = $apiKey;
        }

        return 'https://maps.googleapis.com/maps/api/js?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function resolveCheckoutGoogleMapsApiKey(): string
    {
        $filteredKey = trim((string) apply_filters('dexpress/package_shop_google_maps_api_key', ''));
        if ($filteredKey !== '') {
            return $filteredKey;
        }

        foreach ($this->buildPanelsForAvailableRates() as $panel) {
            $apiKey = trim((string) ($panel['google_maps_api_key'] ?? ''));
            if ($apiKey !== '') {
                return $apiKey;
            }
        }

        return '';
    }
}
