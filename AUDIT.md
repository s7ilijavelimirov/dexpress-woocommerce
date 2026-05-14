# D Express WooCommerce — Technical Audit

> Generated 2026-05-14. Living reference covering everything CLAUDE.md does not document in detail.
> Covers every PHP file in `src/`, all JS/CSS assets, all templates, api-docs, and DEV-LOG.

---

## 1. File Tree

```
dexpress-woocommerce/
├── dexpress-woocommerce.php          Main entry. Constants, PHP check, Plugin::boot()
├── uninstall.php                     Drops all 17 tables + dexpress_settings option on uninstall
├── composer.json                     PSR-4 autoload; requires action-scheduler ^3.7, no other deps
├── CLAUDE.md                         Developer guide (checked in)
├── AUDIT.md                          This file
├── DESIGN.md                         Design system reference (untracked)
│
├── api-docs/
│   ├── README.md                     API overview + auth flow
│   ├── authentication.md             HTTP Basic + AES password flow
│   ├── shipments-endpoint.md         addShipment field list + quirks
│   ├── payments-endpoint.md          viewpayments endpoint + CSV export
│   ├── webhook-spec.md               Inbound webhook payload spec
│   └── index-endpoints.md            All catalog/šifarnik endpoints
│
├── docs/
│   ├── DEV-LOG.md                    Changelog v2.0.0 – v2.6.0
│   └── audit-2026-05-09.md          Previous audit (all 6 issues resolved)
│
├── assets/
│   ├── css/
│   │   ├── admin.css                 Design tokens + shared admin components
│   │   ├── admin-design-system.css   [DELETED in working tree — superseded by admin.css]
│   │   ├── admin-shipments.css       [DELETED in working tree — was empty/stub]
│   │   ├── admin-dashboard.css       Dashboard-specific styles
│   │   ├── admin-metabox.css         Order metabox 3-state wizard
│   │   ├── admin-bulk-shipment.css   4-step bulk wizard
│   │   ├── admin-package-profiles.css Card grid + modal for profiles
│   │   ├── admin-onboarding.css      6-panel onboarding wizard
│   │   ├── admin-diagnostics.css     "Terminal" dark-theme diagnostics page
│   │   ├── checkout.css              Classic + block checkout address fields
│   │   └── package-shop-checkout.css Package shop modal + browser + map
│   │
│   ├── js/
│   │   ├── admin-metabox.js          3-state wizard (save/send/reprint)
│   │   ├── admin-settings.js         Settings page: password toggle, test conn, sync, modal
│   │   ├── admin-package-profiles.js Card CRUD: add/edit/delete/setDefault
│   │   ├── admin-bulk-shipment.js    4-step bulk wizard (save→print→send)
│   │   ├── admin-onboarding.js       6-panel onboarding (sync + method config)
│   │   ├── checkout.js               Classic/block checkout address autocomplete
│   │   └── package-shop-checkout.js  Package shop modal, map, dispenser browser
│   │
│   └── images/
│       ├── Dexpress-logo.jpg         Logo in settings header
│       └── Paketomat.jpg             Paketomat illustration (untracked)
│
├── src/
│   ├── Plugin.php                    Singleton; boots all providers; is_admin() guard
│   │
│   ├── Container/
│   │   ├── Container.php             PSR-11-compatible IoC (bind/singleton/instance/get/has)
│   │   └── Providers/
│   │       ├── ApiServiceProvider.php           DExpressApiClient + sync services
│   │       ├── AdminServiceProvider.php         All admin pages, AJAX, metabox hooks
│   │       ├── CheckoutServiceProvider.php      Checkout fields + validators + PS flow
│   │       ├── CronServiceProvider.php          WpCronScheduler + all sync service bindings
│   │       ├── DatabaseServiceProvider.php      DatabaseInstaller binding
│   │       ├── EmailServiceProvider.php         WC email classes + subscriber
│   │       ├── PersistenceServiceProvider.php   All repository bindings
│   │       ├── RestServiceProvider.php          WebhookController REST registration
│   │       ├── ShipmentServiceProvider.php      CreateShipmentService + allocator
│   │       ├── SimulationServiceProvider.php    SimulationService binding
│   │       └── WebhookServiceProvider.php       WebhookJobScheduler + processing services
│   │
│   ├── Domain/
│   │   ├── Address/
│   │   │   ├── PhoneNumber.php       Value object; isMobile() = 3816x prefix
│   │   │   └── StreetNumber.php      Value object; validates 15, 15a, 23/4, bb
│   │   ├── Money/
│   │   │   ├── Money.php             Stores para (integer); must be ≥ 0
│   │   │   └── Grams.php             Must be > 0
│   │   ├── PackageProfile/
│   │   │   └── PackageProfile.php    Entity: id, name, desc, weight_g, dx, dy, dz, content
│   │   ├── Payment/
│   │   │   └── Payment.php           Entity: id, reference, amount, order details
│   │   ├── Shipment/
│   │   │   ├── Shipment.php          Entity: full field set + factory fromDbRow()
│   │   │   ├── Package.php           Value object per-package inside a shipment
│   │   │   ├── PackageCode.php       Value object; PATTERN='/^[A-Z]{2}[0-9]{10}$/'
│   │   │   ├── DeliveryType.php      Enum: Urgent=1, Regular=2 + label()
│   │   │   ├── PaymentBy.php         Enum: Sender=0, Pickup=1, Recipient=2 + label()
│   │   │   ├── PaymentType.php       Enum: Cash=1, Invoice=2 + label()
│   │   │   ├── ReturnDoc.php         Enum: None=0, Documents=1, Pod=3 + label()
│   │   │   ├── ShipmentRepository.php  Interface: 14 methods
│   │   │   └── StatusEmailBucket.php Enum: DELIVERED, IN_TRANSIT, DELAYED, PROBLEM
│   │   └── Status/
│   │       └── StatusMapper.php      Maps sID int → StatusEmailBucket
│   │
│   ├── Application/
│   │   ├── Email/
│   │   │   └── EmailNotificationSubscriber.php  Listens to domain events; rate-limits 2h
│   │   ├── Shipment/
│   │   │   ├── CreateShipmentRequest.php  DTO: all fields for shipment creation
│   │   │   ├── CreateShipmentResult.php   DTO: success/error + shipmentId + packageCode
│   │   │   ├── CreateShipmentService.php  save() + send() + execute() wrapper
│   │   │   ├── OrderRecipientResolver.php Resolves recipient from WC_Order
│   │   │   ├── RecipientAddressCheckService.php  Validates town/street exist in DB
│   │   │   ├── ShipmentCodeAllocator.php  SELECT MAX…FOR UPDATE; transient-based warning
│   │   │   └── ShipmentStatusOrderNoteFormatter.php  Formats status notes for orders
│   │   ├── Simulation/
│   │   │   └── SimulationService.php      Timeline preview + step labels + AS scheduling
│   │   ├── Sync/
│   │   │   ├── SyncCatalogOrder.php       ALL_SEQUENCE constant; orchestrates sync order
│   │   │   ├── SyncResult.php             DTO: success/failure factories
│   │   │   ├── RowChangeStats.php         inserted/updated/unchanged/deleted + merge()
│   │   │   ├── SyncCentresService.php     GET /data/centres → upsertBatch
│   │   │   ├── SyncDispensersService.php  GET /data/dispensers → upsertBatch
│   │   │   ├── SyncLocationsService.php   GET /data/locations → upsertBatch
│   │   │   ├── SyncMunicipalitiesService.php  GET /data/municipalities → upsertBatch
│   │   │   ├── SyncPaymentsService.php    GET /data/viewpayments → upsertByReference
│   │   │   ├── SyncShopsService.php       GET /data/shops → upsertBatch
│   │   │   ├── SyncStatusCodesService.php GET /data/statuses → upsertBatch
│   │   │   ├── SyncStreetsService.php     GET /data/streets → upsertBatch
│   │   │   └── SyncTownsService.php       GET /data/towns → upsertBatch
│   │   └── Webhook/
│   │       ├── InboundStatusNotification.php  DTO: sID, shipmentCode, dateTime
│   │       ├── ProcessWebhookService.php      Processes AS job; updates DB + fires events
│   │       └── ShipmentStatusIngestionService.php  Persists status row; updates order note
│   │
│   ├── Infrastructure/
│   │   ├── Api/
│   │   │   ├── DExpressApiClient.php    HTTP Basic; all API methods (see §4)
│   │   │   └── DExpressEndpoints.php    ⚠ DEAD — Enum of path strings, never used
│   │   ├── Barcode/
│   │   │   └── Code128.php             Pure-PHP SVG barcode; START_A + SWITCH_TO_C
│   │   ├── Cron/
│   │   │   └── WpCronScheduler.php     Registers 4 custom schedules + hooks them
│   │   ├── Options/
│   │   │   ├── EncryptedString.php     AES-256-GCM; NONCE_LEN=12, TAG_LEN=16
│   │   │   └── OptionsRepository.php   Dot-notation over dexpress_settings option
│   │   ├── Persistence/
│   │   │   ├── DatabaseInstaller.php   DB_VERSION='2.4.0'; 17 tables via dbDelta
│   │   │   ├── TransactionRunner.php   wpdb transaction wrapper
│   │   │   ├── WpdbPackageItemRepository.php   Per-package order item allocation
│   │   │   ├── WpdbPackageProfileRepository.php  CRUD + setDefault; auto-default on first
│   │   │   ├── WpdbPackageRepository.php  saveForShipment/findByShipment/update
│   │   │   ├── WpdbPaymentRepository.php  upsertByReference + deleteMissing + CSV export
│   │   │   ├── WpdbSenderLocationRepository.php  Soft-delete; JOIN towns for display_name
│   │   │   ├── WpdbShipmentItemRepository.php  Aggregated quantities; replaceAggregated
│   │   │   ├── WpdbShipmentRepository.php  Full CRUD for shipments + status queries
│   │   │   ├── WpdbShipmentStatusRepository.php  Insert-only; throws on failure
│   │   │   ├── WpdbStreetRepository.php  search(townId, query, limit); filters deleted=1
│   │   │   ├── WpdbTownRepository.php  search/findById/findPostalDisplay
│   │   │   ├── WpdbWebhookLogRepository.php  Insert + prune old logs
│   │   │   ├── AddressSearchRepository.php  Unified town+street search for checkout
│   │   │   └── DispenserBrowserRepository.php  Merges locations+dispensers via LEFT JOIN
│   │   │   └── Sync/
│   │   │       ├── CentreRepository.php      upsertBatch centres
│   │   │       ├── DispenserRepository.php   upsertBatch dispensers
│   │   │       ├── LocationRepository.php    upsertBatch locations
│   │   │       ├── MunicipalityRepository.php  upsertBatch municipalities
│   │   │       ├── ShopRepository.php        upsertBatch shops
│   │   │       ├── StatusCodeRepository.php  upsertBatch + resolveDisplayLabel
│   │   │       ├── StreetRepository.php      upsertBatch streets + name_searchable
│   │   │       ├── TownRepository.php        upsertBatch towns + name_searchable
│   │   │       └── UpsertOutcome.php         Maps mysqli_affected_rows to insert/update/unchanged
│   │   └── Logging/
│   │       ├── Logger.php             PSR-3 subset; writes {uploads}/dexpress-logs/dexpress-{date}.log
│   │       └── StringNormalizer.php   Diacritics map (š→s, đ→dj, etc.) + mb_strtolower
│   │
│   └── Presentation/
│       ├── Admin/
│       │   ├── Ajax/
│       │   │   └── TestConnectionController.php  Falls back to stored creds; hits /data/statuses
│       │   ├── Hooks/
│       │   │   ├── OrdersBulkAction.php   MAX_ORDERS=20; adds bulk create action
│       │   │   └── OrdersListDeliveryStatusColumn.php  COLUMN_ID='dexpress_delivery_status'
│       │   ├── Label/
│       │   │   └── PrintLabelController.php  admin.php?page=dexpress-label; inline HTML/CSS; 1/2/4-up
│       │   ├── Menu/
│       │   │   └── AdminMenu.php         Slug 'dexpress' pos 54; 7 submenus; onboarding redirect
│       │   ├── Metabox/
│       │   │   ├── OrderShipmentMetabox.php  3-state machine; all wizard HTML
│       │   │   └── PackageShopInfoMetabox.php  Side metabox; shows PS location meta
│       │   └── Pages/
│       │       ├── DashboardPage.php        KPI grid + recent shipments + quick actions
│       │       ├── DiagnosticsPage.php      9 sync labels + log viewer last 200 lines
│       │       ├── OnboardingPage.php       6-panel wizard HTML
│       │       ├── PackageProfilesPage.php  Card grid + sidebar rules
│       │       ├── PaymentsPage.php         Sync box + filter + CSV export + WP_List_Table
│       │       ├── SettingsPage.php         8 tabs + unsaved modal + code range widget
│       │       └── ShipmentListTable.php    WP_List_Table; HPOS-aware order edit URLs
│       ├── Email/
│       │   ├── AbstractDExpressEmail.php       Base WC_Email; hydrateTemplateContext
│       │   └── EmailPackageShopReadyForPickup.php  id='dexpress_package_shop_ready_for_pickup'
│       ├── Frontend/
│       │   ├── Ajax/
│       │   │   ├── AutocompleteController.php   search_towns + search_streets (nopriv)
│       │   │   └── PackageShopDispenserController.php  dispensers endpoint; max 2000 results
│       │   └── Checkout/
│       │       ├── CheckoutFields.php           Classic + block checkout field registration
│       │       ├── CheckoutValidator.php        town/street/phone; MIN_TOWN_ID=100000
│       │       ├── PackageShopCustomerFlow.php  PS shipping method hooks; saves location meta
│       │       └── PackageShopInfoPanel.php     Info panel + Google Maps + 2 modal templates
│       ├── Hooks/
│       │   ├── HposCompatDeclarer.php     HPOS=true, cart_checkout_blocks=false
│       │   ├── PluginActivator.php        Install DB, generate passcode, schedule cron
│       │   └── PluginDeactivator.php      Unschedule all 4 cron + simulation AS; flush_rewrite
│       ├── Rest/
│       │   └── WebhookController.php      PUT+POST; IP allowlist; passcode; schedules AS job
│       └── Shipping/
│           ├── DexpressShippingMethod.php  METHOD_ID='dexpress'; standard door-to-door
│           └── DexpressPackageShopShippingMethod.php  METHOD_ID='dexpress_package_shop'
│
└── templates/
    ├── admin/
    │   ├── tab-api.php              API credentials + env toggle + code range monitor
    │   ├── tab-checkout.php         Checkout settings form
    │   ├── tab-email.php            Email settings form
    │   ├── tab-logging.php          Log level + retention settings
    │   ├── tab-sender-locations.php Locations table + add/edit modal
    │   ├── tab-sifarnici.php        Sync status table + danger zone
    │   ├── tab-simulation.php       Simulation config + flow diagram
    │   └── tab-webhook.php          Webhook URL + IP allowlist + passcode
    ├── checkout/
    │   ├── package-shop-browser-modal.php  Full browser modal HTML (JS-driven)
    │   ├── package-shop-info-panel.php     Inline info panel
    │   └── package-shop-modal.php          Onboarding modal HTML
    ├── emails/
    │   ├── dexpress-package-shop-ready-for-pickup.php  HTML email
    │   ├── dexpress-shipment-notification.php          HTML email
    │   ├── plain/dexpress-package-shop-ready-for-pickup.php
    │   └── plain/dexpress-shipment-notification.php
    └── myaccount/
        └── dexpress-shipments.php  My Account → tracking tab table
```

