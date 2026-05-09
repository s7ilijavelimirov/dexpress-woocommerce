# Webhook Specification (Notify API)

> D Express pushes real-time shipment status updates to a URL you expose.
> This is an **inbound** endpoint — D Express calls YOU, not the other way around.
> You must register this URL with D Express support before going live.

---

## Overview

D Express supports two variants of the Notify endpoint. **You must implement both** — D Express may call either:

| Variant | Method | Parameters |
|---|---|---|
| Query string | `PUT /api/Notify?cc=...&nID=...&code=...&rID=...&sID=...&dt=...` | All params in URL |
| JSON body | `POST /api/Notify` | Params in JSON body |

Our plugin registers a single WordPress REST endpoint that handles both:

```
PUT  https://{site}/wp-json/dexpress/v1/notify
POST https://{site}/wp-json/dexpress/v1/notify
```

---

## Request parameters

Same fields for both variants (query string or JSON body):

| Field | Type | Required | Description |
|---|---|---|---|
| `cc` | string | Yes | Passcode — mutually agreed secret between you and D Express |
| `nID` | string/integer | Yes | **Unique event ID**. If you receive a `nID` you already processed, return `200 OK` immediately (idempotency) |
| `code` | string | Yes | Package/shipment tracking code in D Express system (e.g. `TT0000000001`) |
| `rID` | string | Yes | Your `ReferenceID` from `addshipment` — use this to match the event to your WC order |
| `sID` | string/integer | Yes | Status ID — look up in `wp_dexpress_status_codes` or `business-logic/status-mapping.md` |
| `dt` | string | Yes | Date and time of event. Format: `yyyyMMddHHmmss` |

**JSON body sample (POST variant):**
```json
{
  "cc": "your-secret-passcode",
  "nID": "12345678",
  "code": "TT0000000001",
  "rID": "ORDER-1234",
  "sID": "1",
  "dt": "20260428143000"
}
```

---

## Response rules

**This is critical — read carefully.**

| Response | When to return | What D Express does |
|---|---|---|
| `200 OK` with empty body `""` | Data saved to DB successfully | D Express marks event as delivered, moves on |
| `200 OK` with `"OK"` | Same as above | Same |
| Anything else | **Only on DB failure** | D Express **retries** the same event after a delay |

**NEVER return an error for logic errors.** If the `sID` is unknown, if `rID` doesn't match any order, if `code` is unrecognized — **still return 200 OK**. Log the issue internally, handle it asynchronously.

**Only return an error if your database is unavailable** and you cannot persist the raw event data.

**After 10 consecutive errors**, D Express pauses the notification service. You must contact D Express support to resume it, and they will resend all undelivered events.

---

## Processing rules

### Rule 1: Insert first, process later

```
Receive event → save raw payload to DB → return 200 OK → process asynchronously
```

Never do business logic in the receiving handler. The handler must be fast and reliable.

### Rule 2: Idempotency

Check `nID` before processing. If already in `wp_dexpress_webhook_logs.notification_id` → return `200 OK`, skip processing.

```php
$existing = $this->webhookLogRepo->findByNotificationId($nID);
if ($existing !== null) {
    return new WP_REST_Response('', 200); // already processed
}
```

### Rule 3: Accept unknown data

If `code` or `rID` doesn't match anything in your DB, log it and return `200 OK`. D Express may send events for test shipments or shipments created outside your plugin.

### Rule 4: Terminal status guard

Once a shipment reaches a terminal status (`Delivered`, `Returned`, `Cancelled`), record subsequent events in history but do not overwrite the current status. See `business-logic/status-mapping.md`.

---

## Plugin webhook URL

The URL the merchant provides to D Express:

```
https://{merchant-domain}/wp-json/dexpress/v1/notify
```

This URL is shown in the plugin's settings page under the "Webhook" tab, along with the passcode (`cc` value) the merchant must give to D Express.

---

## Security

### Passcode (`cc`) validation

The `cc` field is a shared secret between the merchant and D Express. It's set during D Express onboarding and stored in plugin settings.

**Always use constant-time comparison:**

```php
if (!hash_equals($storedPasscode, $receivedCc)) {
    // Return 200 OK anyway — do not reveal that validation failed
    // Log the failed attempt
    $this->logger->warning('Webhook: invalid passcode', [
        'ip' => $_SERVER['REMOTE_ADDR'],
        'nID' => $nID,
    ]);
    return new WP_REST_Response('', 200);
}
```

**Why return 200 on invalid passcode?** Returning 4xx would cause D Express to retry and eventually pause the service. Log the failure, alert the admin, but return 200 to keep D Express happy.

### IP allowlist (optional)

D Express sends notifications from fixed IP addresses. The merchant can optionally configure an IP allowlist in plugin settings. If the request IP is not in the list, log and return 200.

### HTTPS required

The endpoint must be served over HTTPS. D Express will not call plain HTTP endpoints.

---

## WordPress REST API registration

```php
add_action('rest_api_init', function () {
    // PUT variant (query string params)
    register_rest_route('dexpress/v1', '/notify', [
        'methods'             => 'PUT',
        'callback'            => [WebhookController::class, 'handlePut'],
        'permission_callback' => '__return_true', // auth done inside handler
        'args'                => [
            'cc'   => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            'nID'  => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            'code' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            'rID'  => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            'sID'  => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            'dt'   => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
        ],
    ]);

    // POST variant (JSON body)
    register_rest_route('dexpress/v1', '/notify', [
        'methods'             => 'POST',
        'callback'            => [WebhookController::class, 'handlePost'],
        'permission_callback' => '__return_true',
    ]);
});
```

