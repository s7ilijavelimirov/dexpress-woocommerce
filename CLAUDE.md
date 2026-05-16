# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

WordPress plugin integrating WooCommerce with D Express (Serbian courier service). Handles checkout address collection, shipment creation via the D Express External API, inbound webhooks for status updates, printable labels, and customer-facing tracking.

- **Plugin version:** 2.0.1
- **PHP:** 8.1+ (strict types throughout)
- **Requires:** WordPress 6.3+, WooCommerce 8.0+
- **Markets:** Serbia (DACH + ex-YU region clients)
- **Status:** Active development, test environment, pre-production

## Commands

```bash
# Install dependencies (production)
composer install --no-dev

# Install with dev tools (PHPUnit)
composer install

# Run tests (if/when test files exist in tests/)
vendor/bin/phpunit

# Regenerate autoloader after adding new classes
composer dump-autoload
```

There is no build pipeline for JS/CSS — assets are enqueued directly via WordPress hooks and edited in place in `assets/`.

## Architecture

The plugin uses a **layered architecture** with a custom PSR-11 IoC container and service providers.

### Layers (`src/`)

| Layer | Directory | Purpose |
|-------|-----------|---------|
| Domain | `Domain/` | Value objects, entities, repository interfaces, domain events |
| Application | `Application/` | Use case services (shipment creation, sync, webhook processing, email) |
| Infrastructure | `Infrastructure/` | API client, database repos, logging, cron, barcode, encryption |
| Presentation | `Presentation/` | Admin pages, REST endpoint, checkout forms, AJAX controllers |
| Container | `Container/` | IoC container + 11 service providers |

### Bootstrap Flow

1. `dexpress-woocommerce.php` — defines constants, checks PHP/autoloader/WooCommerce, hooks `Plugin::getInstance()->boot()` to `plugins_loaded`
2. `Plugin::boot()` runs `DatabaseInstaller::maybeUpgrade()`, loads text domain, registers all service providers, REST routes, cron jobs, and hooks

### Dependency Injection

Services are registered in `src/Container/Providers/` via `ServiceProvider::register(Container $c)`. Use `$c->singleton()` for shared instances or `$c->bind()` for per-call factories. The container is resolved from the `Plugin` singleton.

### Key Patterns

- **Repository pattern**: domain interfaces in `Domain/*/`, `wpdb` implementations in `Infrastructure/Persistence/`
- **DTOs**: `CreateShipmentRequest`/`CreateShipmentResult`, `InboundStatusNotification`
- **Domain events**: `ShipmentCreated`, `StatusUpdated` — dispatched via WordPress `do_action`, subscribed by `EmailNotificationSubscriber`
- **Value objects**: `PhoneNumber`, `StreetNumber`, `PackageCode`, `Money`, `Grams` — immutable, self-validating

### Database

`DatabaseInstaller` manages 16 custom tables (prefix `{$wpdb->prefix}dexpress_*`). Schema version stored in option `dexpress_db_version`. Fresh install creates all tables via `PluginActivator::activate()`. No runtime migrations — schema is defined in CREATE TABLE statements directly.

Key tables:
- `wp_dexpress_shipments` — shipments with `send_status` column (`pending_send` / `sent`)
- `wp_dexpress_packages` — packages per shipment
- `wp_dexpress_locations` — ALL pickup locations (paket shop + paketomat, 367 rows)
- `wp_dexpress_dispensers` — paketomat only (294 rows, subset of locations)
- `wp_dexpress_payments` — COD reconciliation (populated manually via admin)
- `wp_dexpress_webhook_logs` — inbound webhook log

### D Express API

- Base URL: `https://usersupport.dexpress.rs/ExternalApi`
- Auth: HTTP Basic (username + AES-encrypted password in options)
- Client: `src/Infrastructure/Api/DExpressApiClient.php`
- `addShipment` returns plain text `"OK"` (prod) or `"TEST"` (test env) — not JSON
- `BuyOutAccount` is marked optional in docs but API returns HTTP 400 if BuyOut > 0 and field is missing — treat as required when COD

### Webhook

- REST endpoint: `PUT|POST /wp-json/dexpress/v1/notify`
- Controller: `src/Presentation/Rest/WebhookController.php`
- Processing: async via Action Scheduler
- IP allowlist: single IP configurable in settings (`webhook.ip_address`), empty = allow all

### Settings

All settings stored under the `dexpress_settings` option key. Accessed via `OptionsRepository`. API password stored encrypted via `EncryptedString`.

### PSR-4 Namespace

