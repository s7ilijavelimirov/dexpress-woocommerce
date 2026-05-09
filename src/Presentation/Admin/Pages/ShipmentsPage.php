<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPackageProfileRepository;

final class ShipmentsPage
{
    public function __construct(
        private readonly ShipmentRepository           $shipments,
        private readonly StatusCodeRepository         $statusCodes,
        private readonly WpdbPackageProfileRepository $profiles,
        private readonly \wpdb                        $wpdb,
    ) {}

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nemate dozvolu.', 'dexpress-woocommerce'));
        }

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('D Express pošiljke', 'dexpress-woocommerce') . '</h1>';
        echo '<hr class="wp-header-end" />';

        $this->renderPendingOrders();
        $this->renderTopBar();

        $table = new ShipmentListTable($this->shipments, $this->statusCodes);
        $table->prepare_items();
        $table->display();
        echo '</div>';
    }

    /**
     * Vraća listu narudžbina u obradi sa D-Express metodom dostave koje nemaju pošiljku.
     *
     * @return list<array<string, mixed>>
     */
    private function getPendingOrders(): array
    {
        $orderItemsTable    = $this->wpdb->prefix . 'woocommerce_order_items';
        $orderItemMetaTable = $this->wpdb->prefix . 'woocommerce_order_itemmeta';
        $shipmentsTable     = $this->wpdb->prefix . 'dexpress_shipments';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $orderIds = $this->wpdb->get_col(
            "SELECT DISTINCT oi.order_id
             FROM `{$orderItemsTable}` oi
             INNER JOIN `{$orderItemMetaTable}` oim ON oim.order_item_id = oi.order_item_id
             WHERE oi.order_item_type = 'shipping'
               AND oim.meta_key = 'method_id'
               AND oim.meta_value IN ('dexpress', 'dexpress_package_shop')
               AND oi.order_id NOT IN (
                 SELECT DISTINCT order_id FROM `{$shipmentsTable}` WHERE deleted_at IS NULL
               )
             ORDER BY oi.order_id DESC
             LIMIT 100",
        );

        if (empty($orderIds)) {
            return [];
        }

        $wcOrders = wc_get_orders([
            'include' => array_map('intval', $orderIds),
            'status'  => ['processing'],
            'limit'   => -1,
            'orderby' => 'date',
            'order'   => 'DESC',
        ]);

        $result = [];
        foreach ($wcOrders as $order) {
            $isShop = false;
            foreach ($order->get_shipping_methods() as $m) {
                if ($m->get_method_id() === 'dexpress_package_shop') {
                    $isShop = true;
                    break;
                }
            }

            $firstName = (string) $order->get_billing_first_name();
            $lastName  = (string) $order->get_billing_last_name();
            $customer  = trim("{$firstName} {$lastName}");
            if ($customer === '') {
                $customer = (string) $order->get_billing_company();
            }

            $result[] = [
                'id'       => $order->get_id(),
                'number'   => $order->get_order_number(),
                'customer' => $customer,
                'total'    => strip_tags($order->get_formatted_order_total() ?? ''),
                'date'     => $order->get_date_created()?->date_i18n(get_option('date_format')) ?? '',
                'edit_url' => get_edit_post_link($order->get_id()) ?? admin_url('post.php?post=' . $order->get_id() . '&action=edit'),
                'is_shop'  => $isShop,
            ];
        }

        return $result;
    }

    /**
     * Prikazuje sekciju "Narudžbine na čekanju" sa tabelom i dugmetom za grupno kreiranje.
     */
    private function renderPendingOrders(): void
    {
        $orders = $this->getPendingOrders();

        if (empty($orders)) {
            return;
        }

        $bulkNonce   = wp_create_nonce('dexpress_bulk_init');
        $bulkPageUrl = admin_url('admin.php');
        $count       = count($orders);
        ?>
<div class="dex-pending-orders-wrap">
    <div class="dex-pending-orders-header">
        <h2>
            <?= esc_html__('Narudžbine na čekanju', 'dexpress-woocommerce') ?>
            <span class="dex-pending-badge"><?= (int) $count ?></span>
        </h2>
        <button type="button" class="button button-primary" id="dex-pending-bulk-btn" disabled>
            <?= esc_html__('Kreiraj D-Express pošiljke', 'dexpress-woocommerce') ?>
        </button>
    </div>
    <p class="description" style="margin-top:4px;">
        <?= esc_html__('Narudžbine u statusu "U obradi" sa D-Express metodom dostave za koje još nije kreirana pošiljka.', 'dexpress-woocommerce') ?>
    </p>
    <table class="wp-list-table widefat fixed striped dex-pending-table">
        <thead>
            <tr>
                <td class="check-column">
                    <input type="checkbox" id="dex-pending-check-all"
                           title="<?= esc_attr__('Označi sve', 'dexpress-woocommerce') ?>" />
                </td>
                <th><?= esc_html__('Narudžbina', 'dexpress-woocommerce') ?></th>
                <th><?= esc_html__('Kupac', 'dexpress-woocommerce') ?></th>
                <th><?= esc_html__('Datum', 'dexpress-woocommerce') ?></th>
                <th><?= esc_html__('Iznos', 'dexpress-woocommerce') ?></th>
                <th><?= esc_html__('Metoda', 'dexpress-woocommerce') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $o): ?>
            <tr>
                <th class="check-column">
                    <input type="checkbox" class="dex-pending-order-cb"
                           value="<?= (int) $o['id'] ?>"
                           <?php if ($o['is_shop']): ?>
                           title="<?= esc_attr__('Package Shop — kreirati pojedinačno', 'dexpress-woocommerce') ?>"
                           <?php endif; ?>
                    />
                </th>
                <td>
                    <a href="<?= esc_url((string) $o['edit_url']) ?>" target="_blank">
                        #<?= esc_html((string) $o['number']) ?>
                    </a>
                </td>
                <td><?= esc_html((string) $o['customer']) ?></td>
                <td><?= esc_html((string) $o['date']) ?></td>
                <td><?= esc_html((string) $o['total']) ?></td>
                <td>
                    <?php if ($o['is_shop']): ?>
                    <span class="dex-pending-method dex-pending-method--shop">
                        <?= esc_html__('Paketomat', 'dexpress-woocommerce') ?>
                    </span>
                    <?php else: ?>
                    <span class="dex-pending-method">
                        <?= esc_html__('Kućna dostava', 'dexpress-woocommerce') ?>
                    </span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<input type="hidden" id="dex-bulk-init-nonce" value="<?= esc_attr($bulkNonce) ?>">
