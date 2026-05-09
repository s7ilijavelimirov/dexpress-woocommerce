# DEV-LOG — D Express WooCommerce Integration

All notable changes to this plugin are documented here.  
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)  
Versioning: DB schema version (`DatabaseInstaller::DB_VERSION`) is the canonical version tracker.  
Plugin header (`dexpress-woocommerce.php`) is locked at **2.0.1** until a public release bump — it does not track internal iterations.

> **For future Claude sessions:** Read `CLAUDE.md` first for architecture overview. This log explains *why* decisions were made and what technical debt exists per version. DB version = `get_option('dexpress_db_version')`. Schema managed by `DatabaseInstaller::maybeUpgrade()` + idempotent ALTER helpers.

---

## [2.4.0] — 2026-05-09

### Fixed
- `src/Presentation/Hooks/PluginActivator.php` + `src/Presentation/Admin/Menu/AdminMenu.php` — **BUG 1: Onboarding redirect never fired on first activation.** Root cause: `maybeRedirectToOnboarding()` only redirected from `index.php` or D-Express home, but WordPress lands on `plugins.php` after activation. Fix: `activate()` now sets a 30-second transient `_dexpress_activation_redirect`; `maybeRedirectToOnboarding()` checks and consumes it on the next `admin_init`, bypassing the page restriction for that one load only.
- `src/Presentation/Admin/Label/PrintLabelController.php` — **BUG 2: Bulk label print used a completely different HTML template.** `renderBulk()` produced a minimal compact layout with different CSS, fonts, and structure than single-label print. Removed entirely. Replaced with `renderMultiple()` which uses `buildLabelData()` + `printSheets()` — the same methods that power single-label rendering — so every label in a bulk job is visually identical to a single-label print. `<div style="page-break-after: always">` inserted between shipments.
- `assets/js/admin-bulk-shipment.js` + `src/Presentation/Admin/Ajax/BulkShipmentController.php` — **BUG 2 (JS side): `buildLabelUrl()` was broken.** It passed the literal string `'bulk'` as a nonce, which always fails `wp_verify_nonce`. Fix: `handleSave()` now returns a `label_url` field containing a server-generated URL with a proper per-shipment nonce. JS uses `r.label_url` directly; the dead `buildLabelUrl()` function removed.
- `src/Presentation/Admin/Pages/BulkShipmentPage.php:406` + `src/Presentation/Admin/Pages/ShipmentsPage.php:98` — **BUG 3: PHP 8.1 deprecation warning — `strip_tags(null)`.** `WC_Order::get_formatted_order_total()` can return `null` on edge cases (e.g. HPOS orders not fully loaded). Added `?? ''` null coalescing to both call sites.
- `src/Presentation/Admin/Pages/ShipmentListTable.php` — **BUG 4: Tablenav top rendered empty/redundant navigation.** `WP_List_Table::display()` renders a top tablenav with bulk-actions area (unused, no `cb` column) + filter form. Since `ShipmentsPage::renderTopBar()` already provides the top UI, the WP tablenav top is dead space. Added `display_tablenav()` override that returns early for `'top'`. Status filter form moved to `'bottom'` tablenav by changing `extra_tablenav()` guard from `!== 'top'` to `!== 'bottom'`.
- `src/Presentation/Admin/Pages/OnboardingPage.php` + `assets/js/admin-onboarding.js` — **BUG 5: CClientID not saved through onboarding wizard.** Step 2 had no input for `api.client_id`, and `handleComplete()` only saved username + password. Without `CClientID`, `CreateShipmentService` throws `'ID klijenta (CClientID) nije konfigurisan'` on first shipment creation attempt — silent failure from the admin's perspective. Added `api_client_id` field to Step 2 form; `handleComplete()` now saves all three credentials; JS sends `client_id` to AJAX handler. Step 6 now shows an inline warning notice if `api.client_id` is still empty at completion time, with a back-link to Step 2.

### Removed
- `src/Presentation/Admin/Label/PrintLabelController.php` — `renderBulk()` private method (~190 lines). Dead code: generated a completely different minimal HTML document with different CSS classes, different label structure, different barcode dimensions, and no Package Shop support. Superseded by `renderMultiple()` which calls the shared `printSheets()`.
- `assets/js/admin-bulk-shipment.js` — `buildLabelUrl()` function. Built invalid URLs with literal `'bulk'` nonce marker; comment inside the function acknowledged it was a broken workaround. Server now provides correct URLs via `label_url` in AJAX response.

