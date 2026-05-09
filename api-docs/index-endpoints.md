# Reference Data (Index) Endpoints

> Read-only endpoints for syncing D Express reference data into local tables.
> All endpoints use GET. Authentication: HTTP Basic Auth.
> Base URL: `https://usersupport.dexpress.rs/ExternalApi`

---

## Sync strategy

| Endpoint | Table | Frequency | Has `date` filter |
|---|---|---|---|
| `GET /data/towns` | `wp_dexpress_towns` | Monthly (1st) | Yes |
| `GET /data/streets` | `wp_dexpress_streets` | Weekly (Sunday) | Yes |
| `GET /data/municipalities` | `wp_dexpress_municipalities` | Monthly (1st) | Yes |
| `GET /data/dispensers` | `wp_dexpress_dispensers` | Daily (night) | No |
| `GET /data/locations` | `wp_dexpress_locations` | Daily (night) | No |
| `GET /data/centres` | `wp_dexpress_centres` | Weekly | No |
| `GET /data/shops` | `wp_dexpress_shops` | Weekly | No |
| `GET /data/statuses` | `wp_dexpress_status_codes` | On activation + weekly | No |

**`date` parameter**: when provided, returns only records changed **after** that date. Format: `yyyyMMddHHmmss`. Use this for incremental sync — pass the last sync timestamp, not a full re-download every time.

**First sync** (on plugin activation): call with `date=20000101000000` to get all records. (Consistent with `README.md` and v1 implementation.)

---

## GET /data/towns

Returns list of all towns D Express delivers to.

**URL:** `GET /data/towns?date={date}`

**Query parameter:**

| Name | Type | Required | Format |
|---|---|---|---|
| `date` | string | Yes | `yyyyMMddHHmmss` |

**Response:** Array of `Town`

| Field | Type | Description | DB column |
|---|---|---|---|
| `Id` | integer | Town ID (D Express internal) | `id` (PK) |
| `Name` | string | Town name (Serbian Latin) | `name` |
| `DName` | string | Display name (e.g. "Beograd (Stari grad)") | `display_name` |
| `CentarId` | integer | Regional centre ID (FK to centres) | `centre_id` |
| `MId` | integer | Municipality ID (FK to municipalities) | `municipality_id` |
| `PttNo` | integer | Postal code | `postal_code` |
| `O` | integer | Sort order | `sort_order` |
| `DeliveryDays` | string | Delivery days info | `delivery_days` |
| `CutOffPickupTime` | string | Latest pickup time for next-day delivery | `cut_off_pickup_time` |

**Sample response:**
```json
[
  {
    "Id": 791032,
    "Name": "Beograd",
    "DName": "Beograd (Zvezdara)",
    "CentarId": 1,
    "MId": 5,
    "PttNo": 11000,
    "O": 1,
    "DeliveryDays": "1-2",
    "CutOffPickupTime": "16:00"
  }
]
```

**Implementation notes:**
- Store `Id` as PK — it's the `TownID` referenced everywhere else.
- `DName` is what we show in the checkout autocomplete dropdown.
- `CutOffPickupTime` is used for estimated delivery date calculation in emails.
- Add `name_searchable` column (diacritic-stripped, lowercase) for fast autocomplete search — see `serbian-address-handling.md`.

---

## GET /data/streets

Returns list of all streets. Large dataset (~50k–100k rows) — use `date` filter for incremental sync.

**URL:** `GET /data/streets?date={date}`

**Query parameter:**

| Name | Type | Required | Format |
|---|---|---|---|
| `date` | string | Yes | `yyyyMMddHHmmss` |

**Response:** Array of `Street`

| Field | Type | Description | DB column |
|---|---|---|---|
| `Id` | integer | Street ID (D Express internal) | `id` (PK) |
| `Name` | string | Street name (Serbian Latin) | `name` |
| `TId` | integer | Town ID (FK to towns) | `town_id` |
| `Del` | boolean | Soft-deleted by D Express | `deleted` |

**Sample response:**
```json
[
  { "Id": 1001, "Name": "Kralja Petra", "TId": 791032, "Del": false },
  { "Id": 1002, "Name": "Stara ulica", "TId": 791032, "Del": true }
]
```

