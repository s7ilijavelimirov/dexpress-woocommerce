<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence;

final class DatabaseInstaller
{
    public const DB_VERSION_OPTION = 'dexpress_db_version';

    public const DB_VERSION = '2.8.0';

    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    /**
     * Runs dbDelta when the stored schema version is older than {@see DB_VERSION}.
     */
    public static function maybeUpgrade(\wpdb $wpdb): void
    {
        $installer = new self($wpdb);
        $current   = (string) get_option(self::DB_VERSION_OPTION, '');

        if ($current !== self::DB_VERSION) {
            $installer->install();
        }

        // dbDelta često ne doda nove kolone na postojeće tabele — idempotentno dopuni.
        $installer->ensurePackagesContentNoteColumn();
        $installer->ensureShipmentStatusPresentationColumns();
    }

    public function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $this->wpdb->get_charset_collate();

        foreach ($this->tableDefinitions($charset) as $sql) {
            dbDelta($sql);
        }

        $this->ensurePackagesContentNoteColumn();
        $this->ensureShipmentStatusPresentationColumns();

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * D Express sID + snapshot labele; kolona status sadrži {@see \S7codedesign\DExpress\Domain\Shipment\StatusEmailBucket}.
     */
    private function ensureShipmentStatusPresentationColumns(): void
    {
        $shipments = $this->wpdb->prefix . 'dexpress_shipments';
        $history    = $this->wpdb->prefix . 'dexpress_shipment_statuses';

        $exists = $this->wpdb->get_var(
            $this->wpdb->prepare('SHOW TABLES LIKE %s', $shipments),
        );
        if ($exists !== $shipments) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $cols = $this->wpdb->get_col("SHOW COLUMNS FROM `{$shipments}`", 0);
        if (!is_array($cols)) {
            $cols = [];
        }

        if (!in_array('current_sid', $cols, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->wpdb->query(
                "ALTER TABLE `{$shipments}` ADD COLUMN current_sid INT NULL DEFAULT NULL AFTER status",
            );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $cols = $this->wpdb->get_col("SHOW COLUMNS FROM `{$shipments}`", 0);
        if (!is_array($cols)) {
            $cols = [];
        }
        if (!in_array('status_label_snapshot', $cols, true)) {
            $after = in_array('current_sid', $cols, true) ? 'current_sid' : 'status';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->wpdb->query(
                "ALTER TABLE `{$shipments}` ADD COLUMN status_label_snapshot VARCHAR(200) NOT NULL DEFAULT '' AFTER {$after}",
            );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->wpdb->query("UPDATE `{$shipments}` SET current_sid = 0 WHERE current_sid IS NULL");

        // Migracija starog enum stringa → StatusEmailBucket vrednosti.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->wpdb->query(
            "UPDATE `{$shipments}` SET status = CASE status
                WHEN 'delivered' THEN 'delivered'
                WHEN 'in_transit' THEN 'in_transit'
                WHEN 'out_for_delivery' THEN 'out_for_delivery'
                WHEN 'delivery_failed' THEN 'problem_failed'
                WHEN 'problem' THEN 'problem_failed'
                WHEN 'cancelled' THEN 'other'
                WHEN 'returned' THEN 'other'
                WHEN 'returning' THEN 'other'
                WHEN 'delayed' THEN 'other'
                WHEN 'pending' THEN 'other'
                WHEN 'accepted' THEN 'other'
                WHEN 'picked_up' THEN 'other'
                ELSE 'other' END
            WHERE status NOT IN ('delivered','in_transit','out_for_delivery','problem_failed','other')",
        );

        $existsHist = $this->wpdb->get_var(
            $this->wpdb->prepare('SHOW TABLES LIKE %s', $history),
        );
        if ($existsHist !== $history) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $hcols = $this->wpdb->get_col("SHOW COLUMNS FROM `{$history}`", 0);
        if (!is_array($hcols)) {
            $hcols = [];
        }
        if (!in_array('status_label_snapshot', $hcols, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->wpdb->query(
                "ALTER TABLE `{$history}` ADD COLUMN status_label_snapshot VARCHAR(200) NOT NULL DEFAULT '' AFTER status",
            );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->wpdb->query(
            "UPDATE `{$history}` SET status = CASE status
                WHEN 'delivered' THEN 'delivered'
                WHEN 'in_transit' THEN 'in_transit'
                WHEN 'out_for_delivery' THEN 'out_for_delivery'
                WHEN 'delivery_failed' THEN 'problem_failed'
                WHEN 'problem' THEN 'problem_failed'
                WHEN 'cancelled' THEN 'other'
                WHEN 'returned' THEN 'other'
                WHEN 'returning' THEN 'other'
                WHEN 'delayed' THEN 'other'
                WHEN 'pending' THEN 'other'
                WHEN 'accepted' THEN 'other'
                WHEN 'picked_up' THEN 'other'
                ELSE 'other' END
            WHERE status NOT IN ('delivered','in_transit','out_for_delivery','problem_failed','other')",
        );
    }

    /**
     * Dodaje content_note ako nedostaje (npr. posle nadogradnje kada dbDelta nije izvršio ALTER).
     */
    private function ensurePackagesContentNoteColumn(): void
    {
        $table = $this->wpdb->prefix . 'dexpress_packages';

        $tableExists = $this->wpdb->get_var(
            $this->wpdb->prepare('SHOW TABLES LIKE %s', $table),
        );
        if ($tableExists !== $table) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted prefix
        $col = $this->wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'content_note'");
        if (is_array($col) && $col !== []) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->wpdb->query(
            "ALTER TABLE `{$table}` ADD COLUMN content_note VARCHAR(50) NULL DEFAULT NULL AFTER reference_id",
        );
    }

    /**
     * Drop all plugin tables. Called from uninstall.php when the merchant
     * has opted in to "delete all data on uninstall".
     */
    public function uninstall(): void
    {
        // Drop in reverse dependency order (children before parents).
        $tables = [
            'dexpress_package_items',
            'dexpress_shipment_statuses',
            'dexpress_packages',
            'dexpress_shipments',
            'dexpress_webhook_logs',
            'dexpress_sender_locations',
            'dexpress_payments',
            'dexpress_streets',
            'dexpress_towns',
            'dexpress_municipalities',
            'dexpress_dispensers',
            'dexpress_locations',
            'dexpress_shops',
            'dexpress_centres',
            'dexpress_status_codes',
            'dexpress_package_profiles',
        ];

        foreach ($tables as $name) {
            $table = $this->wpdb->prefix . $name;
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        delete_option(self::DB_VERSION_OPTION);
    }

    /**
     * Returns the names of all plugin tables that currently exist in the DB.
     * Used to verify installation.
     *
     * @return string[]
     */
    public function installedTables(): array
    {
        $like   = $this->wpdb->esc_like($this->wpdb->prefix . 'dexpress_') . '%';
        $sql    = $this->wpdb->prepare('SHOW TABLES LIKE %s', $like);
        $result = $this->wpdb->get_col($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return $result;
    }

    /** @return string[] */
    private function tableDefinitions(string $charset): array
    {
        $p = $this->wpdb->prefix;

        return [
            $this->municipalities($p, $charset),
            $this->centres($p, $charset),
            $this->towns($p, $charset),
            $this->streets($p, $charset),
            $this->statusCodes($p, $charset),
            $this->dispensers($p, $charset),
            $this->locations($p, $charset),
            $this->shops($p, $charset),
            $this->senderLocations($p, $charset),
            $this->shipments($p, $charset),
            $this->packages($p, $charset),
            $this->packageItems($p, $charset),
            $this->shipmentStatuses($p, $charset),
            $this->webhookLogs($p, $charset),
            $this->payments($p, $charset),
            $this->packageProfiles($p, $charset),
        ];
    }

    // -------------------------------------------------------------------------
    // Reference tables — D Express ID is the PK (no AUTO_INCREMENT).
    // Upsert pattern (INSERT ... ON DUPLICATE KEY UPDATE) used during sync.
    // -------------------------------------------------------------------------

    private function municipalities(string $p, string $charset): string
    {
        return "CREATE TABLE IF NOT EXISTS {$p}dexpress_municipalities (
  id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  postal_code MEDIUMINT UNSIGNED NULL DEFAULT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id)
) $charset;";
    }

    private function centres(string $p, string $charset): string
    {
        return "CREATE TABLE IF NOT EXISTS {$p}dexpress_centres (
  id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  prefix VARCHAR(10) NULL DEFAULT NULL,
  address VARCHAR(150) NULL DEFAULT NULL,
  town_name VARCHAR(100) NULL DEFAULT NULL,
  town_id INT UNSIGNED NULL DEFAULT NULL,
  phone VARCHAR(20) NULL DEFAULT NULL,
  latitude VARCHAR(20) NULL DEFAULT NULL,
  longitude VARCHAR(20) NULL DEFAULT NULL,
  work_hours VARCHAR(100) NULL DEFAULT NULL,
  work_days VARCHAR(100) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY town_id (town_id)
) $charset;";
    }

    private function towns(string $p, string $charset): string
    {
        return "CREATE TABLE IF NOT EXISTS {$p}dexpress_towns (
  id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  display_name VARCHAR(150) NOT NULL,
  name_searchable VARCHAR(150) NOT NULL DEFAULT '',
  municipality_id INT UNSIGNED NULL DEFAULT NULL,
  centre_id INT UNSIGNED NULL DEFAULT NULL,
  postal_code MEDIUMINT UNSIGNED NULL DEFAULT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  delivery_days VARCHAR(20) NULL DEFAULT NULL,
  cut_off_pickup_time VARCHAR(10) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY name_searchable (name_searchable),
  KEY municipality_id (municipality_id),
  KEY centre_id (centre_id)
) $charset;";
    }

    private function streets(string $p, string $charset): string
    {
        return "CREATE TABLE IF NOT EXISTS {$p}dexpress_streets (
  id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  name_searchable VARCHAR(150) NOT NULL DEFAULT '',
  town_id INT UNSIGNED NOT NULL,
  deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY town_id (town_id),
  KEY town_name_searchable (town_id, name_searchable)
) $charset;";
    }

    private function statusCodes(string $p, string $charset): string
    {
        return "CREATE TABLE IF NOT EXISTS {$p}dexpress_status_codes (
  sid INT NOT NULL,
  name_sr VARCHAR(200) NOT NULL,
  name_en VARCHAR(200) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (sid)
) $charset;";
    }