**Dead / orphaned files:**
- `src/Infrastructure/Api/DExpressEndpoints.php` — Enum defining endpoint path strings that are hardcoded directly inside `DExpressApiClient.php`. Never imported or used anywhere. Safe to delete.

---

## 2. Class Map & Dependencies

### Domain Value Objects / Enums

| Class | Path | Notes |
|-------|------|-------|
| `PhoneNumber` | `Domain/Address/PhoneNumber.php` | `fromString(string): self`; `isMobile(): bool` (3816x) |
| `StreetNumber` | `Domain/Address/StreetNumber.php` | `fromString(string): self`; validates 15, 15a, 23/4, bb |
| `Money` | `Domain/Money/Money.php` | `fromPara(int): self`; `toPara(): int`; must be ≥ 0 |
| `Grams` | `Domain/Money/Grams.php` | `fromGrams(int): self`; must be > 0 |
| `PackageCode` | `Domain/Shipment/PackageCode.php` | `PATTERN='/^[A-Z]{2}[0-9]{10}$/'`; `fromString(): self` |
| `DeliveryType` | `Domain/Shipment/DeliveryType.php` | Enum Urgent=1, Regular=2; `label(): string` |
| `PaymentBy` | `Domain/Shipment/PaymentBy.php` | Enum Sender=0, Pickup=1, Recipient=2; `label(): string` |
| `PaymentType` | `Domain/Shipment/PaymentType.php` | Enum Cash=1, Invoice=2; `label(): string` |
| `ReturnDoc` | `Domain/Shipment/ReturnDoc.php` | Enum None=0, Documents=1, Pod=3; `label(): string` |
| `StatusEmailBucket` | `Domain/Shipment/StatusEmailBucket.php` | Enum DELIVERED, IN_TRANSIT, DELAYED, PROBLEM |
| `StatusMapper` | `Domain/Status/StatusMapper.php` | `map(int $sId): StatusEmailBucket` |
| `UpsertOutcome` | `Infrastructure/Persistence/Sync/UpsertOutcome.php` | `fromMysqliAffectedRows(int): self`; `fromBatchWpdb(): self` |