**Implementation notes:**
- `Del: true` means D Express discontinued this street — hide from autocomplete but keep in DB for historical shipment display.
- Add `name_searchable` column for diacritic-tolerant search.
- Autocomplete must filter by `town_id` — never search all streets globally (too slow, ambiguous).
- Index: `(town_id, name_searchable)` compound index for checkout autocomplete queries.

---

## GET /data/municipalities

**URL:** `GET /data/municipalities?date={date}`

| Field | Type | Description | DB column |
|---|---|---|---|
| `Id` | integer | Municipality ID | `id` (PK) |
| `Name` | string | Municipality name | `name` |
| `PttNo` | integer | Postal code | `postal_code` |
| `O` | integer | Sort order | `sort_order` |

**Usage:** Reference table only. Towns reference municipalities via `MId`. Not shown to end users.

---

## GET /data/dispensers

Package lockers / automated parcel machines. Call once per day, ideally at night.

**URL:** `GET /data/dispensers` (no parameters)

**Response:** Array of `Dispenser`

| Field | Type | Description | DB column |
|---|---|---|---|
| `ID` | integer | Dispenser ID | `id` (PK) |
| `Name` | string | Location name | `name` |
| `Address` | string | Street address | `address` |
| `Town` | string | Town name (display) | `town_name` |
| `TownID` | integer | Town ID (FK to towns) | `town_id` |
| `WorkingHours` | string | **Obsolete** — use `WorkHours` | — |
| `WorkHours` | string | Working hours string | `work_hours` |
| `WorkDays` | string | Working days string | `work_days` |
| `Latitude` | string | GPS latitude | `latitude` |
| `Longitude` | string | GPS longitude | `longitude` |
| `PayByCash` | boolean | Cash payment available | `pay_by_cash` |
| `PayByCard` | boolean | Card payment available | `pay_by_card` |

**Sample response:**
```json
[
  {
    "ID": 1,
    "Name": "NIS petrol - Mije Kovačevića",
    "Address": "Mije Kovačevića 7b",
    "Town": "Beograd (Zvezdara)",
    "TownID": 791032,
    "WorkingHours": "0-24",
    "WorkHours": "0-24",
    "WorkDays": "Mon-Sun",
    "Latitude": "44.813833000",
    "Longitude": "20.489759000",
    "PayByCash": false,
    "PayByCard": false
  }
]
```

**Implementation notes:**
- `WorkingHours` is marked obsolete by D Express — use `WorkHours`.
- Dispenser shipments have restrictions: max 1 package, max 20 kg, COD max 200,000 RSD, mobile phone required for SMS notification.
- Show dispensers on a map or in a filterable list on checkout.
- Filter by `TownID` to show only dispensers in the customer's selected town.

---

## GET /data/locations

D Express pickup/drop-off locations (physical shops + partners).

**URL:** `GET /data/locations` (no parameters)

**Response:** Array of `Location`

| Field | Type | Description | DB column |
|---|---|---|---|
| `ID` | integer | Location ID | `id` (PK) |
| `Name` | string | Location name | `name` |
| `Description` | string | Additional description | `description` |
| `Address` | string | Street address | `address` |
| `Town` | string | Town name (display) | `town_name` |
| `TownID` | integer | Town ID (FK) | `town_id` |
| `WorkingHours` | string | **Obsolete** | — |
| `WorkHours` | string | Working hours | `work_hours` |
| `WorkDays` | string | Working days | `work_days` |
| `Phone` | string | Contact phone | `phone` |
| `Latitude` | string | GPS latitude | `latitude` |
| `Longitude` | string | GPS longitude | `longitude` |
| `LocationType` | string | Type code (e.g. "2") | `location_type` |
| `PayByCash` | boolean | Cash payment available | `pay_by_cash` |
| `PayByCard` | boolean | Card payment available | `pay_by_card` |

**Note:** `LocationType` values are not documented in the API. From sample data: `"2"` appears to be partner pickup points, `"3"` appears in shops. ⚠ Verify with D Express.

---

