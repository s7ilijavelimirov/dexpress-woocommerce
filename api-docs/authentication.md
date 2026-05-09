# Authentication

> How the plugin authenticates with the D Express External API.

---

## Method

**HTTP Basic Authentication** — every request includes a `Authorization: Basic {base64(username:password)}` header.

There is no token, no OAuth, no session. Every API call sends credentials directly.

```php
$credentials = base64_encode($username . ':' . $password);
$headers = [
    'Authorization' => 'Basic ' . $credentials,
    'Content-Type'  => 'application/json',
    'Accept'        => 'application/json',
];
```

In WordPress, via `wp_remote_get/post`:

```php
$response = wp_remote_post($url, [
    'headers' => [
        'Authorization' => 'Basic ' . base64_encode($user . ':' . $pass),
        'Content-Type'  => 'application/json',
    ],
    'body'    => wp_json_encode($payload),
    'timeout' => 30,
]);
```

---

## Environments

D Express has **one endpoint** for both test and production. The environment is determined by the credentials, not the URL.

| Setting | Test | Production |
|---|---|---|
| Base URL | `https://usersupport.dexpress.rs/ExternalApi` | Same |
| Username | Assigned by D Express (test account) | Assigned by D Express (prod account) |
| Password | Assigned by D Express | Same password |
| CClientID | Test client ID (e.g. `UK16967`) | Production client ID (different value) |
| Shipment code prefix | Test prefix (e.g. `TT`) | Production prefix (assigned by D Express) |
| Shipment code range | Limited (e.g. 1–99 for test) | Full range (assigned by D Express) |

**Key rule**: the plugin settings must have separate fields for test vs production credentials, or a single set with an environment toggle. The toggle changes which `CClientID` and code prefix/range are used — the base URL never changes.

---

## What the client (merchant) configures

Every merchant who purchases the plugin gets their own credentials from D Express. The plugin admin UI must collect:

| Setting key | Label | Notes |
|---|---|---|
| `api.username` | API Username | Provided by D Express |
| `api.password` | API Password | Stored encrypted — never plain text |
| `api.client_id` | Client ID (CClientID) | Numeric ID, format `UKxxxxx` |
| `api.environment` | Environment | `test` or `production` |
| `shipment.prefix` | Shipment code prefix | 2 uppercase letters, e.g. `TT` (test) or production prefix |
| `shipment.range_start` | Code range start | Integer, e.g. `1` |
| `shipment.range_end` | Code range end | Integer, e.g. `99` (test) or assigned range |

**Never hardcode any of these values in plugin code.**

---

## Credential storage

Passwords are encrypted at rest using AES-256 with a key derived from WordPress `AUTH_KEY` (from `wp-config.php`).

```php
// Save
$encrypted = EncryptedString::encrypt($rawPassword);
$this->options->set('api.password', $encrypted->toString());

// Load
$encrypted = EncryptedString::fromString($this->options->get('api.password'));
$rawPassword = $encrypted->decrypt();
```

If `AUTH_KEY` changes (e.g. after a security incident), the stored password must be re-entered. The settings page shows a warning if decryption fails.

**Never** log the raw password. Mask in UI: show `••••••••` with a "Show" toggle.

---

## Credential validation

On settings save, the plugin performs a test API call to verify credentials before storing:

```php
// Use a lightweight endpoint for validation
$response = $this->apiClient->get('/data/statuses');
if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
    throw new ValidationException('Invalid API credentials — please check username and password.');
}
```

This prevents saving wrong credentials that would break shipment creation silently.

---

## Shipment code format

D Express assigns each client a **prefix** and a **numeric range**. The plugin generates codes from this range.

**Format:** `{PREFIX}{NUMBER_PADDED_TO_10_DIGITS}`

Examples:
- Test: `TT0000000001`, `TT0000000002`, ..., `TT0000000099`
- Production: `AB0000001000`, `AB0000001001`, ... (prefix and range assigned by D Express)

**Code allocation** is handled atomically in the database to prevent duplicate codes across concurrent requests. See `database-patterns.md` for the `SELECT ... FOR UPDATE` pattern.

**Before going live**, the merchant must:
1. Request a production prefix and range from D Express support.
2. Enter production credentials + prefix + range in plugin settings.
3. Switch environment toggle to `production`.

---

## Integration workflow (D Express onboarding steps)

D Express provides this workflow for new integrations:

1. **Create a reporting service** — the client exposes a webhook endpoint (PUT/POST `api/Notify`) that D Express calls with real-time status updates. The merchant provides this URL to D Express. In our plugin: `https://{site}/wp-json/dexpress/v1/notify`.

2. **Download reference data** — sync towns, streets, dispensers, statuses from `GET /data/*` endpoints. See `index-endpoints.md`.

3. **Create shipment labels** — generate PDF labels for packages. Labels must be printed and attached before courier pickup. See `10-labels-pdf.md`.

4. **Send shipment data** — call `POST /data/addshipment` after the package is labeled and ready for pickup. See `shipments-endpoint.md`.

---

## Error responses for auth failures

| HTTP Status | Meaning |
|---|---|
| `401 Unauthorized` | Wrong username or password |
| `403 Forbidden` | Valid credentials but insufficient permissions |

Both should be caught and surfaced as admin notices, not silently swallowed.

```php
$code = wp_remote_retrieve_response_code($response);
if ($code === 401) {
    throw new ApiUnauthorizedException('Invalid D Express credentials.');
}
if ($code === 403) {
    throw new ApiException('D Express API access forbidden — contact D Express support.');
}
```

---

## References

- D Express Integration Guide: `https://usersupport.dexpress.rs/ExternalApi/Integration`
- D Express API Help: `https://usersupport.dexpress.rs/ExternalApi/Help`
- Credential encryption implementation: `src/Infrastructure/Options/EncryptedString.php`
- Settings UI: `src/Presentation/Admin/Pages/SettingsPage.php` → API tab