### Architecture Notes
- `PrintLabelController` now follows a cleaner separation: `buildLabelData(int $id, int $perSheet): ?array` owns all DB/business logic; `printSheets(array $d): void` owns the HTML template; `printLabelCss(): void` owns the shared stylesheet; `outputHtml()` and `renderMultiple()` each compose these pieces. Adding a third render variant (e.g. thermal label format) now requires only a new composer method, not duplicating data-gathering or CSS.
- `BulkShipmentController::handleSave()` now returns `label_url` alongside `shipment_id` and `tracking_code`. This is the correct pattern: nonces cannot be generated client-side, so the server must produce authenticated URLs. All future AJAX responses that lead to label printing should follow this pattern.
- Onboarding `state.credentials` object in `admin-onboarding.js` now includes `client_id`. If future API fields are added to the wizard, extend this object and pass through `handleComplete()`.

### Known Issues / Tech Debt
- `renderPanel6()` Client ID warning is a PHP-time check (`renderPanel6()` runs at page load). If admin enters the Client ID during the same wizard session but doesn't re-trigger a PHP render, the warning will still show even though JS state has the value. Low priority — `handleComplete()` correctly saves whatever JS passes, so the warning is conservative (false positive) not dangerous (false negative).
- Onboarding `state.credentials.client_id` is only populated from the Step 2 input at test-connection time. If admin skips test-connection and goes directly to Step 6, `client_id` is `''` in JS state but may have been filled in the input. A pre-complete read of `#dex-ob-api-client-id` value would fix this; deferred as low impact since the warning in Step 6 prompts them back.

---

## [2.3.1] — 2026-05-09

### Fixed (Code Audit — `docs/audit-2026-05-09.md`)
- `src/Application/Shipment/CreateShipmentService.php` — Removed dead `do_action('dexpress_shipment_created', ...)` dispatch. Only `dexpress/shipment.created` has registered listeners (`EmailNotificationSubscriber`, `SimulationService`). The underscore-style hook was a legacy naming artifact with no subscribers.
- `src/Application/Shipment/ShipmentStatusOrderNoteFormatter.php` + `src/Application/Webhook/ShipmentStatusIngestionService.php` — Removed unused `$_bucket` parameter from `ShipmentStatusOrderNoteFormatter::format()`. The parameter accepted a `StatusEmailBucket` but the method body never referenced it. Call site updated to drop the third argument.
- `src/Domain/Shipment/StatusEmailBucket.php` — Removed unused `label()` method. All other domain enums (`DeliveryType`, `PaymentType`, `PaymentBy`, `ReturnDoc`) have `label()` actively used in admin UI. `StatusEmailBucket::label()` was dead — admin status display comes from `StatusCodeRepository::resolveOfficialShipmentStatusLabel()` against the DB.
- `src/Application/Shipment/CreateShipmentService.php` — Removed 4-line `// PHASE 2B COMPLETE` milestone comment block. Described work (Package Shop dispenser flow, address validation, checkout server-side validation) is fully shipped.
- `assets/js/package-shop-checkout.js` — Removed `// PHASE 2B HOOKS — ready for implementation` checklist comment. All described features (dispenser selection, hidden field population, pre-submit validation) are implemented.
- `src/Application/Email/ShipmentEmailRenderContextFactory.php` — Added docblock explaining `EARLY_PROBLEM_SIDS` constant: controls whether a problem status is attributed to the pickup step (sID before courier collection) or the transit step (sID after collection) in shipment notification email progress indicators.

### Architecture Notes
- Audit baseline: codebase is healthy. All 16 DB tables actively queried, all service provider bindings used, no orphaned CSS/JS. See full report in `docs/audit-2026-05-09.md`.

---

## [2.3.0] — 2026-04-28 (pre-git, reconstructed)

