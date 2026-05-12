<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPackageProfileRepository;

final class ShipmentsPage
{
    public function __construct(
        private readonly \wpdb $wpdb,
        private readonly WpdbPackageProfileRepository $profiles,
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

        if (empty($wcOrders)) {
            return [];
        }

        // Batch-fetch town names — collect unique town IDs from order meta.
        $townIds = [];
        foreach ($wcOrders as $order) {
            $townId = (string) ($order->get_meta('_shipping_town_id') ?? '');
            if ($townId !== '') {
                $townIds[] = (int) $townId;
            }
        }
        $townMap = [];
        if (!empty($townIds)) {
            $uniqueTownIds = array_unique($townIds);
            $townsTable    = $this->wpdb->prefix . 'dexpress_towns';
            $placeholders  = implode(',', array_fill(0, count($uniqueTownIds), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT id, name FROM `{$townsTable}` WHERE id IN ({$placeholders})",
                    ...$uniqueTownIds,
                ),
                ARRAY_A,
            );
            foreach ((array) $rows as $row) {
                $townMap[(int) $row['id']] = (string) $row['name'];
            }
        }

        // HPOS-aware edit URL helper.
        $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

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

            $orderId = $order->get_id();

            if ($hpos) {
                $editUrl = admin_url('admin.php?page=wc-orders&action=edit&id=' . $orderId);
            } else {
                $editUrl = admin_url('post.php?post=' . $orderId . '&action=edit');
            }

            // Shipping address: town from šifarnik map, then street + number, then postcode.
            $townId   = (string) ($order->get_meta('_shipping_town_id') ?? '');
            $townName = $townId !== '' && isset($townMap[(int) $townId])
                ? $townMap[(int) $townId]
                : (string) $order->get_shipping_city();

            $streetName   = (string) ($order->get_meta('_shipping_street_name') ?? '');
            $streetNumber = (string) ($order->get_meta('_shipping_street_number') ?? '');
            $street       = trim($streetName . ($streetNumber !== '' ? ' ' . $streetNumber : ''));

            $postcode = (string) $order->get_shipping_postcode();

            $addressParts    = array_filter([$townName, $street, $postcode], static fn(string $p) => $p !== '');
            $shippingAddress = implode(', ', $addressParts);

            // Order items — max 3, with overflow label.
            $rawItems  = $order->get_items();
            $items     = [];
            $itemCount = 0;
            foreach ($rawItems as $item) {
                if ($itemCount < 3) {
                    $items[] = [
                        'name' => (string) $item->get_name(),
                        'qty'  => (int) $item->get_quantity(),
                    ];
                }
                $itemCount++;
            }
            if ($itemCount > 3) {
                $items[] = [
                    'name' => sprintf(
                        /* translators: %d: number of additional items */
                        __('+ %d više', 'dexpress-woocommerce'),
                        $itemCount - 3,
                    ),
                    'qty'  => 0,
                ];
            }

            $result[] = [
                'id'                   => $orderId,
                'number'               => $order->get_order_number(),
                'customer'             => $customer,
                'customer_email'       => (string) $order->get_billing_email(),
                'customer_phone'       => (string) $order->get_billing_phone(),
                'order_total'          => wp_strip_all_tags((string) wc_price($order->get_total())),
                'edit_url'             => $editUrl,
                'is_shop'              => $isShop,
                'shipping_address'     => $shippingAddress,
                'payment_method'       => (string) $order->get_payment_method(),
                'payment_method_title' => (string) $order->get_payment_method_title(),
                'is_paid'              => $order->is_paid(),
                'items'                => $items,
            ];
        }

