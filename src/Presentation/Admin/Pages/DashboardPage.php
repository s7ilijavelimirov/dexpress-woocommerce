<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;

final class DashboardPage
{
    private const TRANSIENT_KEY = 'dexpress_dashboard_stats';
    private const TRANSIENT_TTL = 5 * MINUTE_IN_SECONDS;

    /** @var array<string, string> Human-readable status labels */
    private const STATUS_LABELS = [
        'delivered'        => 'Dostavljeno',
        'in_transit'       => 'U tranzitu',
        'out_for_delivery' => 'Na dostavi',
        'problem_failed'   => 'Problem',
        'other'            => 'Ostalo',
    ];

    /** @var array<string, string> CSS badge modifier per status */
    private const STATUS_BADGE = [
        'delivered'        => 'delivered',
        'in_transit'       => 'transit',
        'out_for_delivery' => 'transit',
        'problem_failed'   => 'problem',
        'other'            => 'other',
    ];

    public function __construct(
        private readonly OptionsRepository $options,
    ) {}

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nemate dozvolu.', 'dexpress-woocommerce'));
        }

        global $wpdb;

        $stats   = $this->getStats($wpdb);
        $recent  = $this->getRecentShipments($wpdb);
        $chart   = $this->getChartData($wpdb);
        $sysInfo = $this->getSystemInfo();

        $shipmentsUrl   = esc_url(admin_url('admin.php?page=dexpress-shipments'));
        $settingsUrl    = esc_url(admin_url('admin.php?page=dexpress-settings'));
        $diagnosticsUrl = esc_url(admin_url('admin.php?page=dexpress-diagnostics'));
        $paymentsUrl    = esc_url(admin_url('admin.php?page=dexpress-payments'));
        $onboardingUrl  = esc_url(admin_url('admin.php?page=' . OnboardingPage::PAGE_SLUG));

        ?>
<div class="wrap dex-dashboard">
    <h1 class="wp-heading-inline"><?= esc_html__('D Express — pregled', 'dexpress-woocommerce') ?></h1>

    <!-- Stat cards -->
    <div class="dex-dash-stats">
        <div class="dex-stat-card">
            <p class="dex-stat-card__label"><?= esc_html__('Pošiljke ovaj mesec', 'dexpress-woocommerce') ?></p>
            <p class="dex-stat-card__value"><?= (int) $stats['month'] ?></p>
        </div>
        <div class="dex-stat-card dex-stat-card--delivered">
            <p class="dex-stat-card__label"><?= esc_html__('Dostavljeno', 'dexpress-woocommerce') ?></p>
            <p class="dex-stat-card__value"><?= (int) $stats['delivered'] ?></p>
        </div>
        <div class="dex-stat-card dex-stat-card--transit">
            <p class="dex-stat-card__label"><?= esc_html__('U tranzitu', 'dexpress-woocommerce') ?></p>
            <p class="dex-stat-card__value"><?= (int) $stats['in_transit'] ?></p>
        </div>
        <div class="dex-stat-card dex-stat-card--problem">
            <p class="dex-stat-card__label"><?= esc_html__('Problem', 'dexpress-woocommerce') ?></p>
            <p class="dex-stat-card__value"><?= (int) $stats['problem'] ?></p>
        </div>
    </div>

    <!-- Two-column body -->
    <div class="dex-dash-body">

        <!-- LEFT: bar chart + recent shipments -->
        <div class="dex-dash-col-main">

            <!-- 7-day bar chart -->
            <div class="dex-dash-card">
                <div class="dex-dash-card__head">
                    <h2 class="dex-dash-card__title"><?= esc_html__('Pošiljke po danima (7 dana)', 'dexpress-woocommerce') ?></h2>
                </div>
                <div class="dex-dash-card__body">
                    <?php $this->renderBarChart($chart); ?>
                </div>
            </div>

            <!-- Recent shipments -->
            <div class="dex-dash-card">
                <div class="dex-dash-card__head">
                    <h2 class="dex-dash-card__title"><?= esc_html__('Poslednje pošiljke', 'dexpress-woocommerce') ?></h2>
                    <a href="<?= $shipmentsUrl ?>" class="button button-small"><?= esc_html__('Sve pošiljke', 'dexpress-woocommerce') ?></a>
                </div>
                <?php $this->renderRecentShipments($recent); ?>
            </div>

        </div>

        <!-- RIGHT: quick actions + system status -->
        <div class="dex-dash-col-side">

            <!-- Quick actions -->
            <div class="dex-dash-card">
                <div class="dex-dash-card__head">
                    <h2 class="dex-dash-card__title"><?= esc_html__('Brze akcije', 'dexpress-woocommerce') ?></h2>
                </div>
                <div class="dex-dash-card__body">
                    <ul class="dex-quick-actions">
                        <li><a href="<?= $shipmentsUrl ?>">
                            <span class="dashicons dashicons-email-alt"></span>
                            <?= esc_html__('Lista pošiljaka', 'dexpress-woocommerce') ?>
                        </a></li>
                        <li><a href="<?= $paymentsUrl ?>">
                            <span class="dashicons dashicons-money-alt"></span>
                            <?= esc_html__('Otkupnine', 'dexpress-woocommerce') ?>
                        </a></li>
                        <li><a href="<?= $settingsUrl ?>">
                            <span class="dashicons dashicons-admin-settings"></span>
                            <?= esc_html__('Podešavanja', 'dexpress-woocommerce') ?>
                        </a></li>
                        <li><a href="<?= $diagnosticsUrl ?>">
                            <span class="dashicons dashicons-search"></span>
                            <?= esc_html__('Dijagnostika', 'dexpress-woocommerce') ?>
                        </a></li>
                        <li><a href="<?= $onboardingUrl ?>">
                            <span class="dashicons dashicons-welcome-widgets-menus"></span>
                            <?= esc_html__('Čarobnjak podešavanja', 'dexpress-woocommerce') ?>
                        </a></li>
                    </ul>
                </div>
            </div>

            <!-- System status -->
            <div class="dex-dash-card">
                <div class="dex-dash-card__head">
                    <h2 class="dex-dash-card__title"><?= esc_html__('Status sistema', 'dexpress-woocommerce') ?></h2>
                </div>
                <div class="dex-dash-card__body">
                    <?php $this->renderSystemStatus($sysInfo); ?>
                </div>
            </div>

        </div>
    </div>
