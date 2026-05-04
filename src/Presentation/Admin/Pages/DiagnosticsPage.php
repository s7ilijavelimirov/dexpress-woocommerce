<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;

final class DiagnosticsPage
{
    /** @var array<string, string> */
    private const SYNC_LABELS = [
        'sync.last_towns'          => 'Gradovi',
        'sync.last_streets'        => 'Ulice',
        'sync.last_municipalities' => 'Opštine',
        'sync.last_status_codes'   => 'Status kodovi',
        'sync.last_dispensers'     => 'Paketomati',
        'sync.last_locations'      => 'Lokacije',
        'sync.last_centres'        => 'Centri',
        'sync.last_shops'          => 'Prodavnice',
    ];

    public function __construct(
        private readonly OptionsRepository $options,
        private readonly Logger $logger,
    ) {}

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nemate dozvolu.', 'dexpress-woocommerce'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('D Express — dijagnostika', 'dexpress-woocommerce') . '</h1>';

        echo '<h2>' . esc_html__('Poslednja sinhronizacija šifarnika', 'dexpress-woocommerce') . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Šifarnik', 'dexpress-woocommerce') . '</th><th>'
            . esc_html__('Vreme (opcija)', 'dexpress-woocommerce') . '</th></tr></thead><tbody>';
        foreach (self::SYNC_LABELS as $key => $label) {
            $v = $this->options->getString($key, '');
            printf(
                '<tr><td>%s</td><td><code>%s</code></td></tr>',
                esc_html($label),
                esc_html($v !== '' ? $v : '—'),
            );
        }
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Test API konekcije', 'dexpress-woocommerce') . '</h2>';
        echo '<p>' . esc_html__('Koristite dugme na tabu API u podešavanjima.', 'dexpress-woocommerce')
            . ' <a class="button" href="' . esc_url(admin_url('admin.php?page=dexpress-settings&tab=api')) . '">'
            . esc_html__('Otvori API tab', 'dexpress-woocommerce') . '</a></p>';

        echo '<h2>' . esc_html__('Log (danas)', 'dexpress-woocommerce') . '</h2>';
        $path = $this->logger->getLogDirectory() . 'dexpress-' . gmdate('Y-m-d') . '.log';
        if (!is_readable($path)) {
            echo '<p>' . esc_html__('Nema log fajla za današnji dan.', 'dexpress-woocommerce') . '</p>';
        } else {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines)) {
                echo '<p>' . esc_html__('Log se ne može pročitati.', 'dexpress-woocommerce') . '</p>';
            } else {
                $tail = array_slice($lines, -200);
                echo '<textarea readonly rows="18" class="large-text code" style="width:100%;font-size:11px;">';
                echo esc_textarea(implode("\n", $tail));
                echo '</textarea>';
            }
        }

        echo '<p class="description">' . esc_html__('Direktorijum logova:', 'dexpress-woocommerce') . ' <code>'
            . esc_html($this->logger->getLogDirectory()) . '</code></p>';

        echo '</div>';
    }
}