### Domain Entities

| Class | Path | Constructor Deps | Key Methods |
|-------|------|-----------------|-------------|
| `Shipment` | `Domain/Shipment/Shipment.php` | None (factory `fromDbRow(array)`) | `getPackageCode()`, `getSendStatus()`, `toArray()` |
| `Package` | `Domain/Shipment/Package.php` | None | Weight/dims getters |
| `PackageProfile` | `Domain/PackageProfile/PackageProfile.php` | None | `fromDbRow(array)`, `isDefault(): bool` |
| `Payment` | `Domain/Payment/Payment.php` | None | `fromDbRow(array)` |

### Application Services

| Class | Constructor Deps | Key Public Methods |
|-------|----------------|--------------------|
| `CreateShipmentService` | `DExpressApiClient`, `ShipmentRepository`, `WpdbPackageRepository`, `WpdbPackageItemRepository`, `WpdbShipmentItemRepository`, `ShipmentCodeAllocator`, `WpdbSenderLocationRepository`, `Logger` | `save(CreateShipmentRequest): CreateShipmentResult`, `send(int $shipmentId): CreateShipmentResult`, `execute(CreateShipmentRequest): CreateShipmentResult` |
| `ShipmentCodeAllocator` | `ShipmentRepository`, `OptionsRepository` | `allocate(): PackageCode`; `consumeUsageWarningTransient(): ?string` (static) |
| `EmailNotificationSubscriber` | `OptionsRepository`, `Logger`, WC email instances | `onShipmentCreated(int, int)`, `onStatusUpdated(int, int, string, int)` |
| `SimulationService` | `OptionsRepository`, Action Scheduler | `buildAdminTimelinePreview(bool $quick): list<array>`, `simulationFlowStepLabels(): list<string>` |
| `ProcessWebhookService` | `WpdbWebhookLogRepository`, `ShipmentStatusIngestionService`, `Logger` | `handle(int $logId): void` |
| `ShipmentStatusIngestionService` | `WpdbShipmentRepository`, `WpdbShipmentStatusRepository`, `StatusMapper`, `ShipmentStatusOrderNoteFormatter`, `Logger` | `ingest(InboundStatusNotification): void` |
| `RecipientAddressCheckService` | `WpdbTownRepository`, `WpdbStreetRepository` | `check(int $townId, int $streetId): bool` |
| `OrderRecipientResolver` | None | `resolve(WC_Order): array` |
| `ShipmentStatusOrderNoteFormatter` | `StatusCodeRepository` | `format(int $sId, string $dt): string` |
| All 9 Sync services | `DExpressApiClient`, respective Sync repository, `OptionsRepository`, `Logger` | `sync(): SyncResult` |

### Infrastructure

| Class | Constructor Deps | Notes |
|-------|-----------------|-------|
| `DExpressApiClient` | `OptionsRepository` | Reads username + decrypts password per-request |
| `DatabaseInstaller` | `wpdb` (global) | `maybeUpgrade(): void`; idempotent ALTER helpers |
| `OptionsRepository` | `wpdb` (global) | `get/getString/set/delete` via dot-notation |
| `EncryptedString` | None (static factories) | `fromPlaintext(string): self`; `decrypt(): string`; `isEmpty(): bool` |
| `Logger` | `OptionsRepository` | PSR-3 subset; `debug/info/warning/error(string, array)` |
| `WpCronScheduler` | `OptionsRepository` | `register(): void` — adds 4 custom schedules |
| `Code128` | None | `generate(string $text): string` (SVG XML) |
| `StringNormalizer` | None (all static) | `normalize(string): string` — diacritics + lowercase |
| `TransactionRunner` | `wpdb` (global) | `run(callable): mixed` |
| `WpdbShipmentRepository` | `wpdb` | Implements `ShipmentRepository` interface (14 methods) |
| `DispenserBrowserRepository` | `wpdb` | `findAll(array $filters, int $limit): array` — LEFT JOIN merge |

### Presentation

| Class | Constructor Deps | Notes |
|-------|-----------------|-------|
| `AdminMenu` | `DashboardPage`, `ShipmentListTable`, `PaymentsPage`, `SettingsPage`, `PackageProfilesPage`, `BulkShipmentPage`, `OnboardingPage`, `DiagnosticsPage` | `register(): void` |
| `OrderShipmentMetabox` | `CreateShipmentService`, `WpdbShipmentRepository`, `WpdbPackageRepository`, `WpdbSenderLocationRepository`, `WpdbPackageProfileRepository`, `Logger` | `register(): void`; `render(WP_Post\|WC_Order): void` |
| `SettingsPage` | `OptionsRepository`, `WpdbSenderLocationRepository`, `ShipmentRepository`, `SimulationService` | `render(): void` |
| `WebhookController` | `OptionsRepository`, `WpdbWebhookLogRepository`, `WebhookJobScheduler` | `register(): void`; `handle(WP_REST_Request): WP_REST_Response` |
| `CheckoutFields` | `OptionsRepository` | `register(): void`; `saveFields(int $orderId, array $data): void` |
| `CheckoutValidator` | `WpdbTownRepository`, `WpdbStreetRepository` | `validate(array $fields, WP_Error): void` |
| `PackageShopInfoPanel` | `OptionsRepository`, `WpdbSenderLocationRepository` | `register(): void`; `render(): void` |
| `PrintLabelController` | `WpdbShipmentRepository`, `WpdbPackageRepository`, `WpdbSenderLocationRepository`, `Code128` | `handle(): void` — inline HTML/CSS output |

**Singleton vs per-call:**
- All services registered as **singleton** via `$c->singleton()` — one instance per request.
- No `$c->bind()` (per-call) usage in any provider — all shared.

