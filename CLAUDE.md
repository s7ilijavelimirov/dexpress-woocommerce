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