## GET /data/centres

D Express regional sorting/distribution centres.

**URL:** `GET /data/centres` (no parameters)

**Response:** Array of `Centre`

| Field | Type | Description | DB column |
|---|---|---|---|
| `ID` | integer | Centre ID | `id` (PK) |
| `Name` | string | Centre name | `name` |
| `Prefix` | string | Centre prefix code | `prefix` |
| `Address` | string | Address | `address` |
| `Town` | string | Town name | `town_name` |
| `TownID` | integer | Town ID (FK) | `town_id` |
| `Phone` | string | Contact phone | `phone` |
| `Latitude` | string | GPS latitude | `latitude` |
| `Longitude` | string | GPS longitude | `longitude` |
| `WorkingHours` | string | **Obsolete** | — |
| `WorkHours` | string | Working hours | `work_hours` |
| `WorkDays` | string | Working days | `work_days` |

**Usage:** Centres are referenced by towns via `CentarId`. Used for display and routing info. Not directly used in shipment creation.

---

## GET /data/shops

D Express retail shops where customers can drop off or pick up parcels.

**URL:** `GET /data/shops` (no parameters)

**Response:** Array of `Shops`

| Field | Type | Description | DB column |
|---|---|---|---|
| `ID` | integer | Shop ID | `id` (PK) |
| `Name` | string | Shop name | `name` |
| `Description` | string | Description | `description` |
| `Address` | string | Address | `address` |
| `Town` | string | Town name | `town_name` |
| `TownID` | integer | Town ID (FK) | `town_id` |
| `WorkingHours` | string | **Obsolete** | — |
| `WorkHours` | string | Working hours | `work_hours` |
| `WorkDays` | string | Working days | `work_days` |
| `Phone` | string | Contact phone | `phone` |
| `Latitude` | string | GPS latitude | `latitude` |
| `Longitude` | string | GPS longitude | `longitude` |
| `LocationType` | string | Type code | `location_type` |
| `PayByCash` | boolean | Cash available | `pay_by_cash` |
| `PayByCard` | boolean | Card available | `pay_by_card` |

---

## GET /data/statuses

Full list of D Express shipment status codes. Sync on activation + weekly.

**URL:** `GET /data/statuses` (no parameters)

**Response:** Array of `Status`

| Field | Type | Description | DB column |
|---|---|---|---|
| `ID` | integer | Status ID (sID) — can be negative | `sid` (PK) |
| `Name` | string | Status name in Serbian | `name_sr` |
| `NameEn` | string | Status name in English | `name_en` |

**Known statuses from API sample:**

| sID | Serbian | English |
|---|---|---|
| `-2` | Obrisana pošiljka | Shipment is removed from D Express system |
| `-1` | Storno isporuke | Shipment delivery canceled (undelivered) |
| `0` | Čeka na preuzimanje | Shipment is created in D Express system |
| `1` | Pošiljka je isporučena primaocu | Shipment is delivered |
| `3` | Pošiljka je preuzeta od pošiljaoca | Shipment picked-up by courier |

**Full mapping** of sID → internal plugin status: see `business-logic/status-mapping.md`.

**Implementation notes:**
- `sID` can be **negative** — use `INT` (signed), not `UNSIGNED`.
- Store the full list as a local reference table for display in admin (human-readable status labels).
- The webhook delivers `sID` — look it up here for the display name.

---

## PHP implementation reference

```php
// Sync towns (incremental)
$lastSync = get_option('dexpress_last_towns_sync', '19000101000000');
$response = $this->apiClient->get('/data/towns', ['date' => $lastSync]);
// Process response, upsert into wp_dexpress_towns
update_option('dexpress_last_towns_sync', gmdate('YmdHis'));

// Sync dispensers (full, no date filter)
$response = $this->apiClient->get('/data/dispensers');
// Truncate table, re-insert all rows
```

**Upsert pattern** (INSERT ... ON DUPLICATE KEY UPDATE) for towns/streets — don't truncate, as existing shipments reference these IDs.

**Truncate + re-insert** is acceptable only for dispensers, locations, centres, shops — no foreign keys pointing to them from shipments.