---

## 3. Hooks Map

### WordPress / WooCommerce Core Hooks (`add_action` / `add_filter`)

| Hook | Callback | Priority | Args | Registering Class |
|------|----------|----------|------|-------------------|
| `plugins_loaded` | `Plugin::boot` | 10 | 0 | `dexpress-woocommerce.php` |
| `init` | `HposCompatDeclarer::declare` | 10 | 0 | `HposCompatDeclarer` |
| `woocommerce_init` | `CheckoutFields::register` | 10 | 0 | `CheckoutServiceProvider` |
| `woocommerce_shipping_init` | register shipping methods | 10 | 0 | `CheckoutServiceProvider` |
| `woocommerce_shipping_methods` filter | add dexpress + dexpress_package_shop | 10 | 1 | `CheckoutServiceProvider` |
| `admin_menu` | `AdminMenu::register` | 10 | 0 | `AdminServiceProvider` |
| `add_meta_boxes` | `OrderShipmentMetabox::register` | 10 | 0 | `AdminServiceProvider` |
| `add_meta_boxes` | `PackageShopInfoMetabox::register` | 10 | 0 | `AdminServiceProvider` |
| `manage_woocommerce_page_wc-orders_columns` filter | add delivery column | 20 | 1 | `OrdersListDeliveryStatusColumn` |
| `manage_woocommerce_page_wc-orders_custom_column` | render delivery badge | 10 | 2 | `OrdersListDeliveryStatusColumn` |
| `manage_shop_order_posts_columns` filter | add delivery column (legacy) | 20 | 1 | `OrdersListDeliveryStatusColumn` |
| `manage_shop_order_posts_custom_column` | render delivery badge (legacy) | 10 | 2 | `OrdersListDeliveryStatusColumn` |
| `bulk_actions-woocommerce_page_wc-orders` filter | add bulk create action | 10 | 1 | `OrdersBulkAction` |
| `handle_bulk_actions-woocommerce_page_wc-orders` | handle bulk action | 10 | 3 | `OrdersBulkAction` |
| `rest_api_init` | `WebhookController::register` | 10 | 0 | `RestServiceProvider` |
| `wp_ajax_dexpress_test_connection` | `TestConnectionController::handle` | 10 | 0 | `AdminServiceProvider` |
| `wp_ajax_dexpress_save_shipment_local` | `ShipmentWorkflowController::saveLocal` | 10 | 0 | `AdminServiceProvider` |
| `wp_ajax_dexpress_send_saved_shipment` | `ShipmentWorkflowController::sendSaved` | 10 | 0 | `AdminServiceProvider` |
| `wp_ajax_dexpress_save_package_profile` | `PackageProfileController::save` | 10 | 0 | `AdminServiceProvider` |
| `wp_ajax_dexpress_delete_package_profile` | `PackageProfileController::delete` | 10 | 0 | `AdminServiceProvider` |
| `wp_ajax_dexpress_set_default_profile` | `PackageProfileController::setDefault` | 10 | 0 | `AdminServiceProvider` |
| `wp_ajax_dexpress_save_sender_location` | `SenderLocationController::save` | 10 | 0 | `AdminServiceProvider` |
| `wp_ajax_dexpress_delete_sender_location` | `SenderLocationController::delete` | 10 | 0 | `AdminServiceProvider` |
| `wp_ajax_dexpress_set_default_location` | `SenderLocationController::setDefault` | 10 | 0 | `AdminServiceProvider` |
| `wp_ajax_dexpress_manual_sync` | `ManualSyncController::handle` | 10 | 0 | `AdminServiceProvider` |
| `wp_ajax_dexpress_sync_all` | `ManualSyncController::syncAll` | 10 | 0 | `AdminServiceProvider` |
| `wp_ajax_dexpress_save_settings` | `SettingsSaveHandler::handle` | 10 | 0 | `AdminServiceProvider` |
| `wp_ajax_nopriv_dexpress_search_towns` | `AutocompleteController::searchTowns` | 10 | 0 | `CheckoutServiceProvider` |
| `wp_ajax_dexpress_search_towns` | `AutocompleteController::searchTowns` | 10 | 0 | `CheckoutServiceProvider` |
| `wp_ajax_nopriv_dexpress_search_streets` | `AutocompleteController::searchStreets` | 10 | 0 | `CheckoutServiceProvider` |
| `wp_ajax_dexpress_search_streets` | `AutocompleteController::searchStreets` | 10 | 0 | `CheckoutServiceProvider` |
| `wp_ajax_nopriv_dexpress_get_dispensers` | `PackageShopDispenserController::handle` | 10 | 0 | `CheckoutServiceProvider` |
| `wp_ajax_dexpress_get_dispensers` | `PackageShopDispenserController::handle` | 10 | 0 | `CheckoutServiceProvider` |
| `dexpress_cron_daily` | sync dispensers + locations | 10 | 0 | `CronServiceProvider` |
| `dexpress_cron_monthly` | sync municipalities + centres + towns + streets + purge logs | 10 | 0 | `CronServiceProvider` |
| `dexpress_cron_quarterly` | sync shops | 10 | 0 | `CronServiceProvider` |
| `dexpress_cron_semi_annual` | sync status_codes | 10 | 0 | `CronServiceProvider` |
| `woocommerce_email_classes` filter | register 2 D-Express WC email classes | 10 | 1 | `EmailServiceProvider` |
| `woocommerce_checkout_order_created` | `PackageShopCustomerFlow::saveLocationOnOrder` | 10 | 1 | `CheckoutServiceProvider` |
| `woocommerce_blocks_checkout_order_processed` | same | 10 | 1 | `CheckoutServiceProvider` |
| `woocommerce_checkout_process` | `CheckoutValidator::validate` | 10 | 0 | `CheckoutServiceProvider` |
| `woocommerce_store_api_checkout_order_processed` (WC 9.9+) | `CheckoutValidator::validateOrderBeforePayment` | 10 | 1 | `CheckoutServiceProvider` |
| `wp_enqueue_scripts` | enqueue checkout assets | 10 | 0 | `CheckoutServiceProvider` |
| `admin_enqueue_scripts` | enqueue admin assets | 10 | 1 | `AdminServiceProvider` |
| `register_activation_hook` | `PluginActivator::activate` | — | — | `dexpress-woocommerce.php` |
| `register_deactivation_hook` | `PluginDeactivator::deactivate` | — | — | `dexpress-woocommerce.php` |

### Domain Events (`do_action`)

| Action | Args | Fired By | Consumed By |
|--------|------|----------|-------------|
| `dexpress/shipment.created` | `int $orderId, int $shipmentId` | `CreateShipmentService::send()` | `EmailNotificationSubscriber` |
| `dexpress/shipment.status_updated` | `int $orderId, int $shipmentId, string $bucket, int $sId` | `ShipmentStatusIngestionService::ingest()` | `EmailNotificationSubscriber` |
| `dexpress_shipment_status_updated` | `int $orderId, int $shipmentId, string $bucket, int $rawSid` | `ShipmentStatusIngestionService::ingest()` | Legacy — no internal consumers; for 3rd-party plugins |
| `dexpress_process_webhook_job` | `int $logId` | Action Scheduler | `ProcessWebhookService::handle()` |
| `dexpress_run_simulation_step` | `int $orderId, int $stepIndex, bool $quick` | Action Scheduler | `SimulationService` |

### `apply_filters` (Extension Points)

| Filter | Args | Used By |
|--------|------|---------|
| `dexpress_shipment_email_recipient` | `string $email, WC_Order $order` | `EmailNotificationSubscriber` — allows overriding email recipient |
| `dexpress_api_timeout` | `int $timeout` | `DExpressApiClient` — default 30s |
| `dexpress_checkout_phone_hint` | `string $hint` | `CheckoutFields` — phone hint text |

---