### Added
- `src/Presentation/Admin/Pages/OnboardingPage.php` + `assets/js/admin-onboarding.js` + `assets/css/admin-onboarding.css` — 6-step onboarding wizard. Shown automatically on first plugin activation via `dexpress_onboarding_complete` option. Steps: Welcome → API credentials → Catalog sync → Sender location → Shipping zone → Complete. Dismissible admin notice persists until onboarding finishes (per-user meta `_dexpress_onboarding_notice_dismissed`). AJAX handlers: `dexpress_onboarding_complete`, `dexpress_onboarding_create_zone`, `dexpress_dismiss_onboarding_notice`, `dexpress_onboarding_log`.
- `src/Presentation/Admin/Pages/BulkShipmentPage.php` + `assets/js/admin-bulk-shipment.js` + `assets/css/admin-bulk-shipment.css` — 3-step bulk shipment wizard (global defaults → per-order review → sequential save + send). Hidden submenu; accessed only via redirect from `OrdersBulkAction`. JS drives all state between steps (no intermediate DB storage).
- `src/Presentation/Admin/Ajax/BulkShipmentController.php` — AJAX handler for bulk operations. `handleSave()` calls `CreateShipmentService::save()` (allocates TT code, status=`pending_send`). `handleSend()` calls `CreateShipmentService::send()`. Same service layer as single-shipment metabox — no duplication.
- `src/Presentation/Admin/Ajax/PackageProfileController.php` + `src/Presentation/Admin/Pages/PackageProfilesPage.php` + `assets/js/admin-package-profiles.js` + `assets/css/admin-package-profiles.css` — Package profile management (save/delete/set-default). Profiles provide weight/dimension/content presets for bulk and single shipment creation. Stored in `wp_dexpress_package_profiles` table (added to schema). Appear as quick-apply cards in bulk wizard Step 1 and as a reference bar on shipments page.
- `src/Presentation/Admin/Pages/PaymentsPage.php` + `src/Presentation/Admin/Pages/PaymentListTable.php` + `src/Infrastructure/Persistence/WpdbPaymentRepository.php` + `src/Application/Sync/SyncPaymentsService.php` — COD reconciliation page (Otkupnine). Admins enter `PaymentReference` from bank statement; API call fetches payment batch for that reference. No automatic sync — by design, D-Express `viewpayments` endpoint requires a bank reference number as input, there is no "fetch all" endpoint.
- `src/Presentation/Admin/Hooks/OrdersBulkAction.php` — Adds "Kreiraj D-Express pošiljke" bulk action to WooCommerce orders list. Validates each order: skips Package Shop orders (must be created individually), skips orders without D-Express shipping method, skips orders that already have a shipment. Redirects to `BulkShipmentPage` with valid order IDs + nonce.
- `src/Presentation/Admin/Hooks/OrdersListDeliveryStatusColumn.php` — Adds delivery status badge column to WooCommerce orders list table. Shows `StatusEmailBucket` as color-coded badge for quick triage.
- `src/Presentation/Admin/Pages/DashboardPage.php` — Dashboard redesign. Shows pending orders count, recent shipments, sync status indicators.
- `assets/css/admin-design-system.css` — CSS custom property design token layer shared across all admin pages. Variables: `--dex-primary`, `--dex-gray-*`, `--dex-radius-*`. Loaded before page-specific stylesheets. This was introduced because the admin CSS had grown to ~500 lines of hardcoded colors; centralizing tokens makes theming and brand color changes a one-file edit.
- `assets/css/admin-metabox.css` — Extracted metabox-specific styles from monolithic `admin.css`. The three-state metabox wizard (State A/B/C) needed scoped styles that were polluting the global admin sheet.
- `src/Infrastructure/Persistence/WpdbPackageProfileRepository.php` — Repository for package profiles table.
- `src/Application/Shipment/ShipmentCodeAllocator.php` — Extracted TT code allocation logic from `CreateShipmentService` into a dedicated class. Test environment uses prefix `TT` + range 1–99; production prefix assigned by D-Express.

### Changed
- `src/Presentation/Admin/Pages/ShipmentsPage.php` — Added "Pending Orders" section above the shipment table. Shows `processing` orders with D-Express shipping but no shipment, with per-order checkboxes and a bulk-create button. `renderTopBar()` shows package profile chips for quick reference.
- `assets/css/admin.css` — Refactored to import design tokens from `admin-design-system.css`. Removed inline color values. Page-specific rules moved to per-page stylesheets.

