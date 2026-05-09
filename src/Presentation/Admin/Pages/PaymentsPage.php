<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Application\Sync\SyncPaymentsService;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPaymentRepository;

final class PaymentsPage
{
    private const PAGE_SLUG = 'dexpress-payments';

    public function __construct(
        private readonly WpdbPaymentRepository $payments,
        private readonly SyncPaymentsService $syncPayments,
        private readonly OptionsRepository $options,
    ) {}

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nemate dozvolu.', 'dexpress-woocommerce'));
        }

        $this->maybeHandlePostActions();
        $this->maybeExportCsv();

        $search = isset($_GET['s']) ? sanitize_text_field((string) $_GET['s']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $dateFrom = isset($_GET['from']) ? sanitize_text_field((string) $_GET['from']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $dateTo = isset($_GET['to']) ? sanitize_text_field((string) $_GET['to']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $syncNotice = isset($_GET['dexpress_sync_msg']) ? sanitize_text_field((string) $_GET['dexpress_sync_msg']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $syncType = isset($_GET['dexpress_sync_type']) ? sanitize_key((string) $_GET['dexpress_sync_type']) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        $summary = $this->payments->summary($search, $dateFrom, $dateTo);
        $lastSync = $this->options->getString('sync.last_payments', '');
        $knownRefs = $this->syncPayments->knownReferences();

        $table = new PaymentListTable($this->payments);
        $table->setFilters($search, $dateFrom, $dateTo);
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('D Express — otkupnine', 'dexpress-woocommerce') . '</h1>';
        echo '<hr class="wp-header-end" />';

        if ($syncNotice !== '') {
            $class = $syncType === 'success' ? 'notice-success' : 'notice-error';
            printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($syncNotice));
        }

        $this->renderSyncBox($lastSync, $knownRefs);
        $this->renderSummary($summary, $dateFrom, $dateTo);
        $this->renderFilters($search, $dateFrom, $dateTo);
        $table->display();
        echo '</div>';
    }

    private function maybeHandlePostActions(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        if (!isset($_POST['dexpress_payments_action'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        check_admin_referer('dexpress_payments_action', 'dexpress_payments_nonce');
        $action = sanitize_key((string) $_POST['dexpress_payments_action']); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ($action === 'sync_reference') {
            $reference = trim(sanitize_text_field((string) ($_POST['payment_reference'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $result = $this->syncPayments->syncByReference($reference);
            $message = $result->success
                ? sprintf(
                    /* translators: 1: inserted rows 2: updated rows 3: unchanged rows */
                    __('Sinhronizacija završena. Novo: %1$d · Ažurirano: %2$d · Bez promene: %3$d.', 'dexpress-woocommerce'),
                    $result->changes->inserted,
                    $result->changes->updated,
                    $result->changes->unchanged,
                )
                : $result->errorMessage;
            $this->redirectWithSyncNotice($result->success, $message);
        }

        if ($action === 'sync_known') {
            $result = $this->syncPayments->syncKnownReferences();
            $message = $result->success
                ? sprintf(
                    /* translators: 1: inserted rows 2: updated rows 3: unchanged rows */
                    __('Sinhronizacija poznatih referenci završena. Novo: %1$d · Ažurirano: %2$d · Bez promene: %3$d.', 'dexpress-woocommerce'),
                    $result->changes->inserted,
                    $result->changes->updated,
                    $result->changes->unchanged,
                )
                : $result->errorMessage;
            $this->redirectWithSyncNotice($result->success, $message);
        }
    }

    private function maybeExportCsv(): void
    {
        $export = isset($_GET['export']) ? sanitize_key((string) $_GET['export']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ($export !== 'csv') {
            return;
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field((string) $_GET['_wpnonce']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if (!wp_verify_nonce($nonce, 'dexpress_export_payments_csv')) {
            wp_die(esc_html__('Nevažeći zahtev za CSV izvoz.', 'dexpress-woocommerce'));
        }

        $search = isset($_GET['s']) ? sanitize_text_field((string) $_GET['s']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $dateFrom = isset($_GET['from']) ? sanitize_text_field((string) $_GET['from']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $dateTo = isset($_GET['to']) ? sanitize_text_field((string) $_GET['to']) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        $rows = $this->payments->findForExport($search, $dateFrom, $dateTo);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="dexpress-otkupnine-' . gmdate('Ymd-His') . '.csv"');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            exit;
        }

        fputcsv($out, [
            'PaymentReference',
            'ShCode',
            'ReferenceID',
            'BuyoutPara',
            'BuyoutRSD',
            'RName',
            'RAddress',
            'RTown',
            'PaymentDate',
        ]);

        foreach ($rows as $row) {
            $buyout = (int) ($row['buyout_para'] ?? 0);
            fputcsv($out, [
                (string) ($row['payment_reference'] ?? ''),
                (string) ($row['shipment_code'] ?? ''),
                (string) ($row['order_reference_id'] ?? ''),
                $buyout,
                number_format($buyout / 100, 2, '.', ''),
                (string) ($row['recipient_name'] ?? ''),
                (string) ($row['recipient_address'] ?? ''),
                (string) ($row['recipient_town'] ?? ''),
                (string) ($row['payment_date'] ?? ''),
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * @param array{records:int,total_para:int,min_date:string,max_date:string} $summary
     */
    private function renderSummary(array $summary, string $dateFrom, string $dateTo): void
    {
        $from = $dateFrom !== '' ? $dateFrom : ($summary['min_date'] !== '' ? $summary['min_date'] : '—');
        $to = $dateTo !== '' ? $dateTo : ($summary['max_date'] !== '' ? $summary['max_date'] : '—');

        echo '<div class="notice notice-info" style="margin:16px 0; padding:12px 16px;">';
        echo '<p style="margin:0 0 6px 0;"><strong>' . esc_html__('Sažetak prikaza', 'dexpress-woocommerce') . '</strong></p>';
        echo '<p style="margin:0;">';
        printf(
            /* translators: 1: total amount in RSD 2: number of records 3: from date 4: to date */
            esc_html__('Ukupno: %1$s · Broj zapisa: %2$d · Period: %3$s — %4$s', 'dexpress-woocommerce'),
            esc_html(number_format_i18n($summary['total_para'] / 100, 2) . ' RSD'),
            (int) $summary['records'],
            esc_html($from),
            esc_html($to),
        );
        echo '</p>';
        echo '</div>';
    }

    /**
     * @param list<string> $knownRefs
     */
    private function renderSyncBox(string $lastSync, array $knownRefs): void
    {
        echo '<div class="postbox" style="margin-top:12px;max-width:1100px;"><div class="inside">';
        echo '<h2 style="margin-top:0;">' . esc_html__('Sinhronizacija otkupnina', 'dexpress-woocommerce') . '</h2>';
        echo '<p class="description" style="margin-bottom:12px;">'
            . esc_html__('API endpoint zahteva PaymentReference. Unesite referencu iz bankovnog izvoda (npr. broj naloga/isplate), pa pokrenite sinhronizaciju ili osvežite već poznate reference.', 'dexpress-woocommerce')
            . '</p>';

        echo '<form method="post" style="display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;margin-bottom:10px;">';
        wp_nonce_field('dexpress_payments_action', 'dexpress_payments_nonce');
        echo '<input type="hidden" name="dexpress_payments_action" value="sync_reference" />';
        echo '<div>';
        echo '<label for="payment_reference"><strong>' . esc_html__('Referenca uplate', 'dexpress-woocommerce') . '</strong></label><br />';
        echo '<input type="text" id="payment_reference" name="payment_reference" class="regular-text code" placeholder="' . esc_attr__('Unesite referencu iz bankovnog izvoda', 'dexpress-woocommerce') . '" required />';
        echo '</div>';
        submit_button(__('Sinhronizuj sada', 'dexpress-woocommerce'), 'primary', '', false);
        echo '</form>';

        echo '<form method="post" style="margin:0 0 8px 0;">';
        wp_nonce_field('dexpress_payments_action', 'dexpress_payments_nonce');
        echo '<input type="hidden" name="dexpress_payments_action" value="sync_known" />';
        submit_button(__('Osveži poznate reference', 'dexpress-woocommerce'), 'secondary', '', false);
        echo '</form>';

        echo '<p class="description" style="margin:6px 0;">';
        echo esc_html__('Poznate reference:', 'dexpress-woocommerce') . ' ';
        echo $knownRefs === [] ? '<em>' . esc_html__('nema', 'dexpress-woocommerce') . '</em>' : esc_html(implode(', ', $knownRefs));
        echo '</p>';

        echo '<p class="description" style="margin:6px 0 0 0;">';
        echo esc_html__('Poslednja sinhronizacija:', 'dexpress-woocommerce') . ' ';
        if ($lastSync === '') {
            echo '<em>' . esc_html__('nikad', 'dexpress-woocommerce') . '</em>';
        } else {
            $dt = \DateTime::createFromFormat('YmdHis', $lastSync);
            echo esc_html($dt instanceof \DateTime ? $dt->format('d.m.Y H:i:s') : $lastSync);
        }
        echo '</p>';
        echo '</div></div>';
    }

    private function renderFilters(string $search, string $dateFrom, string $dateTo): void
    {
        $csvUrl = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                's' => $search,
                'from' => $dateFrom,
                'to' => $dateTo,
                'export' => 'csv',
                '_wpnonce' => wp_create_nonce('dexpress_export_payments_csv'),
            ],
            admin_url('admin.php'),
        );

        echo '<form method="get" style="margin:14px 0 10px 0;display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '" />';
        echo '<div><label for="payment-search"><strong>' . esc_html__('Pretraga', 'dexpress-woocommerce') . '</strong></label><br />';
        echo '<input type="search" id="payment-search" name="s" class="regular-text" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Referenca, kod pošiljke, primalac...', 'dexpress-woocommerce') . '" /></div>';
        echo '<div><label for="payment-from"><strong>' . esc_html__('Od datuma', 'dexpress-woocommerce') . '</strong></label><br />';
        echo '<input type="date" id="payment-from" name="from" value="' . esc_attr($dateFrom) . '" /></div>';
        echo '<div><label for="payment-to"><strong>' . esc_html__('Do datuma', 'dexpress-woocommerce') . '</strong></label><br />';
        echo '<input type="date" id="payment-to" name="to" value="' . esc_attr($dateTo) . '" /></div>';
        submit_button(__('Filtriraj', 'dexpress-woocommerce'), 'secondary', '', false);
        echo '<a href="' . esc_url($csvUrl) . '" class="button">' . esc_html__('Izvezi CSV', 'dexpress-woocommerce') . '</a>';
        echo '</form>';
    }

    private function redirectWithSyncNotice(bool $success, string $message): void
    {
        $url = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'dexpress_sync_type' => $success ? 'success' : 'error',
                'dexpress_sync_msg' => $message,
            ],
            admin_url('admin.php'),
        );
        wp_safe_redirect($url);
        exit;
    }
}