## 4. API Integration Deep Dive

### Base URL
`https://usersupport.dexpress.rs/ExternalApi`

### Authentication
HTTP Basic Auth header: `Authorization: Basic base64(username:password)`.
Password stored AES-256-GCM encrypted in `dexpress_settings.api.password`; decrypted per-request in `DExpressApiClient`.

### All Endpoints

| Method | Path | PHP method | Returns | Notes |
|--------|------|-----------|---------|-------|
| POST | `/data/addshipment` | `addShipment(array $payload)` | Plain text `"OK"` / `"TEST"` | NOT JSON. Test env = "TEST". |
| GET | `/data/statuses` | `getStatuses()` | JSON array | Used by test-connection check |
| GET | `/data/towns` | `getTowns()` | JSON array | ~5000 rows |
| GET | `/data/streets` | `getStreets()` | JSON array | Large — ~200k rows |
| GET | `/data/municipalities` | `getMunicipalities()` | JSON array | |
| GET | `/data/centres` | `getCentres()` | JSON array | |
| GET | `/data/dispensers` | `getDispensers()` | JSON array | Paketomat subset of locations |
| GET | `/data/locations` | `getLocations()` | JSON array | All pickup points (367 rows) |
| GET | `/data/shops` | `getShops()` | JSON array | |
| GET | `/data/viewpayments?PaymentReference=X` | `getPayments(string $ref)` | JSON array | Requires PaymentReference from bank statement |

### `addShipment` Payload — Field Order (as built by `buildApiPayload()`)

```
TT          string   Shipment code (PREFIX+10-digit padded, e.g. TT0000000001)
SenderID    int      Sender location ID from dexpress_sender_locations
SCity       int      Sender town ID
SZip        string   Sender postal code
SMunicipality string Sender municipality
SAddress    string   Sender street name
SNumber     string   Sender street number
SPhone      string   Sender phone
SName       string   Sender name

RCity       int      Recipient town ID (for PS: pickup location town)
RZip        string   Recipient postal code
RMunicipality string  Recipient municipality
RAddress    string   Recipient street
RNumber     string   Recipient street number
RPhone      string   Recipient phone (for PS: customer mobile)
RName       string   Recipient name (for PS: pickup location name)
RCName      string   Recipient contact name (PS only: customer name)
RCPhone     string   Recipient contact phone (PS only: customer phone)

PaymentBy   int      0=sender, 1=pickup, 2=recipient
PaymentType int      1=cash, 2=invoice
BuyOut      int      COD amount in para (0 if no COD)
BuyOutAccount string Bank account number — REQUIRED when BuyOut > 0
ReturnDoc   int      0=none, 1=documents, 3=POD
Delivery    int      1=urgent, 2=regular
Note        string   Shipment note (optional)
Content     string   Package content description

Mass        int      Weight in grams (first package; if multiple packages: sum)
SizeX       int      Length cm (first package)
SizeY       int      Width cm (first package)
SizeZ       int      Height cm (first package)

PackageCount int     Total number of packages
DispenserID int|null Paketomat dispenser ID — REQUIRED for location_type=2, omitted otherwise

ClientID    string   Client identifier (set in plugin settings)
```

**Known quirks:**
1. `addShipment` returns plain text, not JSON — `wp_remote_retrieve_body()` used directly.
2. `BuyOutAccount` is required when `BuyOut > 0` despite API docs marking it optional. Omitting it causes HTTP 400.
3. Test environment returns `"TEST"` instead of `"OK"` — both are success responses.
4. `DispenserID` must be omitted entirely (not sent as null) for non-paketomat orders.
5. `R*` fields for Package Shop are the **pickup location's** address, not the customer's billing address.
6. `Mass`, `SizeX/Y/Z` are always the **first package's** values even when `PackageCount > 1`.

### Webhook Inbound Payload

```json
{
  "ShipmentCode": "TT0000000001",
  "StatusID":     4,
  "StatusDate":   "2024-01-15T14:30:00",
  "PassCode":     "abc123"
}
```

`StatusDate` is stored as Europe/Belgrade local time; `WebhookController::parseDtUtcMysql()` converts to UTC before persisting.

---

## 5. Database Schema

### All 17 Tables

**`wp_dexpress_shipments`**
```sql
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
order_id        BIGINT UNSIGNED NOT NULL
tt_code         VARCHAR(20) NOT NULL UNIQUE
send_status     ENUM('pending_send','sent') NOT NULL DEFAULT 'pending_send'
environment     VARCHAR(20) NOT NULL DEFAULT 'test'
sender_location_id BIGINT UNSIGNED
delivery_type   TINYINT UNSIGNED
payment_by      TINYINT UNSIGNED
payment_type    TINYINT UNSIGNED
buy_out         INT UNSIGNED DEFAULT 0
buy_out_account VARCHAR(50)
return_doc      TINYINT UNSIGNED DEFAULT 0
self_drop_off   TINYINT UNSIGNED DEFAULT 0
note            TEXT
content         TEXT
client_id       VARCHAR(100)
created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
sent_at         DATETIME
```

**`wp_dexpress_packages`**
```sql
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
shipment_id     BIGINT UNSIGNED NOT NULL
weight_g        INT UNSIGNED NOT NULL
dim_x           SMALLINT UNSIGNED DEFAULT 0
dim_y           SMALLINT UNSIGNED DEFAULT 0
dim_z           SMALLINT UNSIGNED DEFAULT 0
package_index   TINYINT UNSIGNED NOT NULL DEFAULT 0
```

**`wp_dexpress_package_items`**
```sql
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
package_id      BIGINT UNSIGNED NOT NULL
order_item_id   BIGINT UNSIGNED NOT NULL
quantity        INT UNSIGNED NOT NULL
```

**`wp_dexpress_shipment_items`**
```sql
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
shipment_id     BIGINT UNSIGNED NOT NULL
order_item_id   BIGINT UNSIGNED NOT NULL
quantity        INT UNSIGNED NOT NULL
```

**`wp_dexpress_shipment_statuses`**
```sql
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
shipment_id     BIGINT UNSIGNED NOT NULL
status_id       INT NOT NULL
status_date     DATETIME NOT NULL
created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
```

**`wp_dexpress_webhook_logs`**
```sql
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
shipment_code   VARCHAR(20)
status_id       INT
status_date     DATETIME
raw_payload     TEXT
processed       TINYINT NOT NULL DEFAULT 0
created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
```

**`wp_dexpress_sender_locations`**
```sql
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
name            VARCHAR(200) NOT NULL
town_id         INT UNSIGNED NOT NULL
street_name     VARCHAR(200) NOT NULL
street_number   VARCHAR(30)
postal_code     VARCHAR(20)
phone           VARCHAR(30)
contact_name    VARCHAR(200)
is_default      TINYINT NOT NULL DEFAULT 0
deleted_at      DATETIME
created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
```

**`wp_dexpress_package_profiles`**
```sql
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
name            VARCHAR(200) NOT NULL
description     TEXT
weight_g        INT UNSIGNED NOT NULL
dim_x           SMALLINT UNSIGNED DEFAULT 0
dim_y           SMALLINT UNSIGNED DEFAULT 0
dim_z           SMALLINT UNSIGNED DEFAULT 0
content         VARCHAR(500)
is_default      TINYINT NOT NULL DEFAULT 0
created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
```

**`wp_dexpress_payments`**
```sql
id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
reference       VARCHAR(100) NOT NULL
amount          BIGINT NOT NULL
order_number    VARCHAR(100)
shipment_code   VARCHAR(20)
payment_date    DATETIME
created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
UNIQUE KEY (reference, shipment_code)
```