**Note:** `permission_callback` is `__return_true` because authentication is done inside the handler via passcode check — not via WordPress user caps. The endpoint is intentionally public (D Express has no WP credentials).

---

## Handler implementation sketch

```php
final class WebhookController {

    public function handlePut(WP_REST_Request $request): WP_REST_Response {
        return $this->handle(
            cc:   $request->get_param('cc'),
            nID:  $request->get_param('nID'),
            code: $request->get_param('code'),
            rID:  $request->get_param('rID'),
            sID:  $request->get_param('sID'),
            dt:   $request->get_param('dt'),
            raw:  $request->get_params(),
        );
    }

    public function handlePost(WP_REST_Request $request): WP_REST_Response {
        $body = $request->get_json_params();
        return $this->handle(
            cc:   $body['cc']   ?? '',
            nID:  $body['nID']  ?? '',
            code: $body['code'] ?? '',
            rID:  $body['rID']  ?? '',
            sID:  $body['sID']  ?? '',
            dt:   $body['dt']   ?? '',
            raw:  $body,
        );
    }

    private function handle(
        string $cc, string $nID, string $code,
        string $rID, string $sID, string $dt, array $raw
    ): WP_REST_Response {

        // 1. Validate structure
        if (empty($nID) || empty($code) || empty($sID)) {
            $this->logger->warning('Webhook: malformed payload', compact('nID', 'code', 'sID'));
            return new WP_REST_Response('', 200); // still 200
        }

        // 2. Validate passcode
        $storedCc = $this->options->get('webhook.passcode');
        if (!hash_equals($storedCc, $cc)) {
            $this->logger->warning('Webhook: invalid passcode', ['nID' => $nID]);
            return new WP_REST_Response('', 200);
        }

        // 3. Idempotency check
        if ($this->webhookLogRepo->existsByNotificationId($nID)) {
            return new WP_REST_Response('', 200);
        }

        // 4. Persist raw event — THIS IS THE ONLY THING THAT CAN RETURN AN ERROR
        try {
            $logId = $this->webhookLogRepo->save([
                'notification_id' => $nID,
                'package_code'    => $code,
                'reference_id'    => $rID,
                'sid'             => $sID,
                'occurred_at'     => DateTime::createFromFormat('YmdHis', $dt),
                'raw_payload'     => wp_json_encode($raw),
                'processed'       => false,
            ]);
        } catch (DatabaseException $e) {
            $this->logger->error('Webhook: DB save failed', ['error' => $e->getMessage()]);
            return new WP_REST_Response('ERROR', 500); // only case we return error
        }

        // 5. Schedule async processing
        wp_schedule_single_event(time() + 5, 'dexpress_process_webhook', [$logId]);

        // 6. Return OK immediately
        return new WP_REST_Response('', 200);
    }
}
```

---

## Async processing (scheduled event)

The actual business logic runs via `wp_schedule_single_event` 5 seconds after receiving the webhook:

```php
add_action('dexpress_process_webhook', function (int $logId) {
    $processor = Container::get(ProcessWebhookService::class);
    $processor->process($logId);
});
```

`ProcessWebhookService::process()`:
1. Load raw event from `wp_dexpress_webhook_logs`
2. Check if already processed (double-check idempotency)
3. Map `sID` → internal `ShipmentStatus` + `ShipmentStatusGroup`
4. Find shipment by `code` (package tracking code) in `wp_dexpress_packages`
5. Apply status update with terminal-status guard
6. Insert row in `wp_dexpress_shipment_statuses` (history)
7. Add order note
8. Mark log entry as processed
9. Dispatch `StatusUpdated` domain event → email subscriber, etc.

---

## DB schema for webhook logs

```sql
wp_dexpress_webhook_logs
├── id               BIGINT UNSIGNED AUTO_INCREMENT PK
├── notification_id  VARCHAR(64) UNIQUE NOT NULL   -- nID, unique constraint = idempotency
├── package_code     VARCHAR(16) NOT NULL           -- code
├── reference_id     VARCHAR(50)                    -- rID
├── sid              VARCHAR(10) NOT NULL            -- sID (store as string, can be negative)
├── occurred_at      DATETIME NOT NULL              -- parsed from dt
├── received_at      DATETIME NOT NULL              -- when we got the webhook
├── raw_payload      JSON NOT NULL                  -- full original payload
├── processed        TINYINT(1) NOT NULL DEFAULT 0
├── processed_at     DATETIME NULL
└── created_at       DATETIME NOT NULL
```

**Retention:** Delete processed logs older than 30 days via cron job.

---

## `dt` field parsing

```php
$dt = '20260428143000'; // yyyyMMddHHmmss
$occurredAt = DateTime::createFromFormat('YmdHis', $dt, new DateTimeZone('Europe/Belgrade'));
// Store as UTC in DB:
$utc = $occurredAt->setTimezone(new DateTimeZone('UTC'));
```

⚠ D Express sends time in Serbian local time (CET/CEST). Convert to UTC before storing.

---

## Registering your webhook URL with D Express

After plugin activation, the merchant:
1. Goes to plugin Settings → Webhook tab
2. Copies the displayed webhook URL
3. Copies the displayed passcode (`cc`)
4. Sends both to D Express support (`support@dexpress.rs` or their integration contact)
5. D Express configures their system to call that URL with that passcode

There is no self-service registration API — it's a manual step done once per client.