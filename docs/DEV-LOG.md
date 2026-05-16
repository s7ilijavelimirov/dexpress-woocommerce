# DEV-LOG — D Express WooCommerce Integration

All notable changes to this plugin are documented here.  
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)  
Versioning: DB schema version (`DatabaseInstaller::DB_VERSION`) is the canonical version tracker.  
Plugin header (`dexpress-woocommerce.php`) is locked at **2.0.1** until a public release bump — it does not track internal iterations.

> **For future Claude sessions:** Read `CLAUDE.md` first for architecture overview. This log explains *why* decisions were made and what technical debt exists per version. DB version = `get_option('dexpress_db_version')`. Schema managed by `DatabaseInstaller::maybeUpgrade()` + idempotent ALTER helpers.

---

## [2.6.0] — 2026-05-12

### Fixed
- `src/Presentation/Admin/Pages/OnboardingPage.php` — Removed stray `WC()->shipping()` call from `handleCreateZone()`. This call internally invokes `WC_Shipping::load()` which does not exist in WooCommerce 8.x+ and caused a fatal `Call to undefined method` that was logged on every zone-creation AJAX request. The call was vestigial: `attachMethodsToZone()` already uses direct `$wpdb` queries, so no WC shipping registry warm-up is needed.

- `assets/js/admin-bulk-shipment.js` — **Weight calculation persistence**: Step 1 weight field was required (`validateStep1` blocked proceeding if empty), making the global weight always filled and always winning in `initOrderState`. Removed weight from Step 1 validation — it is now an optional batch override. `initOrderState` logic was already correct: per-order `calc_weight_kg` (500g base + WC product weights) is used as primary; global weight overrides only when explicitly entered. Step 2 per-row validation still enforces `weight_kg > 0`, which always passes since calc weight is at least 0.500 kg.

- `assets/js/admin-bulk-shipment.js` — **Step 2 table layout**: Reduced from 8 columns to 6 by merging Kupac and Iznos into the Narudžbina cell (`dex-bulk-order-meta` row with customer name + total amount inline). Calc weight hint simplified to just show the total kg (removed breakdown since it clutters the input area).

- `assets/js/admin-bulk-shipment.js` — **Error row visibility in Step 3 results**: Error `<tr>` elements now carry `class="dex-row-has-error"` for visual differentiation. Corresponding CSS rule added to `admin-bulk-shipment.css`.

- `assets/js/admin-bulk-shipment.js` — **Print-before-send enforcement**: "Pošalji sve u D-Express" button is now hidden after save phase completes (per CLAUDE.md: *"Call this method after shipment is packaged, properly labeled and ready for pickup"*). The button is revealed only after admin clicks "Štampaj sve nalepnice" (print-all), enforcing the correct workflow: save → print → physically package → send to API.

- `src/Presentation/Admin/Pages/BulkShipmentPage.php` — Removed asterisk from "Masa (kg)" field label in Step 1 since weight is no longer required at that step.

### Added
- `assets/css/admin-bulk-shipment.css` — `.dex-bulk-order-meta`, `.dex-bulk-customer`, `.dex-bulk-total` for merged order cell layout; `.dex-row-has-error` + `.dex-row-has-error td` for error row highlighting in Step 3 results table.

### Architecture Notes
- The bulk wizard now fully respects the two-step shipment flow: `save()` creates the DB record and returns a label URL; the admin must open labels (print-all click) before the send button unlocks. This matches the state machine described in CLAUDE.md.
- Step 1 weight field is now "batch override" semantics: leave blank = each order uses its own calculated weight; fill in = override all orders with a single value. This is a UX improvement over the previous "required" model, which defeated the purpose of per-product weight data.

### Revision — 2026-05-12 (UI + 4-step flow)