**`wp_dexpress_locations`**
```sql
id              INT UNSIGNED NOT NULL PRIMARY KEY
name            VARCHAR(300) NOT NULL
city            VARCHAR(200)
address         VARCHAR(300)
lat             DECIMAL(10,7)
lng             DECIMAL(10,7)
location_type   TINYINT UNSIGNED NOT NULL
town_id         INT UNSIGNED
zip             VARCHAR(20)
created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

**`wp_dexpress_dispensers`**
```sql
id              INT UNSIGNED NOT NULL PRIMARY KEY
name            VARCHAR(300) NOT NULL
city            VARCHAR(200)
address         VARCHAR(300)
lat             DECIMAL(10,7)
lng             DECIMAL(10,7)
location_id     INT UNSIGNED
created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

**`wp_dexpress_towns`**
```sql
id              INT UNSIGNED NOT NULL PRIMARY KEY
name            VARCHAR(200) NOT NULL
name_searchable VARCHAR(200) NOT NULL
postal_code     VARCHAR(20)
municipality_id INT UNSIGNED
centre_id       INT UNSIGNED
created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

**`wp_dexpress_streets`**
```sql
id              INT UNSIGNED NOT NULL PRIMARY KEY
name            VARCHAR(300) NOT NULL
name_searchable VARCHAR(300) NOT NULL
town_id         INT UNSIGNED NOT NULL
deleted         TINYINT NOT NULL DEFAULT 0
created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

**`wp_dexpress_municipalities`**
```sql
id              INT UNSIGNED NOT NULL PRIMARY KEY
name            VARCHAR(200) NOT NULL
created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
```

**`wp_dexpress_centres`**
```sql
id              INT UNSIGNED NOT NULL PRIMARY KEY
name            VARCHAR(200) NOT NULL
created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
```

**`wp_dexpress_status_codes`**
```sql
id              INT UNSIGNED NOT NULL PRIMARY KEY
label_sr        VARCHAR(500)
label_en        VARCHAR(500)
created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

**`wp_dexpress_shops`**
```sql
id              INT UNSIGNED NOT NULL PRIMARY KEY
name            VARCHAR(300)
city            VARCHAR(200)
address         VARCHAR(300)
created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### `wp_options` Keys (under `dexpress_settings`)

All stored as sub-keys under `dexpress_settings` option (dot-notation via `OptionsRepository`):

| Key | Type | Default | Notes |
|-----|------|---------|-------|
| `api.username` | string | `''` | Plain text |
| `api.password` | string | `''` | AES-256-GCM encrypted |
| `api.environment` | string | `'test'` | `'test'` or `'production'` |
| `api.client_id` | string | `''` | ClientID sent in addShipment payload |
| `shipment.prefix` | string | `''` | 2 uppercase letters, e.g. `TT` |
| `shipment.range_start` | int | `1` | Numeric range start |
| `shipment.range_end` | int | `99` | Numeric range end |
| `webhook.ip_address` | string | `''` | Single IP; empty = allow all |
| `webhook.passcode` | string | `''` | Plain SHA-256 hash stored; `hash_equals` comparison |
| `checkout.enabled` | bool | `true` | Enable D-Express checkout fields |
| `checkout.phone_required` | bool | `true` | Require phone at checkout |
| `email.shipment_notification` | bool | `true` | Send shipment status emails |
| `email.package_shop_ready` | bool | `true` | Send PS ready-for-pickup email |
| `logging.level` | string | `'error'` | debug/info/warning/error |
| `logging.retention_days` | int | `30` | Log file retention |
| `simulation.enabled` | bool | `false` | Enable status simulation for test orders |
| `simulation.quick` | bool | `false` | Quick timing (30/60/120/180s vs real 120/600/1500/2700s) |
| `sync.last_towns` | string | `''` | ISO datetime of last sync |
| `sync.last_streets` | string | `''` | |
| `sync.last_municipalities` | string | `''` | |
| `sync.last_centres` | string | `''` | |
| `sync.last_dispensers` | string | `''` | |
| `sync.last_locations` | string | `''` | |
| `sync.last_status_codes` | string | `''` | |
| `sync.last_shops` | string | `''` | |

**Standalone options (not under `dexpress_settings`):**

| Key | Notes |
|-----|-------|
| `dexpress_db_version` | Schema version string, e.g. `'2.4.0'` |
| `dexpress_onboarding_complete` | `'1'` when onboarding wizard finished |

### Transients

| Key | TTL | Purpose |
|-----|-----|---------|
| `dexpress_usage_warning` | Until consumed | Shipment code range usage warning (consumed once on settings render) |
| `dexpress_rate_limit_{orderId}_{emailType}` | 7200s (2h) | Email rate limit guard |
| `dexpress_onboarding_redirect` | 30s | Redirect to onboarding on first activation |

### Order / Post Meta Keys

| Key | Set By | Notes |
|-----|--------|-------|
| `_dexpress_package_shop_location_id` | `PackageShopCustomerFlow` | Selected location ID |
| `_dexpress_package_shop_location_type` | `PackageShopCustomerFlow` | 1=paket shop, 2=paketomat, 3=paket shop variant |
| `_dexpress_package_shop_location_name` | `PackageShopCustomerFlow` | Display name |
| `_dexpress_package_shop_location_city` | `PackageShopCustomerFlow` | City string |
| `_dexpress_package_shop_location_address` | `PackageShopCustomerFlow` | Address string |
| `_dexpress_package_shop_location_zip` | `PackageShopCustomerFlow` | Postal code |
| `_dexpress_package_shop_ready_email_sent` | `EmailNotificationSubscriber` | `'1'` — duplicate guard |
| `_dexpress_town_id` | `CheckoutFields` (classic checkout) | Selected town ID |
| `_dexpress_street_id` | `CheckoutFields` (classic checkout) | Selected street ID |
| `_dexpress_dispenser_id` | `CheckoutFields` | Paketomat dispenser ID |
| `_dexpress_checkout_location_type` | `CheckoutFields` | Mirrors package shop location type |

**WC Block Checkout Additional Fields** (stored in order meta by WC core):

| Field ID | Visible | Purpose |
|----------|---------|---------|
| `dexpress/street-number` | Yes | Street number input |
| `dexpress/town-id` | No (hidden) | Numeric town ID transport |
| `dexpress/street-id` | No (hidden) | Numeric street ID transport |

### User Meta Keys

None — plugin stores no per-user metadata.

---

## 6. JavaScript Audit

### `assets/js/admin-metabox.js`
**Handle:** `dexpress-metabox` | **Deps:** `jquery`, `wp-i18n`

**AJAX calls:**

| Action | Method | Nonce | Response |
|--------|--------|-------|---------|
| `dexpress_save_shipment_local` | POST | `dexpress_metabox` | `{ success, data: { shipmentId, packageCode } }` |
| `dexpress_send_saved_shipment` | POST | `dexpress_metabox` | `{ success, data: { message } }` |

**Behavior:**
- State machine driven by `data-state` attribute on `#dexpress-shipment-root`.
- Step 1 (packages): add/remove package cards dynamically; validates weight > 0.
- Step 2 (options): sender location, delivery type, payment, etc.
- Step 3 (summary): readonly preview before save.
- On save success → transitions to `pending_send` state (State B).
- Send button calls `dexpress_send_saved_shipment` → transitions to `sent` state (State C).
- `#dexpress-reprint-label` opens print label URL in new tab.
- Copy tracking code uses `navigator.clipboard.writeText()` with textarea fallback.

**Known issues:**
- If user navigates away mid-wizard and returns (e.g. saves draft order), wizard resets to Step 1 even if partial data was filled. No localStorage persistence.

---

### `assets/js/admin-settings.js`
**Handle:** `dexpress-admin-settings` | **Deps:** `jquery`
**Localized object:** `dexpressAdmin` (ajaxUrl, nonce, strings)

**AJAX calls:**