### Architecture Notes
- Bulk shipment state is intentionally JS-only between steps — no intermediate rows written to DB, no session storage. This avoids orphaned "in-progress" bulk records. If the browser is closed mid-wizard, nothing is persisted until `handleSave()` AJAX calls succeed.
- Package profiles live in their own table rather than as serialized options because: (1) profiles are user-managed entities, not configuration; (2) the bulk wizard needs to JOIN/filter them; (3) they need individual delete without touching other settings.
- `OrdersBulkAction` redirect uses `_wpnonce` on the URL, validated on `BulkShipmentPage::render()`. The nonce action is `dexpress_bulk_init`. Print nonces use a separate `dexpress_bulk_print` action to separate read (print) from write (create) authorization.

### Known Issues / Tech Debt
- `BulkShipmentPage` does not support Package Shop orders — they are silently skipped by `OrdersBulkAction`. This is by design (Package Shop requires individual field entry) but the skip count shown in the notice is not always visible if the admin navigates away.
- Package profile bulk-apply in Step 1 does not propagate note/reference fields — only weight, dimensions, and content. Intentional for now; those fields are order-specific.

---

## [2.2.0] — 2026-03-20 (pre-git, reconstructed)

### Added
- `src/Presentation/Frontend/Shipping/DexpressPackageShopShippingMethod.php` + `assets/css/admin-package-shop-shipping.css` — Second WooCommerce shipping method (`dexpress_package_shop`) for Package Shop / Paketomat pickup. Includes Google Maps API key field in WC shipping settings.
- `src/Presentation/Admin/Metabox/PackageShopInfoMetabox.php` — Read-only admin metabox on order edit page showing selected pickup location details (name, address, type badge). Data sourced from `_dexpress_package_shop_location_*` order meta keys.
- `src/Presentation/Frontend/Checkout/PackageShopInfoPanel.php` — Frontend order-received / my-account panel showing selected pickup location to customer post-purchase.
- `src/Presentation/Email/EmailPackageShopReadyForPickup.php` — Custom WooCommerce email `dexpress_package_shop_ready_for_pickup` ("Paket spreman za preuzimanje"). Auto-triggered on status IDs 830 (paketomat) and 842 (paket shop). Duplicate guard via `_dexpress_package_shop_ready_email_sent` order meta prevents double-send on webhook replay.
- `src/Application/Shipment/CreateShipmentService.php` — `validatePackageShopShipmentConstraints()` method enforcing paketomat-specific limits: max 1 package, BuyOut < 20,000,000 para (200k RSD), recipient phone must be mobile (06x prefix), `ReturnDoc` must be 0, weight < 20kg, dimensions < 470×440×440mm. These are hard API constraints from D-Express documentation — violations return HTTP 400.
- Label printing for Package Shop: recipient block shows pickup location as primary address (mirrors what goes into `R*` API fields); customer contact shown as secondary line labeled "Kontakt primaoca". `📦 PAKETOMAT` / `📦 PAKET SHOP` badge rendered inline.

### Changed
- `src/Application/Shipment/CreateShipmentService.php` — `buildPayload()` now branches on `location_type` meta: type 2 (Paketomat) includes `DispenserID` field; types 1/3 (Paket Shop staffed) omit it. `R*` payload fields (recipient address) are set to pickup location address, not customer billing address. `RCName`/`RCPhone` carry customer contact for D-Express SMS notifications.
- `src/Application/Shipment/OrderRecipientResolver.php` — Extended to handle Package Shop orders: returns pickup location data for `R*` fields when `_dexpress_package_shop_location_id` meta is present.
- Schema: `wp_dexpress_shipments` — `send_status` column added (`pending_send` / `sent`, DEFAULT `pending_send`). Critical: DEFAULT must be `pending_send`, not `sent` — a shipment is not sent until `CreateShipmentService::send()` is explicitly called.
- `src/Presentation/Admin/Metabox/OrderShipmentMetabox.php` — Implemented three-state machine driven by `send_status`: **State A** (no shipment) → wizard; **State B** (`pending_send`) → "awaiting send" with print + send actions; **State C** (`sent`) → tracking display.

### Architecture Notes
- The two-step save/send flow (`save()` then `send()`) is a business requirement from D-Express documentation: *"Call addShipment after shipment is packaged, properly labeled and ready for pickup."* `execute()` remains as a compatibility wrapper that calls `save()` then `send()` in sequence for callers that don't need the intermediate state.
- `wp_dexpress_locations` is the single source for the location browser modal — not `wp_dexpress_dispensers`. Dispensers are a subset (paketomat only, 294 rows) of locations (all pickup points, ~367 rows). The browser shows all; `location_type` meta distinguishes paketomat from staffed shop at shipment-creation time.
- `BuyOutAccount` is treated as required when `BuyOut > 0` despite D-Express docs marking it optional. API returns HTTP 400 if BuyOut > 0 and the field is absent. This was discovered in testing and is hardcoded as a known quirk.

