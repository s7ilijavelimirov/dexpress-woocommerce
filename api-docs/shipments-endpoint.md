# Shipment Endpoints

> Endpoints for creating shipments and validating addresses before creation.
> Base URL: `https://usersupport.dexpress.rs/ExternalApi`
> Auth: HTTP Basic Auth — see `authentication.md`

---

## POST /data/addshipment

Creates a shipment in D Express system. **Call this only after the package is physically labeled and ready for courier pickup.**

**URL:** `POST /data/addshipment`  
**Content-Type:** `application/json`

### Response

Returns a plain string:
- `"OK"` — production, shipment accepted
- `"TEST"` — test environment, shipment accepted
- Any other string — error description (validation failure or API error)

**There is no JSON response object.** Check if response body equals `"OK"` or `"TEST"` (trim quotes). Anything else is an error message.

```php
$body = wp_remote_retrieve_body($response);
$body = trim($body, '"'); // remove JSON string quotes
if (!in_array($body, ['OK', 'TEST'], true)) {
    throw new ApiException('D Express addshipment error: ' . $body);
}
```

---

### Request fields

#### Sender (Customer) — `C*` fields

| Field | Type | Required | Validation | Notes |
|---|---|---|---|---|
| `CClientID` | string | **Yes** | `^UK[0-9]{5}$` | Merchant's D Express contract ID (e.g. `UK16967`) |
| `CName` | string | **Yes** | Serbian Latin, max 50 | Sender business name — use a real name, not "Store 54" |
| `CAddress` | string | **Yes** | Serbian Latin, max 50 | Street name only, no number |
| `CAddressNum` | string | **Yes** | See address number regex, max 10 | Street number (e.g. `1`, `12a`, `bb`) |
| `CTownID` | integer | **Yes** | 100000–10000000 | From `wp_dexpress_towns.id` |
| `CCName` | string | No | Serbian Latin, max 50 | Sender contact name |
| `CCPhone` | string | No | `^(381[1-9][0-9]{7,8}\|38167[0-9]{6,8})$` | Sender contact phone, canonical format |

#### Pickup location — `Pu*` fields

> The pickup location is where the courier will collect the package. Often same as sender, but can differ (e.g. warehouse different from billing address).

| Field | Type | Required | Validation | Notes |
|---|---|---|---|---|
| `PuClientID` | string | No | `^UK[0-9]{5}$` | Pickup contract ID — usually same as `CClientID` |
| `PuName` | string | **Yes** | Serbian Latin, max 50 | Pickup location name |
| `PuAddress` | string | **Yes** | Serbian Latin, max 50 | Street name only |
| `PuAddressNum` | string | **Yes** | Address number regex, max 10 | Street number |
| `PuAddressDesc` | string | No | max 50 | Additional directions (floor, entrance, etc.) |
| `PuTownID` | integer | **Yes** | 100000–10000000 | From `wp_dexpress_towns.id` |
| `PuCName` | string | No | Serbian Latin, max 30 | Pickup contact name |
| `PuCPhone` | string | No | Phone regex | Pickup contact phone |

#### Receiver — `R*` fields

| Field | Type | Required | Validation | Notes |
|---|---|---|---|---|
| `RClientID` | string | No | `^UK[0-9]{5}$` | Only if receiver is also a D Express client |
| `RName` | string | **Yes** | Serbian Latin, max 50 | Recipient full name |
| `RAddress` | string | **Yes** | Serbian Latin, max 50 | Street name only |
| `RAddressNum` | string | **Yes** | Address number regex, max 10 | Street number |
| `RAddressDesc` | string | No | max 50 | Additional delivery instructions |
| `RTownID` | integer | **Yes** | 100000–10000000 | From `wp_dexpress_towns.id` |
| `RCName` | string | No | Serbian Latin, max 50 | Recipient contact name |
| `RCPhone` | string | No | Phone regex | Recipient phone — **required for dispenser delivery** |

#### Shipment details

| Field | Type | Required | Validation | Notes |
|---|---|---|---|---|
| `DlTypeID` | integer | **Yes** | See `ShipmentType` enum | `1` = Urgent (same day), `2` = Regular |
| `PaymentBy` | integer | **Yes** | See `PaymentBy` enum | `0` = Sender, `1` = Pickup, `2` = Recipient |
| `PaymentType` | integer | **Yes** | See `PaymentType` enum | `1` = Cash, `2` = Invoice |
| `Value` | integer | **Yes** | 0–1,000,000,000 | Shipment declared value in **para** (100 para = 1 RSD) |
| `Content` | string | **Yes** | Serbian Latin, max 50 | Package contents description (e.g. "tekstil", "elektronika") |
| `Mass` | integer | **Yes** | 0–10,000,000 | **Total** shipment mass in **grams** (sum of all packages) |
| `Note` | string | No | max 150 | Additional delivery instructions |
| `ReferenceID` | string | **Yes** | max 50, unique | Your internal ID for this shipment — use WC order ID + suffix for multi-shipment |
| `ReturnDoc` | integer | **Yes** | See `ReturnDoc` enum | `0` = No return, `1` = Return documents, `3` = POD |
| `SelfDropOff` | integer | No | 0 or 1 | `1` = sender drops package at D Express location themselves |