| Action | Method | Notes |
|--------|--------|-------|
| `dexpress_test_connection` | POST | Sends username + password; falls back to stored if fields empty |
| `dexpress_manual_sync` | POST | Requires `sync_type` param |
| `dexpress_sync_all` | POST | Runs all sync types in sequence |
| `dexpress_save_sender_location` | POST | Add/edit sender location |
| `dexpress_delete_sender_location` | POST | Delete sender location |
| `dexpress_set_default_location` | POST | Set default sender location |
| `dexpress_search_towns` | POST | Autocomplete for town field in location modal |
| `dexpress_search_streets` | POST | Autocomplete for street field in location modal |

**Behavior:**
- Password field: toggle between `type=password` and `type=text`; dashicons eye icon.
- Env toggle: visual `dex-env-option` cards + updates hidden `api.environment` input.
- Webhook URL copy button: `document.execCommand('copy')` (legacy) + `navigator.clipboard` fallback.
- Unsaved changes modal: intercepts tab navigation if any form `input`/`select`/`textarea` is dirty.
- Simulation tab: `is-active` toggle on timing panel; no AJAX (settings saved via form POST).

---

### `assets/js/admin-package-profiles.js`
**Handle:** `dexpress-package-profiles` | **Deps:** `jquery`
**Localized object:** `dexPP` (ajaxUrl, nonce, strings)

**AJAX calls:**

| Action | Method | Notes |
|--------|--------|-------|
| `dexpress_save_package_profile` | POST | id=0 for new; returns `{ id, name, weight_g, … }` |
| `dexpress_delete_package_profile` | POST | |
| `dexpress_set_default_profile` | POST | |

**Behavior:**
- `is-open` class on `#dex-pp-modal` controls modal visibility (CSS transition).
- Form pre-fills from clicked `.dex-pp-open-form` card's `data-*` attributes.
- On save: if no cards exist yet, reloads page to show first card; otherwise inserts card HTML dynamically.
- Gram helper: weight input shows live kg conversion in sibling span.

---

### `assets/js/admin-bulk-shipment.js`
**Handle:** `dexpress-bulk-shipment` | **Deps:** `jquery`
**Localized object:** `dexBulk` (ajaxUrl, nonce, orders array, strings)

**AJAX calls:**

| Action | Method | Notes |
|--------|--------|-------|
| `dexpress_save_shipment_local` | POST | Called per-order in Step 3 (sequential loop) |
| `dexpress_send_saved_shipment` | POST | Called per-order in Step 4 (sequential loop) |

**Behavior:**
- Step 1: select package profile (activates `.dex-profile-card--active`) + override defaults form.
- Step 2: editable weight + dims per order; auto-fills from profile defaults.
- Step 3: sequential save loop (never parallel); progress bar + per-row status badges; print-all button opens all label URLs.
- Step 4: sequential send loop; "print before send" gate — send button disabled until at least one label printed.
- Step 4 results: `renderFinalSummary()` — counts sent/error, lists tracking codes, copy-all button.
- Retry button for individual failed rows.

**Known issues:**
- Step 3/4 JS state is memory-only. Browser refresh resets the wizard completely — user must start over even if save completed for some orders.
- Package Shop orders are skipped server-side (`OrdersBulkAction::MAX_ORDERS=20` also applies client-side count).

---

### `assets/js/admin-onboarding.js`
**Handle:** `dexpress-onboarding` | **Deps:** `jquery`
**Localized object:** `dexOb` (ajaxUrl, nonce, strings)

**AJAX calls:**

| Action | Method | Notes |
|--------|--------|-------|
| `dexpress_test_connection` | POST | Step 2: validates credentials before proceeding |
| `dexpress_save_settings` | POST | Step 2: saves API credentials + client ID |
| `dexpress_sync_all` | POST | Step 3: triggers full catalog sync |
| `dexpress_save_settings` | POST | Step 5: saves shipping method enable/disable choices |

**Behavior:**
- 6 panels: Welcome → Credentials → Sync → Sender Location → Shipping Methods → Complete.
- Panel 3 sync order: `['towns','streets','municipalities','centres','dispensers','locations','status_codes','shops']` — note this differs from `SyncCatalogOrder::ALL_SEQUENCE` which starts with municipalities. JS hardcodes its own order independent of PHP.
- Panel 4 (sender location): inline autocomplete using shared `buildObAutocomplete()` factory (same logic as checkout autocomplete but in admin context).
- Phone normalization: strips non-digits + ensures `+381` prefix inline in panel 4.
- Progress bar updates per completed sync step.

---

### `assets/js/checkout.js`
**Handle:** `dexpress-checkout` | **Deps:** `jquery`
**Localized object:** `dexpressCheckout` (ajaxUrl, nonce, IS_BLOCK, fieldSel config)

**AJAX calls:**

| Action | Method | Notes |
|--------|--------|-------|
| `dexpress_search_towns` | POST | Fired on city field input (300ms debounce) |
| `dexpress_search_streets` | POST | Fired on address field input after town selected |

**Behavior:**
- `IS_BLOCK` flag switches between classic (`#shipping_city`, `#billing_city`) and block (`wc-block-components-text-input`) selectors.
- **React compatibility fix**: uses `Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(input, val)` + dispatches synthetic `input` event to avoid React overwriting `.val()` changes — prevents "grad blokira ulicu" (city blocks street) bug where React-managed inputs reject jQuery value assignments.
- Disables street/postcode fields while no town selected; re-enables on town pick.
- Hidden town-id/street-id fields updated on selection; these are WC block checkout additional fields (`dexpress/town-id`, `dexpress/street-id`).
- Postcode is read-only and auto-populated from town data.

---

### `assets/js/package-shop-checkout.js`
**Handle:** `dexpress-package-shop-checkout` | **Deps:** `jquery`, Google Maps API

**AJAX calls:**

| Action | Method | Notes |
|--------|--------|-------|
| `dexpress_get_dispensers` | POST | Fetches all locations for map; max 2000 results |

**Behavior:**
- Initializes Google Maps with all dispensers as markers.
- `@googlemaps/markerclusterer` for cluster management (CDN loaded).
- Left panel: searchable list with diacritic-insensitive search (StringNormalizer equivalent in JS).
- Filter tabs: Sve / Paketomati / Paket Shopovi — filters `location_type`.
- Mobile: drawer layout triggered by `.dexpress-ps-mobile-toggle`.
- Onboarding modal shown on first visit (checks localStorage `dexpress_ps_seen`).
- On selection: writes `DispenserID` + `location_type` to hidden checkout fields.
- `[data-*]` attribute selectors used throughout (not class selectors) for HTML-class decoupling.
- Map info windows and cluster popups built dynamically by JS (not in PHP templates).

---

## 7. CSS / Frontend Architecture

### Admin Design System

**Token source:** `:root` in `assets/css/admin.css` (previously `admin-design-system.css`, now merged/deleted).

| Token Category | Variables |
|---------------|-----------|
| Brand | `--dex-red: #E31E25`, `--dex-red-hover`, `--dex-red-light` |
| Neutral scale | `--dex-gray-50` through `--dex-gray-900` |
| Semantic | `--dex-success`, `--dex-success-light`, `--dex-warning`, `--dex-warning-light`, `--dex-error`, `--dex-error-light`, `--dex-info`, `--dex-info-light` |
| Typography | `--dex-font` (system stack), `--dex-text-xs/sm/base/lg/xl` |
| Spacing | `--dex-space-1` (4px) through `--dex-space-8` (32px) — 4px grid |
| Borders | `--dex-radius-sm`, `--dex-radius`, `--dex-radius-lg`, `--dex-border` |
| Shadows | `--dex-shadow-sm`, `--dex-shadow` |
| Transitions | `--dex-transition` |