`S7codedesign\DExpress\` → `src/`
`S7codedesign\DExpress\Tests\` → `tests/`

---

## Shipping Methods

Two WooCommerce shipping methods:
- `dexpress` — standard door-to-door delivery
- `dexpress_package_shop` — Package Shop / Paketomat pickup point delivery

---

## Shipment Creation Flow (CRITICAL)

**Two-step flow — do not collapse into one:**

1. `CreateShipmentService::save()` — validates, allocates real TT code, saves to DB with `send_status = 'pending_send'`, does NOT call API
2. Admin prints label (existing `PrintLabelController` route)
3. Admin physically packages shipment
4. `CreateShipmentService::send()` — reads fresh sender location data, calls `POST /data/addshipment`, updates `send_status = 'sent'`

D-Express documentation explicitly states: *"Call this method after shipment is packaged, properly labeled and ready for pickup by courier."*

`execute()` exists as a compatibility wrapper that calls `save()` then `send()` in sequence.

**State machine in `OrderShipmentMetabox`:**
- No shipment in DB → State A (wizard, 3 steps)
- Shipment exists + `send_status = pending_send` → State B (awaiting send)
- Shipment exists + `send_status = sent` → State C (created, show tracking)

---

## Package Shop / Paketomat Flow

When order uses `dexpress_package_shop` shipping method:

- Selected location stored in order meta: `_dexpress_package_shop_location_*` keys
- `location_type = 2` → Paketomat (locker), requires `DispenserID` in payload
- `location_type = 1 or 3` → Paket Shop (staffed), no `DispenserID`
- R* (recipient) fields in payload = pickup location address, NOT customer billing address
- RCName/RCPhone = customer contact for D-Express notifications

**Paketomat constraints (enforced in `validatePackageShopShipmentConstraints`):**
1. Only 1 package
2. BuyOut < 20,000,000 para (200,000 RSD)
3. Recipient phone must be mobile (06x prefix)
4. ReturnDoc must be 0
5. Weight < 20kg
6. Dimensions < 470×440×440mm

---

## Cron Jobs

| Hook | Frequency | What |
|------|-----------|------|
| `dexpress_cron_daily` | Daily 02:00 | dispensers + locations sync |
| `dexpress_cron_monthly` | Monthly 03:00 | municipalities, centres, towns, streets + log purge |
| `dexpress_cron_quarterly` | Quarterly 04:00 | shops |
| `dexpress_cron_semi_annual` | 180 days | status codes |

Payments sync is **manual only** — no cron. Admin enters PaymentReference from bank statement manually in the Otkupnine admin page.

---

## Admin Pages

- **Dashboard** — overview
- **Pošiljke** — shipment list
- **Otkupnine** — COD payments (manual sync by PaymentReference)
- **Podešavanja** — settings tabs: API, Shipment, Webhook, Sender Locations, Checkout, Email, Logging, Šifarnici, Simulation
- **Dijagnostika** — diagnostics

Shipping method settings (in WooCommerce → Settings → Shipping):
- `DexpressShippingMethod` — standard delivery
- `DexpressPackageShopShippingMethod` — Package Shop (includes Google Maps API key field)

---

## Frontend (Checkout)

- Custom address fields for D-Express (town/street from šifarnici)
- Package Shop modal: Google Maps + dispenser/location browser
  - Google Maps always loads (with or without API key — warning banner appears without key)
  - Marker clustering via `@googlemaps/markerclusterer`
  - Left panel: searchable list with diacritic-insensitive search (š,đ,ž,č,ć)
  - Filter tabs: Sve / Paketomati / Paket Shopovi
  - Mobile: drawer/hamburger layout
- Hidden checkout fields: `dexpress_checkout_dispenser_id`, `dexpress_checkout_location_type`

---

## Email System

Custom WooCommerce emails:
- `dexpress_package_shop_ready_for_pickup` — "Paket spreman za preuzimanje"
  - Auto-triggered on sID 830 (paketomat) or 842 (paket shop)
  - Also manually triggerable from order admin action
  - Duplicate guard via `_dexpress_package_shop_ready_email_sent` order meta

---

## Label Printing

- Route: `admin.php?page=dexpress-label&shipment_id=X&nonce=Y`
- Controller: `src/Presentation/Admin/Label/PrintLabelController.php`
- Inline HTML/CSS (no mPDF)
- Formats: 1-up, 2-up, 4-up (A4)
- Package Shop labels show pickup location as primary recipient with type badge

---

## Known Quirks & Decisions

- `BuyOutAccount` is required by API when BuyOut > 0, despite docs saying optional
- `wp_dexpress_locations` is the single source of truth for the browser modal (not `wp_dexpress_dispensers`)
- Webhook IP allowlist: single IP field, empty = allow all, no CIDR support
- `send_status` DEFAULT must be `pending_send` in CREATE TABLE (not `sent`)
- Google Maps API key is optional — map renders with warning banner without it
- Payments (`viewpayments`) require a PaymentReference from bank statement — no "fetch all" endpoint
- Test environment uses prefix `TT`, range 1-99 — production prefix assigned by D-Express separately

---

## What Is NOT Yet Implemented

- Automatic validation of package dimensions at checkout (currently only at shipment creation time)
- License/SaaS system (planned: Laravel license server + plugin key validation)
- `viewpayments` automatic sync (manual only by design)
- Tracking display specific to Package Shop (uses same status model as standard)

---

## Current Status

### Completed Phases

- **2.0.0** — Full DDD architecture: Domain/Application/Infrastructure/Presentation layers, IoC container, 16 DB tables, all šifarnici sync, webhook, label printing, single-shipment metabox, settings, diagnostics, checkout fields.
- **2.1.0** — Package Shop checkout modal (Google Maps, diacritic search, filter tabs, mobile drawer), `DispenserBrowserRepository`, hidden order meta fields.
- **2.2.0** — Package Shop shipping method, `PackageShopInfoMetabox`, paketomat constraints validation, two-step save/send flow, three-state metabox machine (`pending_send` / `sent`), Package Shop label variant.
- **2.3.0** — Onboarding wizard (6 steps), bulk shipment wizard (originally 3-step), `BulkShipmentController`, package profiles page + modal + repository, COD payments page (Otkupnine), orders bulk-action redirect, delivery status column on orders list.
- **2.3.1** — Code audit: removed dead hooks, dead `$_bucket` param, dead `label()` method, removed stale milestone comments. Codebase declared clean.
- **2.4.0** — Fixed onboarding redirect on first activation; unified bulk label printing via `renderMultiple()` (removed `renderBulk()`); fixed `buildLabelUrl()` nonce bug (server now returns `label_url`); fixed empty tablenav top on shipments list; added `CClientID` field to onboarding Step 2.
- **2.5.0** — `ShipmentsPage` expanded with phone, shipping address, payment method, delivery type columns; HPOS-aware edit URLs; package profile dropdown in header. Package profiles page fully redesigned (card grid, centered modal, sidebar rules panel). Bulk back-button changed to point to Pošiljke page.
- **2.6.0** — Bulk wizard refactored to 4 steps (Podešavanja → Pregled → Štampa → Slanje); print-before-send enforced in JS; weight field made optional (batch override); Step 2 table merged to 6 columns; error row visibility fixed; CSS specificity bugs fixed.
- **2.7.0** — `DexpressShippingGate` checkout guard (skips all frontend hooks if no D-Express method enabled); bulk wizard Pregled step (snapshot preview before creation); persistent "Čekaju slanje" card on Pošiljke page (server-rendered, AJAX send); `BulkShipmentController` reads delivery/payment/return type defaults from options (removed hardcoded POST params); `shipment.default_self_drop_off` setting added; onboarding client_id fix (reads field value at completion time, pre-validates Step 2 without requiring clientIdInDb); label toolbar redesigned (logo, 2-up/4-up only, removed 1-up).

### Currently In Progress

- **CSS design system migration** (`DESIGN.md` spec finalized 2026-05-13): `admin-design-system.css` to be merged into `admin.css`; all `dexpress-` class prefixes renamed to `dex-`; `admin-shipments.css` to be deleted (empty file); Google Fonts import to be removed from `admin-diagnostics.css`; `!important` overrides to be replaced with proper specificity; inline styles in PHP templates to be replaced with component classes.

### Broken / Pending

- `admin-shipments.css` — empty file that should be deleted; its `enqueueAdminAssets()` registration in `AdminMenu.php` removed at the same time (per `DESIGN.md`).
- `OnboardingPage::renderPanel6()` — Client ID warning is a PHP-time check; if admin enters Client ID during the same session and saves, `syncClientIdWarningOnStep6()` in JS hides it dynamically. But on hard reload before Step 6, the PHP-rendered warning may briefly flash if clientId wasn't in DB at page-load time. Low priority — conservative false positive, not dangerous.
- No automated test suite — `tests/` directory is empty. PHPUnit is declared in `composer.json` but no test files exist.

---

## Active Tasks

Unfinished items from `docs/DEV-LOG.md`, ordered by version:

- **`profile_id` pre-selection in bulk wizard** (`src/Presentation/Admin/Pages/BulkShipmentPage.php`) — `ShipmentsPage` passes `profile_id` as a URL query param when redirecting to the bulk page, but `BulkShipmentPage` ignores it. A JS one-liner on page load (`if (urlParam('profile_id')) { applyProfile(id) }`) would complete the wire-up. [2.5.0 tech debt]
- **Checkout-time paketomat dimension validation** (`src/Application/Shipment/CreateShipmentService.php`, `src/Presentation/Frontend/Checkout/CheckoutValidator.php`) — Dimension limits (470×440×440mm) enforced at shipment creation but not at checkout. A customer can place an order with oversized items; the failure surfaces only when the admin creates the shipment. Requires reliable product dimension data at checkout. [2.2.0 tech debt]
- **CSS design system migration** (`assets/css/`) — Full spec in `DESIGN.md`. Tasks: merge `admin-design-system.css` into `admin.css`; rename all `dexpress-` classes to `dex-`; delete `admin-shipments.css`; remove Google Fonts `@import` from `admin-diagnostics.css`; eliminate `!important` overrides; remove inline styles from PHP templates. JS selectors in `admin-settings.js` and `admin-metabox.js` must be updated in the same pass as the class renames.
- **Package Shop tracking view** (`src/Presentation/Frontend/MyAccount/MyAccountTrackingTab.php`) — Package Shop orders use the same status display as standard orders; there is no contextually tailored view (e.g. "ready for pickup at location X"). [Unreleased / known issue]

---

## Do Not Touch

These modules are complete, audited, and stable. Do not modify unless there is a specific bug to fix.

- **Domain layer** (`src/Domain/`) — All value objects (`PhoneNumber`, `StreetNumber`, `PackageCode`, `Money`, `Grams`), enums (`DeliveryType`, `PaymentType`, `PaymentBy`, `ReturnDoc`, `StatusEmailBucket`), `ShipmentRepository` interface, domain events (`ShipmentCreated`, `StatusUpdated`). Audited clean in 2.3.1.
- **Application services** (`src/Application/`) — `CreateShipmentService` (save/send two-step flow), `ShipmentStatusIngestionService`, `EmailNotificationSubscriber`, `SimulationService`, all 8 `Sync*Service` classes. Audited clean in 2.3.1.
- **Infrastructure layer** (`src/Infrastructure/`) — `DExpressApiClient`, `EncryptedString`, `Code128` barcode generator, `WpCronScheduler`, `Logger`, `OptionsRepository`, `DatabaseInstaller`, `ShipmentCodeAllocator`, `DispenserBrowserRepository` (read-only by design — do not add write operations).
- **REST webhook** (`src/Presentation/Rest/WebhookController.php`) — IP allowlist, async dispatch via Action Scheduler, webhook log. Stable.
- **Label printing** (`src/Presentation/Admin/Label/PrintLabelController.php`) — `buildLabelData()` / `printSheets()` / `renderMultiple()` architecture settled in 2.4.0. `renderBulk()` was removed; do not reintroduce it. 1-up layout removed in 2.7.0 (2-up and 4-up only). Toolbar redesigned in 2.7.0 (logo + grouped action buttons).
- **Package Shop checkout flow** (`src/Presentation/Frontend/Checkout/PackageShopCustomerFlow.php`, `assets/js/package-shop-checkout.js`, `assets/css/package-shop-checkout.css`) — Google Maps modal, diacritic search, filter tabs, mobile drawer. Completed in 2.1.0.
- **Paketomat constraints** (`src/Application/Shipment/CreateShipmentService.php::validatePackageShopShipmentConstraints()`) — Encodes hard D-Express API limits. Do not relax without API documentation change.
- **`BuyOutAccount` required-when-COD** (`src/Infrastructure/Api/DExpressApiClient.php`) — API returns HTTP 400 if BuyOut > 0 and field is absent, despite docs marking it optional. This is intentional and must not be removed.
- **COD payments page** (`src/Presentation/Admin/Pages/PaymentsPage.php`) — Manual-only sync by design; D-Express `viewpayments` has no "fetch all" endpoint.
- **Email system** (`src/Presentation/Email/`, `src/Application/Email/`) — Package Shop ready-for-pickup email with duplicate guard. Stable.
- **Šifarnici sync services** — Towns, streets, municipalities, centres, shops, dispensers, locations, status codes. Cron schedules wired; do not modify frequency without confirming D-Express data update cadence.