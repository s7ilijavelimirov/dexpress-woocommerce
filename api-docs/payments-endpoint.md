# Payments Endpoint

> COD (Cash on Delivery) reconciliation endpoint.
> Base URL: `https://usersupport.dexpress.rs/ExternalApi`
> Auth: HTTP Basic Auth — see `authentication.md`

---

## GET /data/viewpayments

Retrieve COD payment records by bank payment reference number. Used for reconciling which shipments have been paid out by D Express.

**URL:** `GET /data/viewpayments?PaymentReference={PaymentReference}`

### Query parameter

| Name | Type | Required | Notes |
|---|---|---|---|
| `PaymentReference` | string | **Yes** | Reference from bank statement — provided by D Express on the payment transfer |

### Response

Collection of `Payment` objects (JSON array or CSV depending on `Accept` header).

| Field | Type | Description |
|---|---|---|
| `ShCode` | string | Package/shipment tracking code |
| `Buyout` | integer | COD amount in **para** (100 para = 1 RSD) |
| `ReferenceID` | string | Your `ReferenceID` from `addshipment` — use this to match to your WC order |
| `RName` | string | Recipient name |
| `RAddress` | string | Recipient address |
| `RTown` | string | Recipient town |
| `PaymentDate` | string | Date payment was made. Format: `yyyyMMdd` |

**Sample response:**
```json
[
  {
    "ShCode": "TT0000000001",
    "Buyout": 500000,
    "ReferenceID": "ORDER-1234",
    "RName": "Ivan Ivanović",
    "RAddress": "Kralja Petra 15",
    "RTown": "Beograd",
    "PaymentDate": "20260428"
  }
]
```

---

## Implementation notes

**When to use:** This endpoint is for merchants who use COD (`BuyOut > 0`). D Express collects cash from the recipient and transfers it to the merchant's bank account periodically. This endpoint lets you verify which orders have been paid.

**Matching to WC orders:** Use `ReferenceID` to match back to your WC order. This is why `ReferenceID` in `addshipment` must be unique and meaningful (e.g. `ORDER-1234`).

**`Buyout` is in para:** Divide by 100 to get RSD. Same convention as `BuyOut` in `addshipment`.

**`PaymentDate` format:** `yyyyMMdd` — parse with `DateTime::createFromFormat('Ymd', $date)`.

**Plugin feature scope:** The v2 plugin stores payment records in `wp_dexpress_payments` table and allows the admin to look up COD reconciliation. This is a SHOULD feature — not blocking for initial release.

**DB table schema (preliminary):**
```sql
wp_dexpress_payments
├── id (PK)
├── shipment_code (VARCHAR 16)      -- ShCode
├── order_reference_id (VARCHAR 50) -- ReferenceID → match to WC order
├── buyout_para (BIGINT UNSIGNED)   -- Buyout in para
├── recipient_name (VARCHAR 100)
├── recipient_address (VARCHAR 150)
├── recipient_town (VARCHAR 100)
├── payment_date (DATE)             -- parsed from yyyyMMdd
├── payment_reference (VARCHAR 100) -- the PaymentReference used to query
└── created_at (DATETIME)
```