#### COD (Cash on Delivery) — `BuyOut*` fields

| Field | Type | Required | Validation | Notes |
|---|---|---|---|---|
| `BuyOut` | integer | No | 0–1,000,000,000 | COD amount in **para**. `0` = no COD |
| `BuyOutFor` | integer | No | See `BuyOutFor` enum | `0` = Sender gets COD, `1` = Pickup location gets COD |
| `BuyOutAccount` | string | No | max 20 | Bank account for COD transfer (Serbian format `XXX-YYYYYYYY-XX`) |

#### Dispenser (parcel locker) delivery

| Field | Type | Required | Notes |
|---|---|---|---|
| `DispenserID` | integer | No | ID from `wp_dexpress_dispensers.id`. Set to trigger locker delivery. |

**Dispenser constraints (all must be met):**
- Exactly 1 package in `PackageList`
- `BuyOut` < 20,000,000 para (< 200,000 RSD)
- `RCPhone` must be a mobile number (`381 6X ...`)
- `ReturnDoc` must be `0` (no return documents)
- Package mass < 20,000 g (20 kg)
- Package dimensions < 470 × 440 × 440 mm

#### Package list

| Field | Type | Required | Notes |
|---|---|---|---|
| `PackageList` | array | No | Array of `Package` objects. If omitted, D Express assumes 1 package. |

**Package object fields:**

| Field | Type | Required | Validation | Notes |
|---|---|---|---|---|
| `Code` | string | **Yes** | `^[A-Z]{2}[0-9]{10}$` | Package tracking code — generated from your assigned range |
| `DimX` | integer | No | — | Width in mm |
| `DimY` | integer | No | — | Length in mm |
| `DimZ` | integer | No | — | Height in mm |
| `Mass` | integer | No | 0–34,000 | Package mass in grams. If omitted, D Express uses `Shipment.Mass / package_count` |
| `VMass` | integer | No | — | Volumetric mass |
| `ReferenceID` | string | No | — | Package-level reference (optional) |

---

### Enums

#### ShipmentType (`DlTypeID`)
| Value | Name | Description |
|---|---|---|
| `1` | Urgent | Same-day delivery |
| `2` | Regular | Standard delivery (default for WooCommerce) |

#### PaymentBy
| Value | Name | Description |
|---|---|---|
| `0` | Sender | Sender pays shipping cost (most common for WC stores) |
| `1` | PickUp | Pickup location pays |
| `2` | Recipient | Recipient pays on delivery |

#### PaymentType
| Value | Name | Description |
|---|---|---|
| `1` | Cash | Pay by cash |
| `2` | Invoice | Pay by invoice (most common for business clients) |

#### ReturnDoc
| Value | Name | Description |
|---|---|---|
| `0` | NoReturn | No return shipment (default) |
| `1` | ReturnDocuments | Courier returns signed documents to sender |
| `2` | ReturnCash | **DEPRECATED** — use `BuyOut` instead |
| `3` | ReturnPOD | Proof of delivery — courier returns POD document |

#### BuyOutFor
| Value | Name | Description |
|---|---|---|
| `0` | Sender | COD payment goes to sender (default) |
| `1` | PickUp | COD payment goes to pickup location |

---

### Address number regex

Used on `CAddressNum`, `PuAddressNum`, `RAddressNum`:

```
^((bb|BB|b\.b\.|B\.B\.)(\/[-a-zžćčđšA-ZĐŠĆŽČ_0-9]+)*|(\d(-\d){0,1}[a-zžćčđšA-ZĐŠĆŽČ_0-9]{0,2})+(\/[-a-zžćčđšA-ZĐŠĆŽČ_0-9]+)*)$
```

Valid: `1`, `12a`, `34/4`, `bb`, `BB`, `5-7`, `bb/12`  
Invalid: `15.`, `15 a`, empty

### Phone regex

Used on `CCPhone`, `PuCPhone`, `RCPhone`:

```
^(381[1-9][0-9]{7,8}|38167[0-9]{6,8})$
```

No `+`, no spaces. Canonical form: `381XXXXXXXXX`. See `phone-validation-serbian.md`.

---

### Full request example