#### Root causes fixed
- **CSS specificity collision on inputs**: `.dex-bulk-orders-table input[type="number"]` has specificity (0,2,1) which outranks `.dex-bulk-orders-table .dex-dim-input` at (0,2,0) — the generic `width: 100%; min-width: 60px` was overriding the 52px dim-input width. Fixed by changing the generic rule to `box-sizing: border-box` only, and using `input[type="number"].dex-dim-input` (0,3,1) for the dim-specific override.
- **`vertical-align: middle` on tall order cell**: Cell with 4 stacked sub-elements (link, meta, address, badges) forced all other cells to center-align their inputs, causing visual collision. Fixed by changing `td` to `vertical-align: top`.
- **`white-space: nowrap` on calc-hint**: Prevented wrapping of "Kalkulacija: X.XXX kg" text, forcing the weight column to expand and compressing all other columns. Removed.
- **Dims `×` naked text nodes**: Three inputs separated by raw `×` characters with no wrapper caused inline layout instability. Wrapped in `<div class="dex-bulk-dims-cell">` with `display: flex; align-items: center; gap: 4px`.
- **Send-phase status selector**: `$('[data-order-id="..."]').closest('tr')` only finds retry buttons (not first-send rows). Fixed by adding `data-order-id` to every `<tr>` in the results table and selecting with `tr[data-order-id="..."]`.

#### 4-step wizard refactor
- Stepper extended to 4 steps: Podešavanja → Pregled → Štampa → Slanje
- Step 3 is now "Kreiranje i štampa nalepnica": save phase progress + results table + "Štampaj sve nalepnice" + disabled "Nastavi na slanje →" button (unlocks only after print-all click)
- Step 4 is new "Slanje u D-Express": warning notice + results table re-rendered into `#dex-bulk-send-results-wrap` + "Pošalji sve" button + final summary card in `#dex-bulk-final-summary-wrap`
- `renderResultsTable(target, showPrintActions)` now accepts a container selector and a flag; the same function renders both Step 3 save view and Step 4 send view
- Send-phase inline status updates target `#dex-bulk-send-results-wrap tr[data-order-id="..."]` (correct context)
- `renderFinalSummary()` appends into `#dex-bulk-final-summary-wrap` (Step 4) instead of `#dex-bulk-results-wrap`
- `savedShipmentIds` is now a module-level variable populated by `renderResultsTable`, available to both print and send phases

---

## [2.5.0] — 2026-05-10

### Added
- `assets/css/admin-shipments.css` — External stylesheet for the shipments admin page. All previously inline `<style>` rules from `ShipmentsPage::renderPendingOrders()` migrated here and expanded with new column and badge rules. Enqueued via `AdminMenu::enqueueAdminAssets()` when `page=dexpress-shipments`.