    private function dispensers(string $p, string $charset): string
    {
        return "CREATE TABLE IF NOT EXISTS {$p}dexpress_dispensers (
  id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL DEFAULT '',
  address VARCHAR(150) NOT NULL DEFAULT '',
  town_name VARCHAR(100) NOT NULL DEFAULT '',
  town_id INT UNSIGNED NOT NULL DEFAULT 0,
  work_hours VARCHAR(100) NULL DEFAULT NULL,
  work_days VARCHAR(100) NULL DEFAULT NULL,
  latitude VARCHAR(20) NULL DEFAULT NULL,
  longitude VARCHAR(20) NULL DEFAULT NULL,
  pay_by_cash TINYINT(1) NOT NULL DEFAULT 0,
  pay_by_card TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY town_id (town_id)
) $charset;";
    }

    private function locations(string $p, string $charset): string
    {
        return "CREATE TABLE IF NOT EXISTS {$p}dexpress_locations (
  id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL DEFAULT '',
  description VARCHAR(255) NULL DEFAULT NULL,
  address VARCHAR(150) NOT NULL DEFAULT '',
  town_name VARCHAR(100) NOT NULL DEFAULT '',
  town_id INT UNSIGNED NOT NULL DEFAULT 0,
  work_hours VARCHAR(100) NULL DEFAULT NULL,
  work_days VARCHAR(100) NULL DEFAULT NULL,
  phone VARCHAR(20) NULL DEFAULT NULL,
  latitude VARCHAR(20) NULL DEFAULT NULL,
  longitude VARCHAR(20) NULL DEFAULT NULL,
  location_type VARCHAR(10) NULL DEFAULT NULL,
  pay_by_cash TINYINT(1) NOT NULL DEFAULT 0,
  pay_by_card TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY town_id (town_id)
) $charset;";
    }

    private function shops(string $p, string $charset): string
    {
        return "CREATE TABLE IF NOT EXISTS {$p}dexpress_shops (
  id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL DEFAULT '',
  description VARCHAR(255) NULL DEFAULT NULL,
  address VARCHAR(150) NOT NULL DEFAULT '',
  town_name VARCHAR(100) NOT NULL DEFAULT '',
  town_id INT UNSIGNED NOT NULL DEFAULT 0,
  work_hours VARCHAR(100) NULL DEFAULT NULL,
  work_days VARCHAR(100) NULL DEFAULT NULL,
  phone VARCHAR(20) NULL DEFAULT NULL,
  latitude VARCHAR(20) NULL DEFAULT NULL,
  longitude VARCHAR(20) NULL DEFAULT NULL,
  location_type VARCHAR(10) NULL DEFAULT NULL,
  pay_by_cash TINYINT(1) NOT NULL DEFAULT 0,
  pay_by_card TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY town_id (town_id)
) $charset;";
    }

    // -------------------------------------------------------------------------
    // Application tables — BIGINT UNSIGNED AUTO_INCREMENT PK.
    // Soft-deleted where history must be preserved.
    // -------------------------------------------------------------------------

    private function senderLocations(string $p, string $charset): string
    {
        return "CREATE TABLE IF NOT EXISTS {$p}dexpress_sender_locations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(50) NOT NULL,
  street_id INT UNSIGNED NOT NULL DEFAULT 0,
  street_name VARCHAR(50) NOT NULL,
  street_number VARCHAR(10) NOT NULL DEFAULT '',
  town_id INT UNSIGNED NOT NULL,
  address_desc VARCHAR(50) NULL DEFAULT NULL,
  contact_name VARCHAR(50) NULL DEFAULT NULL,
  contact_phone VARCHAR(15) NULL DEFAULT NULL,
  bank_account VARCHAR(25) NULL DEFAULT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY town_id (town_id),
  KEY is_default (is_default)
) $charset;";
    }

    private function shipments(string $p, string $charset): string
    {
        return "CREATE TABLE IF NOT EXISTS {$p}dexpress_shipments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  reference_id VARCHAR(50) NOT NULL,
  sender_location_id BIGINT UNSIGNED NOT NULL,
  send_status VARCHAR(20) NOT NULL DEFAULT 'pending_send',
  status VARCHAR(32) NOT NULL DEFAULT 'other',
  current_sid INT NULL DEFAULT NULL,
  status_label_snapshot VARCHAR(200) NOT NULL DEFAULT '',
  dl_type_id TINYINT UNSIGNED NOT NULL DEFAULT 2,
  payment_by TINYINT UNSIGNED NOT NULL DEFAULT 0,
  payment_type TINYINT UNSIGNED NOT NULL DEFAULT 2,
  value_para BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cod_amount_para BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cod_bank_account VARCHAR(25) NULL DEFAULT NULL,
  total_mass_grams INT UNSIGNED NOT NULL DEFAULT 0,
  content VARCHAR(50) NOT NULL DEFAULT '',
  note VARCHAR(150) NULL DEFAULT NULL,
  return_doc TINYINT UNSIGNED NOT NULL DEFAULT 0,
  self_drop_off TINYINT(1) NOT NULL DEFAULT 0,
  split_index TINYINT UNSIGNED NOT NULL DEFAULT 1,
  total_splits TINYINT UNSIGNED NOT NULL DEFAULT 1,
  api_response VARCHAR(10) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY reference_id (reference_id),
  KEY order_id (order_id),
  KEY status (status),
  KEY sender_location_id (sender_location_id),
  KEY order_created (order_id, created_at)
) $charset;";
    }

    private function packages(string $p, string $charset): string
    {
        return "CREATE TABLE IF NOT EXISTS {$p}dexpress_packages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shipment_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(12) NOT NULL,
  mass_grams INT UNSIGNED NULL DEFAULT NULL,
  dim_x SMALLINT UNSIGNED NULL DEFAULT NULL,
  dim_y SMALLINT UNSIGNED NULL DEFAULT NULL,
  dim_z SMALLINT UNSIGNED NULL DEFAULT NULL,
  vmass INT UNSIGNED NULL DEFAULT NULL,
  reference_id VARCHAR(50) NULL DEFAULT NULL,
  content_note VARCHAR(50) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY shipment_id (shipment_id)
) $charset;";
    }

    private function packageItems(string $p, string $charset): string
    {
        return "CREATE TABLE IF NOT EXISTS {$p}dexpress_package_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NOT NULL,
  quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY package_order_item (package_id, order_item_id),
  KEY package_id (package_id),
  KEY order_item_id (order_item_id)
) $charset;";
    }

    private function shipmentStatuses(string $p, string $charset): string
    {
        return "CREATE TABLE IF NOT EXISTS {$p}dexpress_shipment_statuses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shipment_id BIGINT UNSIGNED NOT NULL,
  sid INT NOT NULL,
  status VARCHAR(32) NOT NULL,
  status_label_snapshot VARCHAR(200) NOT NULL DEFAULT '',
  webhook_log_id BIGINT UNSIGNED NULL DEFAULT NULL,
  occurred_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY shipment_id (shipment_id),
  KEY shipment_occurred (shipment_id, occurred_at),
  KEY webhook_log_id (webhook_log_id)
) $charset;";
    }

    private function webhookLogs(string $p, string $charset): string
    {
        return "CREATE TABLE IF NOT EXISTS {$p}dexpress_webhook_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  notification_id VARCHAR(64) NOT NULL,
  package_code VARCHAR(16) NOT NULL,
  reference_id VARCHAR(50) NULL DEFAULT NULL,
  sid VARCHAR(10) NOT NULL,
  occurred_at DATETIME NOT NULL,
  received_at DATETIME NOT NULL,
  raw_payload LONGTEXT NOT NULL,
  processed TINYINT(1) NOT NULL DEFAULT 0,
  processed_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY notification_id (notification_id),
  KEY package_code (package_code),
  KEY reference_id (reference_id),
  KEY processed (processed)
) $charset;";
    }

    private function payments(string $p, string $charset): string
    {
        return "CREATE TABLE IF NOT EXISTS {$p}dexpress_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_reference VARCHAR(100) NOT NULL,
  shipment_code VARCHAR(16) NOT NULL,
  order_reference_id VARCHAR(50) NULL DEFAULT NULL,
  buyout_para BIGINT UNSIGNED NOT NULL DEFAULT 0,
  recipient_name VARCHAR(100) NULL DEFAULT NULL,
  recipient_address VARCHAR(150) NULL DEFAULT NULL,
  recipient_town VARCHAR(100) NULL DEFAULT NULL,
  payment_date DATE NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY payment_reference (payment_reference),
  KEY shipment_code (shipment_code),
  KEY order_reference_id (order_reference_id)
) $charset;";
    }

    private function packageProfiles(string $p, string $charset): string
    {
        return "CREATE TABLE IF NOT EXISTS {$p}dexpress_package_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  description TEXT NULL DEFAULT NULL,
  weight_grams INT UNSIGNED NOT NULL DEFAULT 0,
  dim_x INT UNSIGNED NULL DEFAULT NULL,
  dim_y INT UNSIGNED NULL DEFAULT NULL,
  dim_z INT UNSIGNED NULL DEFAULT NULL,
  default_content VARCHAR(50) NULL DEFAULT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY is_default (is_default)
) $charset;";
    }
}
