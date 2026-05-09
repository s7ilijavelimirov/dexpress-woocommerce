<?php
/**
 * @var string $rate_id
 * @var string $intro_text
 * @var string $delivery_price_text
 * @var string $delivery_time_text
 * @var string $steps_text
 * @var string $modal_info_text
 * @var string $google_maps_api_key
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div
    class="dexpress-package-shop-panel"
    data-dexpress-package-shop-panel="1"
    data-rate-id="<?php echo esc_attr($rate_id); ?>"
    hidden
>
    <div class="dexpress-package-shop-panel__intro"><?php echo wp_kses_post(wpautop($intro_text)); ?></div>
    <div class="dexpress-package-shop-panel__meta-row">
        <div class="dexpress-package-shop-panel__delivery-price"><?php echo wp_kses_post($delivery_price_text); ?></div>
        <div class="dexpress-package-shop-panel__delivery-time"><?php echo wp_kses_post($delivery_time_text); ?></div>
    </div>
    <div
        class="dexpress-ps-selected-panel"
        data-dexpress-ps-selected-panel="1"
        hidden
    >
        <div class="dexpress-ps-selected-panel__inner">
            <span class="dexpress-ps-selected-panel__label"><?php esc_html_e('Odabran paketomat:', 'dexpress-woocommerce'); ?></span>
            <span class="dexpress-ps-selected-panel__name" data-dexpress-ps-selected-name="1"></span>
            <span class="dexpress-ps-selected-panel__city" data-dexpress-ps-selected-city="1"></span>
            <span class="dexpress-ps-selected-panel__address" data-dexpress-ps-selected-address="1"></span>
            <button
                type="button"
                class="dexpress-ps-selected-panel__reset"
                data-dexpress-ps-reset="1"
            ><?php esc_html_e('✕ Obriši izbor', 'dexpress-woocommerce'); ?></button>
        </div>
    </div>
    <div class="dexpress-package-shop-panel__actions">
        <button
            type="button"
            class="dexpress-package-shop-panel__locker-cta"
            data-dexpress-package-shop-locker-cta="1"
        >
            <?php esc_html_e('ODABERI PAKETOMAT', 'dexpress-woocommerce'); ?>
        </button>
    </div>
    <div class="dexpress-package-shop-panel__steps"><?php echo wp_kses_post(wpautop($steps_text)); ?></div>
    <div class="dexpress-package-shop-panel__modal-content-source" data-dexpress-package-shop-modal-content-source="1" hidden>
        <?php echo wp_kses_post(wpautop($modal_info_text)); ?>
    </div>
    <div class="dexpress-package-shop-panel__map-key-source" data-dexpress-package-shop-map-key-source="1" hidden>
        <?php echo esc_html($google_maps_api_key); ?>
    </div>
    <div class="dexpress-package-shop-panel__future-slot" data-dexpress-package-shop-future-slot="1"></div>
</div>