<input type="hidden" id="dex-bulk-page-url"   value="<?= esc_attr($bulkPageUrl) ?>">
<style>
.dex-pending-orders-wrap{margin-bottom:20px;padding:14px 16px;background:#fff;border:1px solid var(--dex-gray-200,#e2e8f0);border-radius:6px;}
.dex-pending-orders-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;}
.dex-pending-orders-header h2{margin:0;font-size:14px;font-weight:600;}
.dex-pending-badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 5px;background:var(--dex-primary,#e31e29);color:#fff;border-radius:99px;font-size:11px;font-weight:700;margin-left:6px;vertical-align:middle;}
.dex-pending-table{margin-top:10px;}
.dex-pending-method{display:inline-block;padding:1px 7px;border-radius:99px;font-size:11px;font-weight:600;background:var(--dex-gray-100,#f1f5f9);color:var(--dex-gray-700,#334155);}
.dex-pending-method--shop{background:#fef3c7;color:#92400e;}
</style>
<script>
(function($){
    var $btn = $('#dex-pending-bulk-btn');
    var $all = $('#dex-pending-check-all');

    function updateBtn(){
        var selected = $('.dex-pending-order-cb:checked').length;
        $btn.prop('disabled', selected === 0);
        $btn.text(selected > 0
            ? '<?= esc_js(__('Kreiraj D-Express pošiljke', 'dexpress-woocommerce')) ?> (' + selected + ')'
            : '<?= esc_js(__('Kreiraj D-Express pošiljke', 'dexpress-woocommerce')) ?>');
    }

    $all.on('change', function(){
        $('.dex-pending-order-cb').prop('checked', this.checked);
        updateBtn();
    });

    $(document).on('change', '.dex-pending-order-cb', function(){
        var total   = $('.dex-pending-order-cb').length;
        var checked = $('.dex-pending-order-cb:checked').length;
        $all.prop('indeterminate', checked > 0 && checked < total);
        $all.prop('checked', checked === total);
        updateBtn();
    });

    $btn.on('click', function(){
        var ids = [];
        $('.dex-pending-order-cb:checked').each(function(){ ids.push($(this).val()); });
        if(!ids.length){ return; }
        var nonce = $('#dex-bulk-init-nonce').val();
        var base  = $('#dex-bulk-page-url').val();
        window.location.href = base + '?page=dexpress-bulk-shipment&order_ids='
            + ids.join(',') + '&_wpnonce=' + encodeURIComponent(nonce);
    });
})(jQuery);
</script>
        <?php
    }

    /**
     * Prikazuje bar sa profilima paketa i dugmetom za bulk kreiranje.
     */
    private function renderTopBar(): void
    {
        $profiles  = $this->profiles->findAll();
        $bulkUrl   = admin_url('edit.php?post_type=shop_order');
        $ordersUrl = $bulkUrl;

        // Objašnjenje za korisnika.
        echo '<div class="dex-shipments-topbar">';

        if (!empty($profiles)) {
            echo '<div class="dex-shipments-profiles">';
            echo '<span class="dex-shipments-profiles__label">' . esc_html__('Profili paketa:', 'dexpress-woocommerce') . '</span>';
            foreach ($profiles as $profile) {
                $wG   = (int) $profile['weight_grams'];
                $dims = [];
                foreach (['dim_x', 'dim_y', 'dim_z'] as $k) {
                    $v = $profile[$k] !== null ? number_format((int) $profile[$k] / 10, 1, ',', '') : null;
                    if ($v !== null) {
                        $dims[] = $v;
                    }
                }
                $detail = [];
                if ($wG > 0) {
                    $detail[] = number_format($wG / 1000, 3, ',', '') . ' kg';
                }
                if (count($dims) === 3) {
                    $detail[] = implode('×', $dims) . ' cm';
                }

                $tipText = esc_attr(implode(' · ', $detail));
                echo '<span class="dex-mini-profile-chip"'
                    . ($tipText !== '' ? ' title="' . $tipText . '"' : '')
                    . '>';
                echo esc_html((string) $profile['name']);
                if (!empty($profile['is_default'])) {
                    echo ' <span class="dex-pp-badge dex-pp-badge--default">'
                        . esc_html__('✓', 'dexpress-woocommerce')
                        . '</span>';
                }
                echo '</span> ';
            }
            echo '<a href="' . esc_url(admin_url('admin.php?page=' . PackageProfilesPage::PAGE_SLUG)) . '" class="dex-pp-manage-link">'
                . esc_html__('Upravljaj profilima', 'dexpress-woocommerce')
                . '</a>';
            echo '</div>';
        } else {
            echo '<p class="description" style="display:inline-block;">'
                . sprintf(
                    /* translators: %s: link */
                    esc_html__('Nema profila paketa. %s', 'dexpress-woocommerce'),
                    '<a href="' . esc_url(admin_url('admin.php?page=' . PackageProfilesPage::PAGE_SLUG)) . '">'
                        . esc_html__('Dodajte profil', 'dexpress-woocommerce')
                    . '</a>',
                )
                . '</p>';
        }

        echo '<a href="' . esc_url($ordersUrl) . '" class="button button-primary dex-shipments-bulk-btn">'
            . esc_html__('Grupno kreiranje pošiljaka →', 'dexpress-woocommerce')
            . '</a>';

        echo '</div><!-- .dex-shipments-topbar -->';
        echo '<style>
.dex-shipments-topbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;padding:12px 14px;background:#fff;border:1px solid var(--dex-gray-200);border-radius:var(--dex-radius-lg);}
.dex-shipments-profiles{display:flex;align-items:center;flex-wrap:wrap;gap:6px;flex:1;}
.dex-shipments-profiles__label{font-size:12px;color:var(--dex-gray-500);font-weight:600;white-space:nowrap;}
.dex-mini-profile-chip{display:inline-block;padding:2px 8px;background:var(--dex-gray-100);border-radius:99px;font-size:12px;cursor:default;}
.dex-pp-manage-link{font-size:12px;margin-left:4px;}
.dex-shipments-bulk-btn{white-space:nowrap;}
</style>';
    }
}