        return $result;
    }

    /**
     * Prikazuje sekciju "Narudžbine na čekanju" sa tabelom i dugmetom za grupno kreiranje.
     */
    private function renderPendingOrders(): void
    {
        $orders   = $this->getPendingOrders();
        $profiles = $this->profiles->findAll();

        if (empty($orders)) {
            return;
        }

        $bulkNonce   = wp_create_nonce('dexpress_bulk_init');
        $bulkPageUrl = admin_url('admin.php');
        $count       = count($orders);
        $profilesUrl = admin_url('admin.php?page=' . PackageProfilesPage::PAGE_SLUG);
        ?>
<h2><?= esc_html(sprintf(
    /* translators: %d: number of pending orders */
    __('Narudžbine na čekanju (%d)', 'dexpress-woocommerce'),
    $count,
)) ?></h2>

<div class="tablenav top">
    <div class="alignleft actions">
        <?php if (!empty($profiles)): ?>
        <label for="dex-pending-profile" style="font-weight:600;margin-right:4px;">
            <?= esc_html__('Profil paketa:', 'dexpress-woocommerce') ?>
        </label>
        <select id="dex-pending-profile">
            <option value=""><?= esc_html__('— bez profila —', 'dexpress-woocommerce') ?></option>
            <?php foreach ($profiles as $p): ?>
            <option value="<?= (int) $p['id'] ?>"<?= !empty($p['is_default']) ? ' selected' : '' ?>>
                <?= esc_html((string) $p['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
        <span>
            <?= sprintf(
                /* translators: %s: link to profiles page */
                esc_html__('Nema profila paketa. %s', 'dexpress-woocommerce'),
                '<a href="' . esc_url($profilesUrl) . '">' . esc_html__('Kreirajte profil', 'dexpress-woocommerce') . '</a>',
            ) ?>
        </span>
        <?php endif; ?>
        <button type="button" class="button button-primary" id="dex-pending-bulk-btn" disabled style="margin-left:8px;">
            <?= esc_html__('Kreiraj D-Express pošiljke', 'dexpress-woocommerce') ?>
        </button>
    </div>
    <br class="clear">
</div>

<p class="description" style="margin-bottom:10px;">
    <?= esc_html__('Narudžbine u statusu "U obradi" sa D-Express metodom dostave za koje još nije kreirana pošiljka.', 'dexpress-woocommerce') ?>
</p>

<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <td class="manage-column check-column">
                <input type="checkbox" id="dex-pending-check-all"
                       title="<?= esc_attr__('Označi sve', 'dexpress-woocommerce') ?>" />
            </td>
            <th class="manage-column column-primary"><?= esc_html__('Narudžbina', 'dexpress-woocommerce') ?></th>
            <th class="manage-column"><?= esc_html__('Kupac', 'dexpress-woocommerce') ?></th>
            <th class="manage-column"><?= esc_html__('Adresa dostave', 'dexpress-woocommerce') ?></th>
            <th class="manage-column"><?= esc_html__('Proizvodi', 'dexpress-woocommerce') ?></th>
            <th class="manage-column"><?= esc_html__('Plaćanje / Iznos', 'dexpress-woocommerce') ?></th>
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
            <td class="column-primary">
                <strong>
                    <a href="<?= esc_url((string) $o['edit_url']) ?>" class="row-title">
                        #<?= esc_html((string) $o['number']) ?>
                    </a>
                </strong>
            </td>
            <td>
                <strong><?= esc_html((string) $o['customer']) ?></strong><br>
                <?php if ($o['customer_email'] !== ''): ?>
                <span class="description"><?= esc_html((string) $o['customer_email']) ?></span><br>
                <?php endif; ?>
                <?php if ($o['customer_phone'] !== ''): ?>
                <span class="description"><?= esc_html((string) $o['customer_phone']) ?></span><br>
                <?php endif; ?>
                <em><?= $o['is_shop']
                    ? esc_html__('Paketomat / Paket Shop', 'dexpress-woocommerce')
                    : esc_html__('Obična dostava', 'dexpress-woocommerce') ?></em>
            </td>
            <td>
                <?php if ($o['shipping_address'] !== ''): ?>
                <?= esc_html((string) $o['shipping_address']) ?>
                <?php else: ?>
                <span class="description">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!empty($o['items'])): ?>
                <ul style="margin:0;padding:0;list-style:none;">
                    <?php foreach ($o['items'] as $item): ?>
                    <li>
                        <?php if ($item['qty'] > 0): ?>
                        <span class="description"><?= (int) $item['qty'] ?>×</span>
                        <?php endif; ?>
                        <?= esc_html((string) $item['name']) ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <span class="description">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($o['payment_method'] === 'cod'): ?>
                <mark class="order-status"><span><?= esc_html__('Pouzećem', 'dexpress-woocommerce') ?></span></mark>
                <?php elseif ($o['is_paid']): ?>
                <mark class="order-status status-completed"><span><?= esc_html__('Plaćeno', 'dexpress-woocommerce') ?></span></mark>
                <?php else: ?>
                <span class="description"><?= esc_html((string) $o['payment_method_title']) ?></span>
                <?php endif; ?>
                <br><strong><?= esc_html((string) $o['order_total']) ?></strong>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<input type="hidden" id="dex-bulk-init-nonce" value="<?= esc_attr($bulkNonce) ?>">
<input type="hidden" id="dex-bulk-page-url"   value="<?= esc_attr($bulkPageUrl) ?>">
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
        var nonce     = $('#dex-bulk-init-nonce').val();
        var base      = $('#dex-bulk-page-url').val();
        var profileId = $('#dex-pending-profile').val() || '';
        var url = base + '?page=dexpress-bulk-shipment&order_ids='
            + ids.join(',') + '&_wpnonce=' + encodeURIComponent(nonce);
        if (profileId) { url += '&profile_id=' + encodeURIComponent(profileId); }
        window.location.href = url;
    });
})(jQuery);
</script>
        <?php
    }

}