```json
{
  "CClientID": "UK16967",
  "CName": "Moja Firma",
  "CAddress": "Beogradska",
  "CAddressNum": "12",
  "CTownID": 703869,
  "CCName": "Petar Petrović",
  "CCPhone": "381641234567",

  "PuClientID": "UK16967",
  "PuName": "Moja Firma - Magacin",
  "PuAddress": "Industrijska",
  "PuAddressNum": "5",
  "PuTownID": 703869,
  "PuCName": "Petar Petrović",
  "PuCPhone": "381641234567",

  "RName": "Ivan Ivanović",
  "RAddress": "Kralja Petra",
  "RAddressNum": "15",
  "RAddressDesc": "II sprat stan 5",
  "RTownID": 791032,
  "RCName": "Ivan",
  "RCPhone": "381641234567",

  "DlTypeID": 2,
  "PaymentBy": 0,
  "PaymentType": 2,
  "BuyOut": 500000,
  "BuyOutFor": 0,
  "BuyOutAccount": "123-456789012-34",
  "Value": 500000,
  "Content": "tekstil",
  "Mass": 2200,
  "Note": "",
  "ReferenceID": "ORDER-1234",
  "ReturnDoc": 0,
  "PackageList": [
    {
      "Code": "TT0000000001",
      "Mass": 2200,
      "DimX": 300,
      "DimY": 200,
      "DimZ": 150
    }
  ]
}
```

---

### PHP implementation sketch

```php
final class AddShipmentPayloadBuilder {
    public function build(
        Shipment $shipment,
        SenderLocation $sender,
        WC_Order $order,
        string $clientId
    ): array {
        return [
            // Sender (from plugin SenderLocation settings)
            'CClientID'    => $clientId,
            'CName'        => $sender->name,
            'CAddress'     => $sender->streetName,
            'CAddressNum'  => $sender->streetNumber,
            'CTownID'      => $sender->townId,
            'CCName'       => $sender->contactName,
            'CCPhone'      => $sender->contactPhone,

            // Pickup = same as sender in most cases
            'PuClientID'   => $clientId,
            'PuName'       => $sender->name,
            'PuAddress'    => $sender->streetName,
            'PuAddressNum' => $sender->streetNumber,
            'PuTownID'     => $sender->townId,
            'PuCName'      => $sender->contactName,
            'PuCPhone'     => $sender->contactPhone,

            // Receiver (from WC order billing)
            'RName'        => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'RAddress'     => $order->get_meta('_billing_street_name'),
            'RAddressNum'  => $order->get_meta('_billing_street_number'),
            'RAddressDesc' => $order->get_meta('_billing_address_desc') ?: '',
            'RTownID'      => (int) $order->get_meta('_billing_town_id'),
            'RCPhone'      => $order->get_meta('_billing_phone_api_format'),

            // Shipment
            'DlTypeID'     => 2, // Regular
            'PaymentBy'    => 0, // Sender pays
            'PaymentType'  => 2, // Invoice
            'BuyOut'       => $shipment->codAmountPara,
            'BuyOutFor'    => 0,
            'BuyOutAccount'=> $shipment->codBankAccount ?? '',
            'Value'        => $shipment->valuePara,
            'Content'      => $shipment->contentDescription,
            'Mass'         => $shipment->totalMassGrams,
            'Note'         => $shipment->note ?? '',
            'ReferenceID'  => $shipment->referenceId,
            'ReturnDoc'    => 0,

            'PackageList'  => array_map(fn($pkg) => [
                'Code' => $pkg->code,
                'Mass' => $pkg->massGrams,
                'DimX' => $pkg->dimX,
                'DimY' => $pkg->dimY,
                'DimZ' => $pkg->dimZ,
            ], $shipment->packages),
        ];
    }
}
```

---

## POST /data/checkaddress

Optional pre-flight check — validates a recipient address before creating a shipment.

**URL:** `POST /data/checkaddress`  
**Content-Type:** `application/json`

### Request fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `RName` | string | **Yes** | Recipient name |
| `RAddress` | string | **Yes** | Street name |
| `RAddressNum` | string | **Yes** | Street number |
| `RAddressDesc` | string | No | Additional description |
| `RTownID` | integer | **Yes** | Town ID |
| `RCName` | string | No | Contact name |
| `RCPhone` | string | No | Contact phone |

### Response

Same as `addshipment` — plain string: `"OK"` / `"TEST"` / error message.

### When to call

Configurable via plugin setting `dexpress_validate_address` (default: **off**).

When enabled:
- Optionally on checkout submit (warns customer before order is placed)
- Optionally before "Create shipment" in admin metabox (warns admin)

**Do not block shipment creation** on a failed address check — D Express's address database may lag. Log the warning but allow the admin to proceed.

```php
try {
    $result = $this->apiClient->checkAddress($addressPayload);
    if (!in_array($result, ['OK', 'TEST'])) {
        $this->logger->warning('Address validation warning', [
            'order_id' => $orderId,
            'result'   => $result,
        ]);
        // Show admin notice, do not block
    }
} catch (ApiException $e) {
    // Log and continue — don't block shipment creation
    $this->logger->error('checkaddress API error', ['error' => $e->getMessage()]);
}
```