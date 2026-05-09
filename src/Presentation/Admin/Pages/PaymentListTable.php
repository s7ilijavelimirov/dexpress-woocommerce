<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPaymentRepository;

if (!class_exists(\WP_List_Table::class, false)) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class PaymentListTable extends \WP_List_Table
{
    private string $search = '';
    private string $dateFrom = '';
    private string $dateTo = '';

    public function __construct(
        private readonly WpdbPaymentRepository $payments,
    ) {
        parent::__construct([
            'singular' => 'payment',
            'plural' => 'payments',
            'ajax' => false,
        ]);
    }

    public function setFilters(string $search, string $dateFrom, string $dateTo): void
    {
        $this->search = $search;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
    }

    public function no_items(): void
    {
        esc_html_e('Nema podataka za prikazane filtere.', 'dexpress-woocommerce');
    }

    public function get_columns(): array
    {
        return [
            'payment_reference' => __('Referenca uplate', 'dexpress-woocommerce'),
            'payment_date' => __('Datum uplate', 'dexpress-woocommerce'),
            'shipment_code' => __('Kod pošiljke', 'dexpress-woocommerce'),
            'order_reference_id' => __('Referentni broj', 'dexpress-woocommerce'),
            'buyout_para' => __('Otkupnina', 'dexpress-woocommerce'),
            'recipient' => __('Primalac', 'dexpress-woocommerce'),
        ];
    }

    public function prepare_items(): void
    {
        $perPage = 25;
        $paged = max(1, (int) ($_GET['paged'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification
        $offset = ($paged - 1) * $perPage;

        $total = $this->payments->count($this->search, $this->dateFrom, $this->dateTo);
        $this->items = $this->payments->find($this->search, $this->dateFrom, $this->dateTo, $offset, $perPage);
        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => $perPage,
        ]);
    }

    public function column_default($item, $column_name): string
    {
        /** @var array<string, mixed> $item */
        return match ($column_name) {
            'payment_reference' => esc_html((string) ($item['payment_reference'] ?? '')),
            'payment_date' => esc_html($this->formatDate((string) ($item['payment_date'] ?? ''))),
            'shipment_code' => esc_html((string) ($item['shipment_code'] ?? '')),
            'order_reference_id' => esc_html((string) ($item['order_reference_id'] ?? '')),
            'buyout_para' => esc_html($this->formatParaToRsd((int) ($item['buyout_para'] ?? 0))),
            'recipient' => esc_html($this->formatRecipient($item)),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatRecipient(array $item): string
    {
        $name = trim((string) ($item['recipient_name'] ?? ''));
        $address = trim((string) ($item['recipient_address'] ?? ''));
        $town = trim((string) ($item['recipient_town'] ?? ''));

        $parts = array_values(array_filter([$name, $address, $town], static fn (string $value): bool => $value !== ''));
        if ($parts === []) {
            return '—';
        }

        return implode(', ', $parts);
    }

    private function formatDate(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return '—';
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $raw);

        return $dt instanceof \DateTime ? $dt->format('d.m.Y') : $raw;
    }

    private function formatParaToRsd(int $para): string
    {
        $rsd = $para / 100;

        return number_format_i18n($rsd, 2) . ' RSD';
    }
}
