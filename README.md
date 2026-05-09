# D Express WooCommerce Integration

WordPress plugin that connects [WooCommerce](https://woocommerce.com/) to **D Express** (Serbia): courier rates at checkout, structured domestic addresses, shipment registration through the D Express External API, printable shipping labels, inbound webhooks for tracking status, and customer-facing notifications and account tracking.

**Plugin location in this repository:** `public/wp-content/plugins/dexpress-woocommerce/`

**Current version (plugin header):** 2.0.1

---

## Description

The plugin’s purpose is end-to-end **WooCommerce ↔ D Express** operations: merchants configure API credentials and reference data sync, customers see a **D Express** shipping method and complete checkout with town/street data suitable for the carrier, staff create shipments from the order screen (wizard with packages, weights, COD, etc.), and status updates flow back via a **REST webhook** with optional automated emails and a **My Account** tracking view.

The HTTP client targets the D Express External API base documented in code as `https://usersupport.dexpress.rs/ExternalApi` (Basic auth with stored username/password).

---

## Features

Based on the implemented codebase:

- **WooCommerce shipping method** (`dexpress`): zone-based instance settings for title, flat rate in RSD (excl. tax), and optional free-shipping threshold by cart subtotal (excl. tax). Rates are offered only when the shipping destination country is **RS**.
- **Checkout address model**: billing/shipping fields adapted for Serbian locality (e.g. city field used as town autocomplete), hidden technical meta (town ID, street ID, street number), support for **classic** and **block** checkout paths, phone normalization, and optional **API address preflight** (`/data/checkaddress`) when enabled in settings.
- **Sender locations**: multiple warehouse/sender addresses stored in the database; one can be default; used when building shipments and labels.
- **Shipment creation (admin)**: order **metabox** (“D Express — pošiljke”) appears only for orders that use the D Express shipping method. Step-by-step wizard: package count (1–100), per-package mass/dimensions/content and optional line-item allocation, delivery type, payment type, return document option, self drop-off, sender location, content and note. Submits via AJAX to create the shipment through the API. **At most one D Express shipment per order** (enforced in application logic).
- **API integration**: `DExpressApiClient` wraps GET/POST JSON calls, dedicated `addShipment` and `checkAddress` handling (including non-JSON/plain responses where applicable), Basic authentication, configurable test vs production environment flag.
- **Custom database tables**: shipments, packages, package line allocations, shipment items, status history, webhook log, sender locations, synced reference data (towns, streets, municipalities, status codes, dispensers, locations, centres, shops, payments), with versioned schema upgrades via `DatabaseInstaller`.
- **Reference data sync**: scheduled **WP Cron** jobs (monthly / quarterly / semi-annual) pull catalog data from the API into local tables; **manual sync** is available from admin (AJAX). Diagnostics show last sync timestamps per dataset.
- **Inbound webhook**: REST routes `PUT` and `POST` on `dexpress/v1/notify`; validates shared **passcode** (`cc`); deduplicates by notification id; queues asynchronous processing; updates shipment status from package code and status id (`sID`).
- **Simulation (test API only)**: optional timeline that injects synthetic webhook steps for `TEST` API responses so flows can be exercised without live carrier movement.
- **Printable labels**: standalone admin page (`admin.php?page=dexpress-label`) renders HTML labels with **Code 128** barcodes, sender/recipient blocks, COD and payment lines, A4 layouts for **2** or **6** labels per sheet; opened from the order metabox per shipment.
- **Emails**: registers WooCommerce email class **D Express — praćenje pošiljke** (`dexpress_shipment_notification`) with HTML/plain templates; optional automation on shipment created and on status buckets (in transit, out for delivery, all packages delivered, problem/failed). Per-order meta prevents duplicate sends; short **rate limiting** via transients; in **test** environment emails go to the site admin email unless overridden. Template overrides can live in the active theme under `dexpress-woocommerce/emails/…`.
- **Customer tracking**: optional **My Account** endpoint listing shipments and statuses; public tracking URLs built toward `dexpress.rs` from package codes (`TrackingLinkBuilder`).
- **Admin UI**: top-level **D Express** menu (Overview, Shipments list, Settings with tabs, Diagnostics), order metabox, plugin action link to settings, file-based **logging** (configurable), **HPOS** compatibility declaration, **Test connection** and sender-location AJAX tools.

---

## Requirements

| Requirement | Version / note |
|-------------|----------------|
| **WordPress** | 6.3 or higher (`Requires at least` in plugin header) |
| **PHP** | 8.1 or higher |
| **WooCommerce** | 8.0 or higher (tested up to 9.9 per header) |
| **Composer** | Required to generate `vendor/autoload.php` before runtime |

The main plugin file aborts boot with an admin notice if Composer’s autoloader is missing or WooCommerce is not active.

---

## Installation

1. **Copy the plugin** into your WordPress installation:

   `wp-content/plugins/dexpress-woocommerce/`

   (In this repo, that path is under `public/wp-content/plugins/dexpress-woocommerce/`.)

2. **Install PHP dependencies** from the plugin directory:

   ```bash
   cd wp-content/plugins/dexpress-woocommerce
   composer install --no-dev
   ```

   Use `composer install` (with dev) only if you intend to run tests or develop the plugin.

3. In **WordPress Admin → Plugins**, activate **D Express WooCommerce Integration**.

4. Ensure **WooCommerce** is installed and activated first; otherwise the plugin will show a notice and not load.

5. After activation, visit **WooCommerce → Settings → Advanced → Features** if you use custom order storage (HPOS); the plugin declares HPOS compatibility in code.

6. **Permalinks**: if the customer **My Account** endpoint does not resolve, save **Settings → Permalinks** once (the plugin may flush rewrite rules when endpoint configuration changes).

---

## Configuration

All carrier-specific settings are under **WordPress Admin → D Express → Podešavanja** (`admin.php?page=dexpress-settings`). Tabs (labels in the UI may be in Serbian):

| Tab | Purpose |
|-----|---------|
| **API** | API username, password (stored encrypted; leave blank to keep existing), **Client ID (CClientID)**, environment (**test** / **production**), shipment code **prefix** and numeric **range** for generated references, default shipment options, **Test connection** (AJAX). |
| **Webhook** | Displays **Webhook URL** (`rest_url('dexpress/v1/notify')`) and **passcode (`cc`)** generated on activation; instructions to register these with D Express support. Accepts **PUT** (query params) and **POST** (JSON body, with fallbacks). |
| **Sender locations** | CRUD for sender addresses used in the shipment wizard and labels. |
| **Checkout** | Toggle **checkaddress** validation on checkout and related logging behavior. |
| **Email** | Master toggle for **automatic status emails**, **My Account tracking** section, and whether in test mode emails go to real customer addresses. Individual WC email on/off remains under **WooCommerce → Settings → Emails**. |
| **Logging** | Plugin log options (see Diagnostics for log file location). |
| **Šifarnici** | Reference catalogs (towns, streets, municipalities, status codes, dispensers, locations, centres, shops): shows sync frequency hints and **manual sync** actions (AJAX) per dataset. |
| **Simulacija** | Test-environment simulation of webhook timeline (see Features). |

Additional configuration:

- **WooCommerce → Settings → Shipping**: add **D Express dostava** to a shipping zone covering Serbia and set instance **cost**, **free shipping threshold**, and **title**.
- **WooCommerce → Settings → Emails**: enable or disable **D Express — praćenje pošiljke** and use preview where supported (`woocommerce_prepare_email_for_preview` integration exists in code).

---

## Usage

### Storefront

1. Customer selects **D Express** as the shipping method (Serbian address / RS destination per rate logic).
2. Checkout collects structured address data (town/street autocomplete and related meta). If **checkaddress** is enabled in plugin settings, checkout can be blocked when the API does not return a successful validation.
3. After purchase, optional **My Account →** D Express shipments endpoint (if enabled) lists shipments tied to the customer’s orders with status labels resolved from the synced status catalog.

### Back office

1. Open an order that used **D Express** shipping. The **D Express — pošiljke** metabox appears.
2. Ensure at least one **sender location** exists in **D Express → Podešavanja**.
3. Complete the wizard: packages, masses/dimensions, options, then **Create shipment**. The plugin calls D Express `addShipment`, persists shipment and package rows, and fires `dexpress/shipment.created` (email + simulation hooks).
4. Use **Nalepnica** in the metabox to open the label print URL in a new tab; choose 2-up or 6-up A4 layout in the toolbar.
5. When D Express calls your **notify** URL, the plugin logs the payload, schedules processing, matches **package code** to a shipment, updates status history and shipment snapshot fields, dispatches `dexpress/shipment.status_updated`, adds a **WooCommerce order note** summarizing the status change, and can send automated customer emails according to options.

After successful shipment creation, the order receives a note with the D Express tracking code.

**Cash on delivery (COD):** If the order payment method is WooCommerce `cod`, the shipment carries COD amount from the order total; sender location must include a valid **bank account** for COD payout (validated before API submission).

---

## Developer notes

- **Architecture**: PHP 8.1+ strict code under `src/` with **PSR-4** namespace `S7codedesign\DExpress\`. A lightweight **service container** wires domain, application, infrastructure, and presentation layers; providers include `OptionsServiceProvider`, `ApiServiceProvider`, `ShipmentServiceProvider`, `SyncServiceProvider`, `WebhookServiceProvider`, `CronServiceProvider`, `EmailServiceProvider`, `FrontendServiceProvider`, `AdminServiceProvider`, `LabelServiceProvider`, and `SimulationServiceProvider`.
- **Bootstrap**: `Plugin::getInstance()->boot()` (from `plugins_loaded`) runs DB migrations, HPOS declaration, provider registration, REST route registration, cron registration, email subscriber registration, and admin/front hooks.
- **Notable classes**: `DExpressApiClient` (HTTP), `CreateShipmentService` / `CreateShipmentRequest` (shipment use case), `ProcessWebhookService` / `ShipmentStatusIngestionService` (webhook pipeline), `EmailNotificationSubscriber` + `EmailShipmentNotification` (WC emails), `PrintLabelController` + `Code128` (labels), `WebhookController` (REST), `WpCronScheduler` (sync + log maintenance), `DatabaseInstaller` (schema).
- **Templates**: `templates/admin/*`, `templates/emails/*`, `templates/myaccount/*`.
- **Assets**: `assets/css`, `assets/js`, `assets/images`.
- **Tests**: PHPUnit configured in `composer.json` under `tests/` (dev dependency).

---

## Screenshots

| Screen | Placeholder |
|--------|-------------|
| D Express admin dashboard | *No screenshot in repository — add `docs/screenshots/dashboard.png` and link here if desired.* |
| Order metabox — shipment wizard | *Placeholder* |
| Settings — API tab | *Placeholder* |
| Printable label (A4) | *Placeholder* |
| My Account — shipments | *Placeholder* |

---

## Changelog

### 1.0.0

- Initial documented release for public repository README (feature list aligned with codebase at plugin version **2.0.1**).

---

## Author

- **Plugin author (header):** [S7codedesign](https://s7codedesign.com/)
- **License:** GPL-2.0-or-later

---

## External references

- D Express public tracking URL pattern used in code: `https://www.dexpress.rs/rs/pracenje-posiljaka/{packageCode}`
- Webhook registration: contact **D Express** support with the REST notify URL and passcode as described in the plugin’s Webhook settings tab (e.g. `podrška@dexpress.rs` appears in the admin template).