### Changed
- `src/Presentation/Admin/Pages/ShipmentsPage.php` — Major expansion of `getPendingOrders()` and `renderPendingOrders()`:
  - **Constructor**: added `WpdbPackageProfileRepository` dependency for the profile dropdown.
  - **`getPendingOrders()`**: added `phone` (billing phone), `shipping_address` (D-Express šifarnik meta `_shipping_street_name` + `_shipping_street_number`, fallback to `get_formatted_shipping_address()`), `payment_method`, `payment_method_title`, `is_paid`. Edit URL is now HPOS-aware via `\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()`.
  - **`renderPendingOrders()`**: new columns — Narudžbina (linked order # + "Pogledaj" link), Telefon, Adresa dostave (truncated with title tooltip), Plaćanje (badge: "Pouzećem" in warning color for COD, "Plaćeno" in success color for paid, neutral for other), Tip dostave (pill badge: "Paketomat / Paket Shop" or "Obična dostava"). Added package profile `<select>` dropdown in header (pre-selects default profile); if no profiles exist, shows a notice with a link to the profiles page. Profile ID is passed as `profile_id` query param when the bulk button is clicked.
  - All inline `<style>` removed — styles moved to `admin-shipments.css`.
- `src/Container/Providers/AdminServiceProvider.php` — `ShipmentsPage` singleton factory updated to inject `WpdbPackageProfileRepository`.
- `src/Presentation/Admin/Menu/AdminMenu.php` — Added `dexpress-shipments` conditional in `enqueueAdminAssets()` to load `admin-shipments.css`.
- `src/Presentation/Admin/Pages/BulkShipmentPage.php`:
  - **Fix — PHP 8.1 deprecation `strip_tags(null)`**: Hidden submenu pages (parent slug `''`) are not always resolved by WordPress's `get_admin_page_title()`, which then passes `null` to PHP's `strip_tags()`. Fixed by setting `$GLOBALS['title']` explicitly at the top of `render()`. This is the standard WordPress pattern for hidden submenus.
  - **Back button**: href changed from WooCommerce orders list URL to `admin_url('admin.php?page=dexpress-shipments')`, label changed from "← Nazad na narudžbine" to "← Nazad na pošiljke".
- `assets/css/admin-package-profiles.css` — Added new rules for Task 4 of the package profiles redesign: `.dex-pp-page-subtitle`, `.dex-pp-dim-group`, `.dex-pp-dim-label`, `.dex-pp-dim-hint`, `.dex-pp-dims-sep`, `.dex-pp-weight-g`, `.dex-pp-form-note`, `.dex-pp-tab-badge`. Updated `.dex-pp-dims` from `align-items: center` to `align-items: flex-end`.
- `src/Presentation/Admin/Pages/PackageProfilesPage.php` — Full redesign completed across two prior sessions:
  - Card grid layout replacing `WP_List_Table` (auto-fill, minmax 220px).
  - Empty state with SVG illustration and CTA button.
  - Two-column layout (main + 350px sidebar) with responsive breakpoint.
  - Centered modal overlay replacing inline slide-down form; `form="dex-pp-form"` associates submit button in footer to the `<form>` in body.
  - Modal form: full Serbian labels ("Masa prazne kutije (kg)", "Dimenzije prazne kutije (cm)"), labeled dimension sub-fields (Dužina/Širina/Visina), gram converter display, form note.
  - Sidebar: two-tab rules panel (Obična pošiljka / Paketomat) with "loker" badge on Paketomat tab, accurate D-Express constraint data, girth formula `2×(Visina+Širina)+Dužina ≤ 360 cm`.
  - Page subtitle paragraph after `<hr class="wp-header-end" />`.
- `assets/js/admin-package-profiles.js` — Updated `buildCard()` and `refreshTable()` to match card-based PHP render output; `openForm()`/`closeForm()` now toggle `.is-open` on `#dex-pp-modal`; edit handler updated from `$(this).closest('tr')` to `$(this).closest('[data-profile]')`.

### Architecture Notes
- `ShipmentsPage` now owns the profile selector UI that feeds into `BulkShipmentPage`. The profile_id query param is read by `BulkShipmentPage` but not yet used to pre-select a profile card in Step 1 — that wire-up is a natural next step if needed.
- HPOS-aware URL pattern in `ShipmentsPage` mirrors `ShipmentListTable::orderEditUrl()`. Both use `\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()` — the canonical WooCommerce utility method (simpler than the `CustomOrdersTableController` approach used in `BulkShipmentPage`).

### Known Issues / Tech Debt
- `BulkShipmentPage` does not yet consume `profile_id` from the URL to pre-select a profile card. The URL param is passed from `ShipmentsPage` but ignored on the bulk page. A JS one-liner on page load (`if (urlParam('profile_id')) { applyProfile(id) }`) would complete the wire-up.
- `ShipmentsPage::getPendingOrders()` shipping address falls back to `get_formatted_shipping_address()` for orders without D-Express šifarnik meta. This produces a multi-line HTML string that is collapsed to a single line via regex. For very long addresses, this may truncate awkwardly at the column width — handled with CSS `text-overflow: ellipsis` and full address in the `title` attribute.

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

## [2.7.0] — 2026-05-15

### Added
- **Checkout gate** (`src/Presentation/Frontend/Shipping/DexpressShippingGate.php`) — New class with static `isActive()` method. Direct `wpdb` query on `woocommerce_shipping_zone_methods`; no caching, no WC class dependency, safe at `plugins_loaded`. If no D-Express shipping method is enabled in any zone, the entire checkout layer (fields, validators, Package Shop modal, tracking tab) is skipped entirely — default WooCommerce checkout is untouched. Works for both classic shortcode and block checkout.
- **Bulk wizard preview step** (`assets/js/admin-bulk-shipment.js`) — Step 2 "Pregled" added between order selection and AJAX creation. On "Pregledaj →" click, JS snapshots all order state into a frozen array, hides config+orders card, and renders a preview table (Narudžbina, Kupac, Masa, Dimenzije, Sadržaj, Predaja, Plaćanje) with delivery/payment badges. "← Izmeni" returns to config without data loss; "Pakuj i štampaj" proceeds with creation. Wizard is now 4 steps: Odabir → Pregled → Pakuj & Štampaj → Pošalji.
- **Pending shipments card** (`src/Presentation/Admin/Pages/ShipmentsPage.php`) — Server-side card rendered on every Pošiljke page load when `pending_send` shipments exist. HPOS-aware JOIN on `wc_orders`/`wp_posts` filtered to `wc-processing` and `wc-on-hold` orders only. Shows TT tracking code (from `wp_dexpress_packages.code` subquery), customer name, individual label links, bulk print URL. "Pošalji D-Expressu (N)" button + progress bar drives sequential AJAX send. State is persistent across sessions and page navigation — survives without relying on JS in-memory state.
- **`default_self_drop_off` setting** (`templates/admin/tab-api.php`, `src/Presentation/Admin/Handlers/SettingsSaveHandler.php`) — New checkbox on the Shipment settings tab to set the default delivery mode (kurir dolazi vs. sam donosim). Stored as `shipment.default_self_drop_off` in `dexpress_settings`. `BulkShipmentController` and `ShipmentsPage` read from this option; JS `self_drop_off` POST param overrides when explicitly set.
- **Page header on Pošiljke** (`src/Presentation/Admin/Pages/ShipmentsPage.php`) — `dex-page-header--shipments` variant: logo | title+subtitle | pending-order count badge. CSS defined in `assets/css/admin.css`.

### Changed
- **`BulkShipmentController` settings-driven defaults** — `delivery_type`, `payment_type`, `return_doc` now read from `OptionsRepository` instead of POST body. The bulk AJAX caller no longer needs to pass these values; they come from the admin's saved settings. `self_drop_off` falls back to `shipment.default_self_drop_off` if not present in POST. Removed the `weight_kg = 0` validation guard (calculated weight from product data is always used if no explicit override).
- **Label toolbar redesign** (`src/Presentation/Admin/Label/PrintLabelController.php`) — Added D-Express logo to toolbar left. Buttons reorganised into two groups: brand left, actions right. 1-up layout removed (old `?layout=1` URLs redirect to 2-up). Remaining layouts: 2-up (default) and 4-up. `urlLayout1` parameter removed from `printSheets()` signature.

### Fixed
- **"Kod pošiljke" showing order reference** (`ShipmentsPage::getPendingShipments()`) — Previous query aliased `reference_id` (order number e.g. "988") as `tracking_code`. Now uses a correlated subquery `(SELECT MIN(pk.code) FROM dexpress_packages pk WHERE pk.shipment_id = s.id)` to fetch the real TT code (e.g. "TT0000000014").
- **Onboarding client_id UX** (`assets/js/admin-onboarding.js`) — Auto-init condition changed from `saved.username && saved.password && snap.clientIdInDb` to `saved.username && saved.password`. Admins returning to the wizard with credentials already saved (but no Client ID yet) can now enter their Client ID and click "Sačuvaj i nastavi" without being forced to retest the connection.
- **Onboarding `handleComplete()` always sends client_id** (`assets/js/admin-onboarding.js`) — Reads `#dex-ob-api-client-id` value immediately before firing the complete AJAX call, so `client_id` is always populated regardless of whether the admin clicked "Testiraj konekciju". Resolves the 2.4.0 tech debt item.

### Known Issues
- No automated test suite exists (`tests/` directory is empty). All testing has been manual against a local WordPress + WooCommerce install. PHPUnit is declared as a dev dependency in `composer.json`.
- `OnboardingPage::renderPanel6()` — Client ID warning is a PHP-time check. If admin enters Client ID during the same session and saves, the JS `syncClientIdWarningOnStep6()` hides the warning dynamically. But on hard reload before Step 6, the PHP-rendered warning may briefly flash if clientId wasn't in DB at page-load time.

---

## [2.8.0] — 2026-05-16

### Added
- `src/Domain/Shipment/ShipmentRepository.php` — Two new interface methods: `countAllocatedCodesInRange(string $prefix, int $rangeStart, int $rangeEnd): int` and `firstFreeNumericInRange(string $prefix, int $rangeStart, int $rangeEnd): ?int`. These replace the old `maxAllocatedNumericForPrefix` approximation with accurate per-range queries.
- `src/Application/Shipment/ShipmentCodeAllocator.php` — New public method `nextFreeCodeInConfiguredRange(): ?string`. Returns the next available code formatted for display (e.g. `ZS0000083330`), or `null` if the range is exhausted or not configured.
- `templates/admin/tab-api.php` — Live code preview section: shows first and last code in the configured range (e.g. `ZS0000083330 → ZS0000083430`) with total count, updates in real time as admin types prefix/range values. Added "Sledeći slobodni kod" badge row displaying the actual next allocatable code after page load or save.
- `assets/css/admin.css` — `.dex-code-preview` (flex row, monospace code display, hidden by default via `.is-hidden`), `.dex-next-code-row` (blue-tinted card row for next-free-code display).
- `src/Presentation/Admin/Pages/OnboardingPage.php` (`renderPanel2()`) — Same live preview and next-free-code badge as the API tab. `handleSaveCredentials()` now includes `nextFreeCode` in the AJAX success payload.
- `assets/js/admin-onboarding.js` — `updateObCodePreview()` function: updates live preview in onboarding Step 2. Called on page load (pre-filled fields render immediately), on input change to any code field, and from `mergeSettingsSnapshotFromAjax()` after a successful save. `nextFreeCode` from AJAX response populates and reveals `#dex-ob-next-code-row`.

### Changed
- `src/Infrastructure/Persistence/WpdbShipmentRepository.php` — `allocatePackageCode()` replaced MAX+1 strategy with first-free-slot iteration. Queries all active codes with the configured prefix, builds an occupied-set, then iterates from `rangeStart` to find the first gap. When a `pending_send` shipment is deleted, its package row is hard-deleted — the freed numeric slot is now correctly reused rather than wasted. Previously, deleting 10 codes and recreating them would allocate 10 new codes beyond the old MAX; now they fill the vacated slots.
- `src/Infrastructure/Persistence/WpdbShipmentRepository.php` — Implemented `countAllocatedCodesInRange()`: counts rows in `packages` where the numeric suffix of `code` falls within `[rangeStart, rangeEnd]`. Implemented `firstFreeNumericInRange()`: same query, then iterates from `rangeStart` to find the first missing integer.
- `src/Application/Shipment/ShipmentCodeAllocator.php` — `evaluateRangeUsageAfterSettingsSaved()` now calls `countAllocatedCodesInRange()` for the usage ratio (was: `maxAllocatedNumericForPrefix() - rangeStart + 1`, which over-counted when gaps existed).
- `src/Presentation/Admin/Pages/SettingsPage.php` — `shipmentCodeRangeStatusForTemplate()` updated: `$used` from `countAllocatedCodesInRange()` (accurate count), `$nextFree` from `firstFreeNumericInRange()`. Return shape updated: removed `max_numeric`, added `next_free`. Exhausted tier now based on `$nextFree === null || $remaining <= 0`.
- `templates/admin/tab-api.php` — Range description updated to explain the D-Express model ("D Express vam dodeljuje dvoslovni prefiks i numerički opseg — svaki paket dobija jedinstveni kod. Kad se opseg popuni, pozovite D Express da vam prošire 'Do'."). Usage monitor copy improved: counter shows "X / Y kodova iskorišćeno (Z%)", warning copy explicitly says to contact D-Express, exhausted copy gives exact instruction.
- `assets/js/admin-settings.js` — `buildAutocomplete()` town/street dropdowns now use `position: fixed` + `getBoundingClientRect()` (`positionBox()` function) so they escape the modal's `overflow: hidden` / `overflow-y: auto` container. Previously the dropdown was clipped inside the modal body. `close()` resets all inline CSS properties. Z-index set to 100001 (above modal's 100000).
- `assets/js/admin-settings.js` — `openModal()`: street input is now enabled/disabled based on whether `data.townId` is present when the modal opens. Editing the town text field disables and clears the street immediately. Selecting a town from the dropdown enables the street and shifts focus to it. Placeholder switches between "Prvo izaberite grad…" (disabled) and "Počnite kucati naziv ulice…" (enabled).
- `assets/js/admin-settings.js` — `#dex-save-location` handler: bank account (`#dex-loc-bank-account`) is now validated as required. Missing bank account blocks save with a focused error message.
- `templates/admin/tab-sender-locations.php` — Street input now has `disabled` attribute in HTML (JS manages the enabled state at runtime). Bank account label gets `*` required marker; description updated from "Neobavezno. Popunite samo ako…" to "Račun na koji D Express uplaćuje otkupninu za ovu lokaciju."
- `assets/js/admin-onboarding.js` — Rewrote `buildObAutocomplete()` to use `positionBox()` with `position: fixed` + `getBoundingClientRect()`, identical to the settings `buildAutocomplete()`. Switches from `.addClass('is-open')` / `.removeClass('is-open')` to jQuery `show()`/`hide()` with inline CSS positioning. Fixes the dropdown escaping its container in the wizard panel.
- `assets/js/admin-onboarding.js` — Onboarding Step 4 street input starts disabled at page load. `obTownAC.onSelect` enables it and shifts focus. Town text `input` event disables and clears it. Bank account validated as required in the save-location handler.
- `src/Presentation/Admin/Pages/OnboardingPage.php` (`renderPanel4()`) — Street input has `disabled` attribute and updated placeholder "Prvo izaberite grad…". Bank account label gets `*` required marker; description updated to match settings template.

### Architecture Notes
- Code allocation now behaves correctly under the two-step save/send flow: a `pending_send` shipment that is deleted frees its code because packages are hard-deleted with the shipment. The first-free-slot approach guarantees that slot is reused on the next allocation, keeping the range compact. This is important for production clients whose D-Express range is narrow (e.g. 100 codes).
- The `position: fixed` dropdown fix applies to any autocomplete placed inside a CSS `overflow` container. Both `buildAutocomplete` (settings) and `buildObAutocomplete` (onboarding) now use the same pattern. Any future autocomplete should follow the same `positionBox()` approach when there is a risk of an overflow ancestor.
- Bank account is now required at both entry points (onboarding and settings modal). This ensures the field is populated for every sender location, which is necessary for COD otkupnina reconciliation.

---

## Unreleased

### Known Issues
- `MyAccountTrackingTab` uses the same status display model as standard orders — there is no Package Shop-specific tracking view (e.g. "ready for pickup at location X"). Tracking display for Package Shop orders is functionally correct but not contextually tailored.
