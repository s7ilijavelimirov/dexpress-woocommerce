<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;

final class DashboardPage
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
    ) {}

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nemate dozvolu.', 'dexpress-woocommerce'));
        }

        $total = $this->shipments->countAllNotDeleted();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('D Express — pregled', 'dexpress-woocommerce') . '</h1>';
        echo '<p>' . sprintf(
            /* translators: %d: shipment count */
            esc_html__('Ukupno aktivnih pošiljaka u bazi: %d', 'dexpress-woocommerce'),
            $total,
        ) . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=dexpress-shipments')) . '">'
            . esc_html__('Lista pošiljaka', 'dexpress-woocommerce') . '</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=dexpress-settings')) . '">'
            . esc_html__('Podešavanja', 'dexpress-woocommerce') . '</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=dexpress-diagnostics')) . '">'
            . esc_html__('Dijagnostika', 'dexpress-woocommerce') . '</a></p>';
        echo '</div>';
    }
}