</div>
        <?php
    }

    // ─── Queries ───────────────────────────────────────────────────────────────

    /**
     * Loads stat counters from transient cache or runs DB queries.
     *
     * @return array{month: int, delivered: int, in_transit: int, problem: int}
     */
    private function getStats(\wpdb $wpdb): array
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $t         = $wpdb->prefix . 'dexpress_shipments';
        $firstDay  = gmdate('Y-m-01');

        $month = (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM `{$t}` WHERE deleted_at IS NULL AND created_at >= %s",
                $firstDay,
            ),
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $delivered = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$t}` WHERE deleted_at IS NULL AND status = 'delivered'",
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $in_transit = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$t}` WHERE deleted_at IS NULL AND status IN ('in_transit','out_for_delivery')",
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $problem = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$t}` WHERE deleted_at IS NULL AND status = 'problem_failed'",
        );

        $stats = compact('month', 'delivered', 'in_transit', 'problem');
        set_transient(self::TRANSIENT_KEY, $stats, self::TRANSIENT_TTL);

        return $stats;
    }

    /**
     * Last 10 shipments with the first package tracking code via JOIN.
     *
     * @return list<object>
     */
    private function getRecentShipments(\wpdb $wpdb): array
    {
        $s = $wpdb->prefix . 'dexpress_shipments';
        $p = $wpdb->prefix . 'dexpress_packages';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT s.id, s.order_id, s.status, s.status_label_snapshot, s.send_status, s.created_at,
                    MIN(p.code) AS package_code
             FROM `{$s}` s
             LEFT JOIN `{$p}` p ON p.shipment_id = s.id
             WHERE s.deleted_at IS NULL
             GROUP BY s.id
             ORDER BY s.id DESC
             LIMIT 10",
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Shipment count per day for the last 7 days (oldest first).
     *
     * @return list<array{label: string, count: int}>
     */
    private function getChartData(\wpdb $wpdb): array
    {
        $t = $wpdb->prefix . 'dexpress_shipments';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT DATE(created_at) AS day, COUNT(*) AS cnt
             FROM `{$t}`
             WHERE deleted_at IS NULL AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
             GROUP BY DATE(created_at)",
            ARRAY_A,
        );

        $byDay = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $byDay[(string) $row['day']] = (int) $row['cnt'];
            }
        }

        $result = [];
        for ($i = 6; $i >= 0; $i--) {
            $date     = gmdate('Y-m-d', (int) strtotime("-{$i} days"));
            $label    = gmdate('d.m', (int) strtotime($date));
            $result[] = ['label' => $label, 'count' => $byDay[$date] ?? 0];
        }

        return $result;
    }

    /**
     * Reads options for system status panel.
     *
     * @return array<string, mixed>
     */
    private function getSystemInfo(): array
    {
        $username      = $this->options->getString('api.username', '');
        $password      = $this->options->getString('api.password', '');
        $credentialsOk = $username !== '' && $password !== '';

        $webhookUrl = home_url('/wp-json/dexpress/v1/notify');

        $syncKeys = [
            'Gradovi'    => 'sync.last_towns',
            'Ulice'      => 'sync.last_streets',
            'Paketomati' => 'sync.last_dispensers',
            'Lokacije'   => 'sync.last_locations',
        ];

        $sync = [];
        foreach ($syncKeys as $label => $key) {
            $raw = $this->options->getString($key, '');
            if ($raw !== '' && strlen($raw) === 14) {
                $dt           = \DateTime::createFromFormat('YmdHis', $raw);
                $sync[$label] = $dt !== false ? $dt->format('d.m.Y H:i') : $raw;
            } else {
                $sync[$label] = '—';
            }
        }

        return [
            'credentials' => $credentialsOk ? 'ok' : 'warn',
            'username'    => $username !== '' ? $username : '—',
            'webhook'     => $webhookUrl,
            'sync'        => $sync,
            'php'         => PHP_VERSION,
            'plugin'      => DEXPRESS_VERSION,
        ];
    }

    // ─── Render helpers ────────────────────────────────────────────────────────

    /**
     * @param list<array{label: string, count: int}> $chart
     */
    private function renderBarChart(array $chart): void
    {
        $max = max(1, ...array_column($chart, 'count'));

        echo '<div class="dex-bar-chart">';
        foreach ($chart as $day) {
            $pct  = (int) round($day['count'] / $max * 100);
            $zero = $day['count'] === 0 ? ' dex-bar--zero' : '';
            printf(
                '<div class="dex-bar-chart__col" title="%s pošiljak(i)"><div class="dex-bar%s" style="--bar-h:%d%%"></div><span class="dex-bar-chart__label">%s</span></div>',
                esc_attr((string) $day['count']),
                esc_attr($zero),
                $pct,
                esc_html($day['label']),
            );
        }
        echo '</div>';
    }

    /**
     * @param list<object> $recent
     */
    private function renderRecentShipments(array $recent): void
    {
        if (empty($recent)) {
            echo '<div class="dex-dash-card__body"><p class="description">'
                . esc_html__('Nema pošiljaka.', 'dexpress-woocommerce')
                . '</p></div>';
            return;
        }

        echo '<table class="dex-dash-table">';
        echo '<thead><tr>'
            . '<th>' . esc_html__('Kod', 'dexpress-woocommerce') . '</th>'
            . '<th>' . esc_html__('Narudžbina', 'dexpress-woocommerce') . '</th>'
            . '<th>' . esc_html__('Status', 'dexpress-woocommerce') . '</th>'
            . '<th>' . esc_html__('Datum', 'dexpress-woocommerce') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($recent as $row) {
            $orderId  = (int) $row->order_id;
            $editLink = get_edit_post_link($orderId);
            $orderUrl = esc_url($editLink ?? admin_url('post.php?post=' . $orderId . '&action=edit'));
            $code     = isset($row->package_code) && $row->package_code !== null ? (string) $row->package_code : '—';
            $status   = isset($row->status) ? (string) $row->status : 'other';
            $badge    = self::STATUS_BADGE[$status] ?? 'other';
            $label    = (isset($row->status_label_snapshot) && $row->status_label_snapshot !== '')
                ? $row->status_label_snapshot
                : (self::STATUS_LABELS[$status] ?? $status);

            if (isset($row->send_status) && $row->send_status === 'pending_send') {
                $badge = 'pending';
                $label = __('Čeka slanje', 'dexpress-woocommerce');
            }

            $date = '';
            if (isset($row->created_at) && $row->created_at) {
                $dt   = \DateTime::createFromFormat('Y-m-d H:i:s', $row->created_at);
                $date = $dt !== false ? $dt->format('d.m.Y') : '';
            }

            printf(
                '<tr>'
                . '<td><span class="dex-code">%s</span></td>'
                . '<td><a href="%s">#%d</a></td>'
                . '<td><span class="dex-badge dex-badge--%s">%s</span></td>'
                . '<td>%s</td>'
                . '</tr>',
                esc_html($code),
                $orderUrl,
                $orderId,
                esc_attr($badge),
                esc_html((string) $label),
                esc_html($date),
            );
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<string, mixed> $info
     */
    private function renderSystemStatus(array $info): void
    {
        $credDot   = $info['credentials'] === 'ok' ? 'ok' : 'warn';
        $credLabel = $info['credentials'] === 'ok'
            ? esc_html__('Podešeno', 'dexpress-woocommerce')
            : esc_html__('Nije podešeno', 'dexpress-woocommerce');

        echo '<ul class="dex-status-list">';

        printf(
            '<li><span class="dex-status-list__key">%s</span>'
            . '<span class="dex-status-list__val"><span class="dex-status-dot dex-status-dot--%s"></span>%s (%s)</span></li>',
            esc_html__('API pristup', 'dexpress-woocommerce'),
            esc_attr($credDot),
            $credLabel,
            esc_html((string) $info['username']),
        );

        printf(
            '<li><span class="dex-status-list__key">%s</span>'
            . '<span class="dex-status-list__val"><code style="font-size:10px;word-break:break-all;">%s</code></span></li>',
            esc_html__('Webhook URL', 'dexpress-woocommerce'),
            esc_html((string) $info['webhook']),
        );

        /** @var array<string, string> $sync */
        $sync = $info['sync'];
        foreach ($sync as $label => $val) {
            printf(
                '<li><span class="dex-status-list__key">%s</span><span class="dex-status-list__val">%s</span></li>',
                esc_html($label),
                esc_html($val),
            );
        }

        printf(
            '<li><span class="dex-status-list__key">PHP / Plugin</span>'
            . '<span class="dex-status-list__val">%s / v%s</span></li>',
            esc_html((string) $info['php']),
            esc_html((string) $info['plugin']),
        );

        echo '</ul>';
    }
}