**Component inventory (admin.css):**
- Tab navigation: `.dex-tabs`, `.dex-tabs__item`, `.dex-tabs__item.is-active`, `.dex-tabs__panel`
- Settings header: `.dex-settings-header`, `.dex-settings-header__brand`, `.dex-settings-header__logo`, `.dex-settings-header__env`, `--production` / `--test` modifiers
- Notices: `.dex-notice`, `.dex-notice--success/warning/error/info`, `.dex-notice__content`, `.dex-notice__body`
- Buttons: `.dex-btn`, `.dex-btn--primary`, `.dex-btn--secondary`, `.dex-btn--danger`, `.dex-btn--ghost`
- Modal: `.dex-modal`, `.dex-modal__backdrop`, `.dex-modal__dialog`, `.dex-modal__header`, `.dex-modal__body`, `.dex-modal__footer`
- Form elements: `.dex-form-group`, `.dex-label`, `.dex-input`, `.dex-select`, `.dex-textarea`
- Badges: `.dex-badge` with `--delivered/--transit/--problem/--other/--pending` modifiers
- Shipment code range monitor: `.dexpress-usage-meter`, `.dexpress-usage-meter-fill`, `.is-warning`, `.is-exhausted`
- Danger zone: `.dexpress-danger-zone`, `.dexpress-danger-zone-header/body`

**Per-file loading:**
- `admin.css` — loaded on every D-Express admin page
- `admin-dashboard.css` — Dashboard only
- `admin-metabox.css` — Order edit page
- `admin-bulk-shipment.css` — Bulk shipment page
- `admin-package-profiles.css` — Package profiles page
- `admin-onboarding.css` — Onboarding page
- `admin-diagnostics.css` — Diagnostics page (own dark-theme token block, Google Fonts)

**Known issues:**
1. `admin-diagnostics.css` has an independent `:root` token block (`--term-*`, `--dx-*`) separate from the main design system. External network request to Google Fonts on diagnostics page load.
2. `admin-onboarding.css` duplicates `.dexpress-password-wrap` and `.dexpress-pw-toggle` rules already in `admin.css`. Two places to update if toggle style changes.
3. `admin-shipments.css` — deleted from working tree (was empty/stub).
4. `admin-design-system.css` — deleted from working tree (tokens moved to `admin.css`).

### Frontend CSS

- `checkout.css` — Address autocomplete dropdown + disabled/readonly states for classic and block checkout. Block checkout uses `.dx-field-disabled` + `input` nesting.
- `package-shop-checkout.css` — Full modal system (1000+ lines). Contains classes built dynamically by JS at runtime (`.dexpress-package-shop-modal__item`, `.dexpress-ps-select-btn`, `.dexpress-package-shop-infowindow`, cluster popup).

---

## 8. Known Issues & Technical Debt

### Resolved (per `docs/audit-2026-05-09.md` — all 6 fixed)
1. `send_status DEFAULT 'sent'` → fixed to `DEFAULT 'pending_send'` in CREATE TABLE.
2. Missing `DispenserID` for paketomat → now included in `buildApiPayload()` when `location_type=2`.
3. `BuyOutAccount` omitted when COD → now always included when `BuyOut > 0`.
4. Webhook `StatusDate` timezone → now converted Europe/Belgrade → UTC via `parseDtUtcMysql()`.
5. Bulk action limit not enforced client-side → `MAX_ORDERS=20` enforced in both PHP and JS.
6. Email duplicate send → `_dexpress_package_shop_ready_email_sent` meta guard added.

### Active Technical Debt

| # | Issue | Location | Severity |
|---|-------|----------|---------|
| 1 | **`DExpressEndpoints.php` is dead code** | `src/Infrastructure/Api/DExpressEndpoints.php` | Low — delete it |
| 2 | **Bulk wizard JS state loss on refresh** | `admin-bulk-shipment.js` | Medium — wizard resets completely if browser refreshes mid-save; no localStorage persistence |
| 3 | **Checkout.js always-wired instances** | `checkout.js` lines 1-80 | Low — both classic and block instances created regardless of active checkout type; minor DOM overhead |
| 4 | **Zero test coverage** | `tests/` directory empty | High — no PHPUnit tests; all logic relies on manual QA |
| 5 | **Simulation uses legacy `do_action` hook name** | `SimulationService.php` | Low — fires `dexpress_run_simulation_step` which is an AS action, not the canonical `dexpress/*` event namespace |
| 6 | **PaymentsPage has inline styles** | `src/Presentation/Admin/Pages/PaymentsPage.php` | Low — some layout CSS written inline rather than in a `admin-payments.css` file |
| 7 | **Webhook IP: single field, no CIDR** | `tab-webhook.php`, `WebhookController.php` | Low — `X-Forwarded-For` trusted without validation; CIDR not supported; empty = allow all |
| 8 | **Onboarding sync order differs from PHP** | `admin-onboarding.js` vs `SyncCatalogOrder::ALL_SEQUENCE` | Low — JS panel 3 hardcodes `['towns','streets','municipalities',…]`; PHP canonical order starts with `municipalities`; functional mismatch but not broken since each sync is independent |
| 9 | **`admin-diagnostics.css` Google Fonts external request** | `admin-diagnostics.css` | Low — loads Bebas Neue + JetBrains Mono from fonts.googleapis.com; privacy concern for GDPR-conscious setups |
| 10 | **Duplicate CSS: password toggle** | `admin.css` + `admin-onboarding.css` | Low — `.dexpress-password-wrap` and `.dexpress-pw-toggle` defined in both files |

---

## 9. What Is Not Yet Implemented

| Feature | Location / Stub | Notes |
|---------|----------------|-------|
| **License / SaaS system** | No stub exists | Planned: Laravel license server + plugin key validation. Mentioned in CLAUDE.md. |
| **Automatic dimension validation at checkout** | `CheckoutValidator.php` | Only weight/phone/town/street validated. Paketomat dimension constraints (470×440×440mm, 20kg) only enforced at shipment creation time in `CreateShipmentService`, not at checkout. |
| **`viewpayments` automatic sync** | `SyncPaymentsService.php` exists but no cron hook | Manual-only by design: admin enters PaymentReference from bank statement. No "fetch all" endpoint in D-Express API. |
| **Tracking display specific to Package Shop** | `templates/myaccount/dexpress-shipments.php` | Uses same status model as standard delivery. No special "ready for pickup at location X" UI on My Account tracking page. |
| **Tracking timeline on frontend (My Account)** | `MyAccountTrackingTab.php` | Shows shipment list; no status timeline (State C metabox timeline is admin-only). |
| **Block checkout — Package Shop method** | `CheckoutFields.php`, `PackageShopInfoPanel.php` | `cart_checkout_blocks=false` declared — block checkout explicitly disabled. Package Shop modal is not compatible with WC block checkout. |
| **Multi-parcel addShipment payload** | `CreateShipmentService::buildApiPayload()` | `Mass`, `SizeX/Y/Z` always use first package's values; `PackageCount` is sent but D-Express API does not support per-package dimension arrays in a single call. |
| **CIDR / range support for webhook IP allowlist** | `WebhookController::isIpAllowed()` | Single IP only. No subnet mask support. |
| **Automatic log rotation beyond date-based files** | `Logger.php` | Purge runs monthly cron; no size-based rotation. Large traffic can create very large daily log files. |
| **Refund / cancellation flow** | No stub | No API endpoint for cancelling a shipment once `addShipment` is called. No UI for marking a shipment cancelled. |
| **Return shipment creation** | No stub | `ReturnDoc` enum supports POD/documents but no reverse-logistics (`addShipment` variant) exists. |
| **Package Shop — show open hours** | `package-shop-browser-modal.php`, dispenser data | `wp_dexpress_dispensers` table has no `open_hours` column; API does not provide it in current sync. |
| **COD reconciliation automation** | `WpdbPaymentRepository.php` | `upsertByReference` and `deleteMissingForReference` exist; no automatic matching of payment records to WC orders. Manual review only. |
| **Shipment code prefix per environment** | `ShipmentCodeAllocator.php` | Single prefix setting; test environment convention is `TT` prefix but no automatic env-based prefix switching. |