### Known Issues / Tech Debt
- Paketomat dimension validation (470×440×440mm) is enforced at shipment creation time in `validatePackageShopShipmentConstraints()` but NOT at checkout time. A customer can select a paketomat and place an order with oversized items; the failure surfaces only when the admin tries to create the shipment. Checkout-time validation was deferred as it requires product dimension data to be reliably set.

---

## [2.1.0] — 2026-03-05 (pre-git, reconstructed — Phase 2A)

### Added
- `src/Presentation/Frontend/Checkout/PackageShopCustomerFlow.php` — Orchestrates Package Shop checkout flow: injects modal trigger button, manages hidden order meta fields (`dexpress_checkout_dispenser_id`, `dexpress_checkout_location_type`), validates selection on checkout submit.
- `src/Presentation/Frontend/Ajax/PackageShopDispenserController.php` — AJAX endpoint returning paginated/filtered dispenser and location data for the modal browser. Backed by `DispenserBrowserRepository`.
- `src/Infrastructure/Persistence/DispenserBrowserRepository.php` — Optimized read-only repository for the checkout modal. Returns denormalized location rows (name, address, city, type, lat/lon) in one query. Separate from the sync-write `DispenserRepository` to keep browser queries fast and the sync path clean.
- `assets/js/package-shop-checkout.js` (~1800 lines) + `assets/css/package-shop-checkout.css` — Frontend Package Shop modal: Google Maps integration with `@googlemaps/markerclusterer`, left-panel list with diacritic-insensitive search (š/đ/ž/č/ć via `StringNormalizer`), filter tabs (Sve / Paketomati / Paket Shopovi), mobile drawer/hamburger layout, marker click → panel scroll sync.
- `src/Infrastructure/StringNormalizer.php` — PHP + JS diacritic normalization utility. Serbian Latin diacritics (š→s, đ→d, ž→z, č→c, ć→c) applied client-side for search and server-side for address matching.

### Changed
- `src/Presentation/Frontend/Checkout/CheckoutFields.php` — Added town/street autocomplete fields powered by `wp_dexpress_towns` / `wp_dexpress_streets` šifarnici. Standard WooCommerce address fields remain visible for non-D-Express shipping methods; D-Express fields shown conditionally via JS.
- `src/Presentation/Frontend/Checkout/CheckoutValidator.php` — Server-side validation of D-Express address fields (town ID, street ID, phone format) triggered on `woocommerce_checkout_process`. Validates Package Shop selection is present when `dexpress_package_shop` method chosen.

### Architecture Notes
- Google Maps always loads when `dexpress_package_shop` method is available at checkout, even without a configured API key — a warning banner appears instead of blocking the modal. This was a deliberate UX decision: the modal degrades gracefully (no map, just the list panel) rather than breaking entirely.
- `DispenserBrowserRepository` is read-only by design. Do not add write operations to it. The sync write path uses the separate `DispenserRepository` and `LocationRepository` in `Infrastructure/Persistence/Sync/`.

---

## [2.0.0] — 2026-02-10 (pre-git, reconstructed — initial DDD architecture)

