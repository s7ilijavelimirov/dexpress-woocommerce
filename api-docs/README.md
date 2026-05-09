# D Express API Documentation

> Complete reference for the D Express External API used by this plugin.
> Base URL: `https://usersupport.dexpress.rs/ExternalApi`
> Auth: HTTP Basic Auth on every request — see `authentication.md`

---

## Files in this folder

| File | What it covers |
|---|---|
| `authentication.md` | Basic auth, credentials, environments, shipment code format |
| `shipments-endpoint.md` | `POST /data/addshipment` + `POST /data/checkaddress` |
| `webhook-spec.md` | `PUT/POST /api/Notify` — inbound status notifications from D Express |
| `index-endpoints.md` | All GET reference data: towns, streets, municipalities, dispensers, locations, centres, shops, statuses |
| `payments-endpoint.md` | `GET /data/viewpayments` — COD reconciliation |

---

## Official integration workflow (D Express prescribed order)

**Step 1 — Webhook first**
Implement `PUT/POST /api/Notify`, host it publicly, give the URL + passcode to D Express support.
→ See `webhook-spec.md`

**Step 2 — Sync reference data**
Download towns, streets, municipalities, dispensers, statuses. Keep them updated via cron.
→ See `index-endpoints.md`

**Step 3 — Create labels**
Generate PDF labels with Code-128 barcode for each package before calling the API.
Label format: Code-128 only, compressed digits (start Code A, switch to Code C for digits).
→ See plugin docs `10-labels-pdf.md`

**Step 4 — Send shipment data**
After packages are labeled and ready for pickup, call `POST /data/addshipment`.
→ See `shipments-endpoint.md`

---

## Key D Express design notes

**Address UX — street first, then town:**
D Express explicitly recommends asking for the street first, then filtering towns by that street. Do NOT ask for town first. This reduces address errors significantly. See `business-logic/serbian-address-handling.md`.

**Allow manual street entry:**
D Express acknowledges their street database is incomplete. The UI must allow a customer to type a street name even if it's not in the autocomplete list. The town must still be selected from the dropdown (TownID is required for the API).

**First sync date:**
When calling towns/streets/municipalities for the first time, use `date=20000101000000`. Every subsequent call uses the timestamp of the last sync.

**Shipment codes are pre-allocated:**
You generate package codes from your assigned prefix + range before calling the API. The codes go on the physical label and in the `PackageList[].Code` field. D Express does not generate codes for you.

**Response format for addshipment:**
Returns a plain string `"OK"` (production) or `"TEST"` (test environment) on success. Any other string is an error message. There is no JSON response object.

**Webhook response rule:**
Return `200 OK` with empty body for success AND for logic errors. Only return an error if your database is unavailable. See `webhook-spec.md` for details.

---

## Changelog (from D Express docs)

| Date | Change |
|---|---|
| 2023-08-23 | `SelfDropOff` field added to `addshipment` |
| 2023-08-22 | `Ret` field set added to `addshipment` (return to different address than pickup) |
| 2023-03-15 | `checkaddress` method added |

---

## External links

- Integration guide: `https://usersupport.dexpress.rs/ExternalApi/Integration`
- API Help (requires auth): `https://usersupport.dexpress.rs/ExternalApi/Help`
- Notification API guide: `https://usersupport.dexpress.rs/ExternalApi/Integration/IntegrateNotify`
- Data API guide: `https://usersupport.dexpress.rs/ExternalApi/Integration/IntegrateData`
- Address UX guide (Serbian): `https://usersupport.dexpress.rs/ExternalApi/Integration/IntegrateAddress`
- Shipment + Label guide: `https://usersupport.dexpress.rs/ExternalApi/Integration/IntegrateShipment`
- Label examples: `https://usersupport.dexpress.rs/ExternalApi/Integration/IntegrateLabels`