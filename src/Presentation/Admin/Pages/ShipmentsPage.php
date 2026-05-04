<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;

final class ShipmentsPage
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly StatusCodeRepository $statusCodes,
    ) {}

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nemate dozvolu.', 'dexpress-woocommerce'));
        }

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('D Express pošiljke', 'dexpress-woocommerce') . '</h1>';
        echo '<hr class="wp-header-end" />';

        $table = new ShipmentListTable($this->shipments, $this->statusCodes);
        $table->prepare_items();
        $table->display();
        echo '</div>';
    }
}