### Added
- Full plugin foundation using layered DDD architecture (Domain / Application / Infrastructure / Presentation) with PSR-11 IoC container (`src/Container/Container.php`) and 11 service providers.
- **Domain layer**: `Shipment`, `Package`, `PackageCode`, `PhoneNumber`, `StreetNumber`, `Money`, `Grams` value objects; `DeliveryType`, `PaymentType`, `PaymentBy`, `ReturnDoc`, `StatusEmailBucket` enums; `ShipmentRepository` interface; `ShipmentCreated`, `StatusUpdated` domain events.
- **Application layer**: `CreateShipmentService` (full shipment lifecycle), `ShipmentStatusIngestionService` (webhook processing), `EmailNotificationSubscriber` (event-driven email dispatch), `SimulationService` (test mode), all 8 `Sync*Service` classes (towns, streets, municipalities, centres, shops, dispensers, locations, status codes).
- **Infrastructure layer**: `DExpressApiClient` (HTTP Basic auth, AES-encrypted password via `EncryptedString`, endpoints for `addShipment`, `viewShipments`, all šifarnici); `Code128` barcode SVG generator; `WpCronScheduler` (daily/monthly/quarterly/semi-annual jobs); `Logger` (WC log channel); `OptionsRepository` (dot-notation settings, single `dexpress_settings` WP option row).
- **Presentation layer**: `OrderShipmentMetabox` (single-order shipment creation wizard); `PrintLabelController` (standalone label print, no WP chrome; layouts: 1-up, 2-up, 4-up A4); `ShipmentsPage` + `ShipmentListTable`; `SettingsPage` with 8 tabs (API, Webhook, Sender Locations, Checkout, Email, Logging, Šifarnici, Simulation); `DiagnosticsPage`; REST webhook endpoint (`PUT|POST /wp-json/dexpress/v1/notify`); `MyAccountTrackingTab` (customer tracking in WC My Account).
- **Database**: 16 custom tables managed by `DatabaseInstaller` (`dbDelta` + manual ALTER helpers). Schema version stored in `dexpress_db_version` option. Tables: shipments, packages, package_items, shipment_items, shipment_statuses, sender_locations, towns, streets, municipalities, centres, shops, dispensers, locations, status_codes, payments, webhook_logs.
- **Cron**: Daily at 02:00 (dispensers + locations sync), monthly at 03:00 (municipalities, centres, towns, streets + log purge), quarterly at 04:00 (shops), semi-annual at 04:00 (status codes). Payments sync is manual-only by design.
- **Webhook**: IP allowlist (single IP, empty = allow all), async processing via Action Scheduler, full log to `wp_dexpress_webhook_logs`.
- **Shipping**: `DexpressShippingMethod` (standard door-to-door, method ID `dexpress`).
- `src/Presentation/Frontend/Checkout/CheckoutFields.php` — Standard D-Express address fields (phone, town, street, street number, address description) for door-to-door delivery.

### Architecture Notes
- API password stored as AES-256 encrypted string (`EncryptedString`). Encryption key derived from `AUTH_KEY` WordPress constant. Raw password never stored in DB.
- `addShipment` API endpoint returns plain text `"OK"` (production) or `"TEST"` (test environment), not JSON. `DExpressApiClient` handles this explicitly — do not assume JSON response.
- Webhook IP allowlist intentionally supports only a single IP (no CIDR). D-Express notifies from one IP. If this changes, `WebhookController::isAllowedIp()` needs updating.
- All domain events use WordPress `do_action` / `add_action` as the event bus. The action name convention is `dexpress/{noun}.{verb}` (e.g. `dexpress/shipment.created`). The older underscore-style hook `dexpress_shipment_created` was removed in v2.3.1 audit.
- `EncryptedString::fromString()` gracefully returns an empty `EncryptedString` for unrecognized/legacy formats. This makes migration from unencrypted credentials non-destructive: the field appears blank to the admin and they can re-enter.

### Known Issues / Tech Debt
- License/SaaS validation system is planned (Laravel license server + plugin key) but not implemented. No license check exists anywhere in the codebase.
- `viewpayments` API sync is manual-only. D-Express requires a `PaymentReference` from the bank statement as input — there is no "fetch all" endpoint. Admin must obtain the reference from their bank and enter it manually.
- Test environment TT codes use prefix `TT` + range 1–99 (99 codes). Production TT prefix is assigned by D-Express. The allocation counter is stored in a WP option; if the option is lost, code allocation restarts from 1 and may collide with previously-issued codes.

---

## Unreleased

### Known Issues
- `admin-onboarding.js`: `state.credentials.client_id` populated only on test-connection click. If admin fills the field but skips test-connection, `client_id` is `''` in JS state when completing the wizard. Server-side save is correct as long as they click "Testiraj konekciju" first.
- No automated test suite exists (`tests/` directory is empty). All testing has been manual against a local WordPress + WooCommerce install. PHPUnit is declared as a dev dependency in `composer.json`.
- `MyAccountTrackingTab` uses the same status display model as standard orders — there is no Package Shop-specific tracking view (e.g. "ready for pickup at location X"). Tracking display for Package Shop orders is functionally correct but not contextually tailored.
