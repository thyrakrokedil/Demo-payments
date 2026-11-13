# MockPay

MockPay is a local, self-hosted mock payment provider REST API. It lets e-commerce
integrations — for example WooCommerce payment gateways — be developed and tested against
a realistic payment lifecycle (create, activate/capture, refund, cancel) without talking to
a real PSP, without card data, and without an SSN in sight. It runs as a single PHP 8.3 /
Slim 4 container backed by SQLite, exposed on port 8888.

## Quick start (Docker)

```bash
docker compose up --build
```

This builds the image, applies pending migrations on boot, and serves the API on
`http://localhost:8888`. Verify it's up:

```bash
curl http://localhost:8888/health
# {"status":"ok"}
```

Default Basic auth credentials are `username` / `password` (override via `AUTH_USERNAME` /
`AUTH_PASSWORD`, see [Configuration](#configuration)).

If your shop plugin (e.g. WooCommerce) is running in another Docker container, point it at
`http://host.docker.internal:8888` instead of `localhost` so it can reach the host-published
port.

Data is persisted to `./data/mockpay.sqlite` on the host (mounted as `/var/data` in the
container), so payments survive container restarts.

## Running without Docker

Requires PHP 8.3+ with the `pdo_sqlite` extension.

```bash
composer install
composer migrate
composer serve
```

`composer serve` starts the PHP built-in server on `0.0.0.0:8888`.

If Composer isn't installed on the host, run it via the official `composer:2` Docker image:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 composer install
```

## Configuration

All settings are provided via environment variables (see `.env.example`).

| Variable | Default | Description |
|---|---|---|
| `AUTH_USERNAME` | `username` | Basic auth username required on `/payments*` |
| `AUTH_PASSWORD` | `password` | Basic auth password required on `/payments*`; also the API key that signs callbacks |
| `PUBLIC_URL` | `http://localhost:8888` | Base URL customers' browsers reach MockPay on, used for `payment_page_url` |
| `CALLBACK_TIMEOUT_SECONDS` | `5` | Timeout for each validation/notification callback to the merchant |
| `DB_PATH` | `/var/data/mockpay.sqlite` (`data/mockpay.sqlite` outside Docker) | Path to the SQLite database file; use `:memory:` for an ephemeral DB |
| `DB_MIGRATE_ON_BOOT` | `true` | Apply pending SQL migrations from `database/migrations` on startup |
| `APP_DEBUG` | `false` | Include exception details in 500 responses |
| `LOG_LEVEL` | `info` | Monolog level: `debug`\|`info`\|`notice`\|`warning`\|`error` |
| `PHP_CLI_SERVER_WORKERS` | `4` | Number of PHP built-in server worker processes |

## Authentication

Every route under `/payments` requires HTTP Basic auth:

```
Authorization: Basic base64(username:password)
```

`GET /health` is public (so Docker's healthcheck doesn't need credentials), and so is the
customer-facing payment page under `/pay/{id}`.

Missing or invalid credentials return 401 with a `WWW-Authenticate` header:

```
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Basic realm="MockPay"
Content-Type: application/problem+json

{
  "type": "https://mockpay.local/problems/unauthorized",
  "title": "Unauthorized",
  "status": 401,
  "detail": "..."
}
```

## Endpoints

| Method & path | Success | Errors |
|---|---|---|
| `GET /health` | 200 `{"status":"ok"}` | — (public) |
| `POST /payments` | 201 + `Location` + payment | 422 |
| `GET /payments?page=&per_page=&status=&merchant_reference=` | 200 `{data:[...], pagination:{page, per_page, total, total_pages}}` | 422 bad params (`per_page` 1..100, default 20) |
| `GET /payments/{id}` | 200 payment | 404 |
| `PATCH /payments/{id}` | 200 payment | 409 unless `created`; 422 |
| `POST /payments/{id}/cancel` | 200 payment | 409 unless `created`/`authorized` |
| `POST /payments/{id}/activate` | 200 payment | 409 unless `created`/`authorized` |
| `POST /payments/{id}/refund` | 200 payment with new `refunds[]` entry | 409 unless `activated`/`partially_refunded`; 422 if amount exceeds remaining |
| `GET /payments/{id}/callbacks` | 200 `{data:[...]}` callback attempts, oldest first | 404 |
| `GET /pay/{id}` | 200 HTML payment page (public) | 404 |
| `POST /pay/{id}/pay\|fail\|cancel` | 303 redirect to the merchant's `success`/`failed`/`cancel` URL (public) | 404; 409 unless `created` |

All requests/responses are JSON, snake_case, amounts in minor units, timestamps ISO 8601 UTC.

## Payment lifecycle

```
created ──pay on payment page──> authorized
   │                                 │
   ├──────────────activate───────────┴──> activated ──refund──> partially_refunded ──refund──> refunded
   │                                          └──refund(full)───────────────────────────────────┘
   └──cancel (from created or authorized)──> cancelled
```

- `update` (PATCH) is only allowed while the payment is `created`.
- `authorized` means the customer completed the payment page (and the merchant's validation
  callback approved it). `cancel` and `activate` work from both `created` and `authorized`, so
  a server-to-server integration that never uses the payment page keeps working unchanged.
- `activate` performs a full capture: `captured_amount` becomes equal to `order_total`.
- `refund` is only allowed from `activated` or `partially_refunded`. Refunds can be partial
  and repeated; the payment becomes `refunded` once `refunded_amount` equals
  `captured_amount`, and `partially_refunded` otherwise.
- Any other transition (e.g. cancelling an activated payment) returns 409 `invalid-state`
  with `action` and `current_status`.
- `PATCH` only works while the payment is `created`. It performs a **shallow merge** of the
  provided top-level keys onto the stored payload (nested objects/arrays such as
  `billing_address` or `order_rows` are replaced whole, not deep-merged), then the merged
  result is validated exactly like a create request.

## Data model

### Create request

| Field | Type | Required | Notes |
|---|---|---|---|
| `currency` | string | yes | ISO 4217, `^[A-Z]{3}$` |
| `merchant_reference` | string\|null | no | ≤255 chars |
| `merchant_reference2` | string\|null | no | ≤255 chars (e.g. WC order id + order number) |
| `order_total` | int | yes | Minor units, strict integer, ≥ 0; must equal sum of `order_rows[].total_amount` |
| `order_vat_amount` | int | yes | Minor units, strict integer, ≥ 0; must equal sum of `order_rows[].vat_amount` |
| `billing_address` | object | yes | See Address below |
| `shipping_address` | object | yes | See Address below |
| `order_rows` | array | yes | Non-empty; see Order row below |
| `merchant_urls` | object\|null | no | See Merchant URLs below |

Amounts are strict JSON integers in minor units — floats and numeric strings are rejected
with 422 (e.g. `249.00` or `"24900"` are invalid, `24900` is valid).

### Address

| Field | Type | Required | Notes |
|---|---|---|---|
| `first_name` | string | yes | 1..255 chars |
| `last_name` | string | yes | 1..255 chars |
| `company` | string\|null | no | |
| `street_address` | string | yes | 1..255 chars |
| `street_address2` | string\|null | no | |
| `postal_code` | string | yes | ≤32 chars |
| `city` | string | yes | 1..255 chars |
| `region` | string\|null | no | |
| `country` | string | yes | alpha-2, `^[A-Z]{2}$` |
| `email` | string | yes | validated via `FILTER_VALIDATE_EMAIL` |
| `phone` | string | yes | ≤50 chars |

Both `billing_address` and `shipping_address` are fully required objects; only the fields
marked optional above may be `null`.

### Order row

| Field | Type | Required | Notes |
|---|---|---|---|
| `type` | string | yes | one of `physical`, `digital`, `shipping`, `fee`, `discount` |
| `sku` | string | yes | 1..255 chars |
| `name` | string | yes | 1..255 chars |
| `reference` | string\|null | no | |
| `quantity` | int | yes | ≥ 1 |
| `unit_amount` | int | yes | Minor units; ≤ 0 when `type` is `discount`, ≥ 0 otherwise |
| `total_amount` | int | yes | Minor units; must equal `unit_amount * quantity` |
| `vat_amount` | int | yes | Minor units (consistency with `vat_rate` is not enforced) |
| `vat_rate` | int | yes | Percent × 100, e.g. `2500` = 25%, range 0..10000 |

### Merchant URLs

| Field | Used for |
|---|---|
| `validation` | Signed POST when the customer clicks Pay; a 2xx answer approves the payment |
| `notification` | Signed POST after activate, cancel and refund |
| `success` | Where the payment page sends the customer after an approved payment |
| `failed` | Where the customer goes after Fail payment, or when validation rejects the payment |
| `cancel` | Where the customer goes after Cancel |

Every field is optional (`null` or absent) and must be an absolute `http`/`https` URL of at
most 2048 characters. Any URL may contain `{{PAYMENT_ID}}`, which is replaced with the payment
id when it is used, e.g. `https://shop.example/checkout?success={{PAYMENT_ID}}`. On PATCH,
`merchant_urls` is replaced whole like any other nested object.

### Example create payload

```json
{
  "currency": "SEK",
  "merchant_reference": "1001",
  "merchant_reference2": "WC-1001",
  "order_total": 24900,
  "order_vat_amount": 4980,
  "billing_address": {
    "first_name": "Anna",
    "last_name": "Andersson",
    "company": null,
    "street_address": "Storgatan 1",
    "street_address2": null,
    "postal_code": "11122",
    "city": "Stockholm",
    "region": null,
    "country": "SE",
    "email": "anna@example.com",
    "phone": "+46701234567"
  },
  "shipping_address": {
    "first_name": "Anna",
    "last_name": "Andersson",
    "company": null,
    "street_address": "Storgatan 1",
    "street_address2": null,
    "postal_code": "11122",
    "city": "Stockholm",
    "region": null,
    "country": "SE",
    "email": "anna@example.com",
    "phone": "+46701234567"
  },
  "order_rows": [
    {
      "type": "physical",
      "sku": "SKU-001",
      "name": "T-shirt",
      "reference": null,
      "quantity": 2,
      "unit_amount": 10000,
      "total_amount": 20000,
      "vat_amount": 4000,
      "vat_rate": 2500
    },
    {
      "type": "shipping",
      "sku": "SHIP",
      "name": "Standard shipping",
      "reference": null,
      "quantity": 1,
      "unit_amount": 4900,
      "total_amount": 4900,
      "vat_amount": 980,
      "vat_rate": 2500
    }
  ]
}
```

### Payment response

Adds to the create request fields: `id`, `status`, `captured_amount`, `refunded_amount`,
`remaining_refundable_amount`, `payment_page_url`, `refunds[]` (`id`, `amount`, `reference`,
`order_rows`, `created_at`), `created_at`, `updated_at`. `merchant_urls` is returned as sent
(templates, not filled in), with absent keys as `null`, or `null` when none were sent.

```json
{
  "id": "pay_a1b2c3d4e5f6g7h8i9j0k1l2",
  "status": "created",
  "currency": "SEK",
  "merchant_reference": "1001",
  "merchant_reference2": "WC-1001",
  "order_total": 24900,
  "order_vat_amount": 4980,
  "captured_amount": 0,
  "refunded_amount": 0,
  "remaining_refundable_amount": 0,
  "billing_address": {
    "first_name": "Anna",
    "last_name": "Andersson",
    "company": null,
    "street_address": "Storgatan 1",
    "street_address2": null,
    "postal_code": "11122",
    "city": "Stockholm",
    "region": null,
    "country": "SE",
    "email": "anna@example.com",
    "phone": "+46701234567"
  },
  "shipping_address": {
    "first_name": "Anna",
    "last_name": "Andersson",
    "company": null,
    "street_address": "Storgatan 1",
    "street_address2": null,
    "postal_code": "11122",
    "city": "Stockholm",
    "region": null,
    "country": "SE",
    "email": "anna@example.com",
    "phone": "+46701234567"
  },
  "order_rows": [
    {
      "type": "physical",
      "sku": "SKU-001",
      "name": "T-shirt",
      "reference": null,
      "quantity": 2,
      "unit_amount": 10000,
      "total_amount": 20000,
      "vat_amount": 4000,
      "vat_rate": 2500
    },
    {
      "type": "shipping",
      "sku": "SHIP",
      "name": "Standard shipping",
      "reference": null,
      "quantity": 1,
      "unit_amount": 4900,
      "total_amount": 4900,
      "vat_amount": 980,
      "vat_rate": 2500
    }
  ],
  "merchant_urls": null,
  "payment_page_url": "http://localhost:8888/pay/pay_a1b2c3d4e5f6g7h8i9j0k1l2",
  "refunds": [],
  "created_at": "2026-09-22T10:00:00+00:00",
  "updated_at": "2026-09-22T10:00:00+00:00"
}
```

`remaining_refundable_amount` is `captured_amount - refunded_amount`. Once a payment is
`activated`, `captured_amount` equals `order_total`.

## Payment page and callbacks

### Payment page

`payment_page_url` (`GET /pay/{id}`) is a public test checkout page showing the order rows
and total with three buttons:

- **Pay**: sends the validation callback (if a `validation` URL is set). On a 2xx answer, or
  when there is no validation URL, the payment becomes `authorized` and the customer is
  redirected (303) to `success`. Otherwise the payment stays `created` and the customer goes to
  `failed`.
- **Fail payment**: redirects to `failed`; the payment is unchanged.
- **Cancel**: redirects to `cancel`; the payment is unchanged (the customer aborted the
  checkout, the payment itself is not cancelled).

Without the matching redirect URL the page shows a message instead. A payment that is no longer
`created` shows its status and no buttons. On the return URL the shop should `GET
/payments/{id}` to confirm the status before trusting the redirect.

### Callbacks

Every callback is a JSON `POST` with these headers:

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `X-MockPay-Event` | the event name |
| `X-MockPay-Signature` | `sha256=` + hex HMAC-SHA256 of the raw body, keyed with the API key (`AUTH_PASSWORD`) |

| Event | Sent to | When |
|---|---|---|
| `payment.validation` | `validation` | The customer clicks Pay; body includes the full `payment` |
| `payment.activated` | `notification` | After activate |
| `payment.cancelled` | `notification` | After cancel |
| `payment.refunded` | `notification` | After each refund; body includes `refund` (`id`, `amount`, `reference`) |

```json
{
  "id": "evt_ltq3v8p03wwwqv96zrsa03r1",
  "event": "payment.refunded",
  "payment_id": "pay_a1b2c3d4e5f6g7h8i9j0k1l2",
  "status": "partially_refunded",
  "refund": { "id": "ref_xr9vc9mjl8ytsqn7zans6w6i", "amount": 4900, "reference": null },
  "created_at": "2026-09-22T10:00:00+00:00"
}
```

Verify the signature over the raw body before decoding it:

```php
$body = file_get_contents('php://input');
$expected = 'sha256=' . hash_hmac('sha256', $body, $apiKey);
$valid = hash_equals($expected, $_SERVER['HTTP_X_MOCKPAY_SIGNATURE'] ?? '');
```

Delivery is synchronous and single-shot: each callback is sent once, during the request that
triggered it, with a `CALLBACK_TIMEOUT_SECONDS` timeout. A failed notification is logged but
never retried and never undoes the state change. Every attempt (event id, URL, response status
or error, duration) is listed by `GET /payments/{id}/callbacks`. Because delivery is
synchronous, a shop that serves only one request at a time cannot answer a callback triggered
by its own request. Use more than one PHP worker in the shop.

## Validation

- `currency` must match `^[A-Z]{3}$`; `merchant_reference`/`merchant_reference2` are optional
  strings (or `null`) up to 255 characters.
- `order_total` and `order_vat_amount` are ints ≥ 0.
- Address fields are required, non-empty strings 1..255 chars (`postal_code` ≤32,
  `phone` ≤50); `email` is validated with `FILTER_VALIDATE_EMAIL`; `country` matches
  `^[A-Z]{2}$`.
- `order_rows` must be non-empty. Per row: `type` must be one of the enum values; `sku`/`name`
  are 1..255 chars; `quantity` is an int ≥ 1; `unit_amount` must be ≤ 0 for `discount` rows
  and ≥ 0 otherwise; `vat_rate` is 0..10000; `unit_amount * quantity` must equal
  `total_amount`.
- Aggregate checks: `sum(order_rows[].total_amount)` must equal `order_total`, and
  `sum(order_rows[].vat_amount)` must equal `order_vat_amount`.
- All amounts must be strict JSON integers (`is_int`); floats and numeric strings fail
  validation. `vat_amount` vs `vat_rate` consistency is not enforced (real store rounding
  varies).
- `merchant_urls` is optional; each URL is optional, at most 2048 characters, and must be an
  absolute `http`/`https` URL once `{{PAYMENT_ID}}` is filled in.
- Unknown JSON fields are silently ignored.
- Error field paths use array/object notation, e.g. `order_rows[1].vat_rate`,
  `shipping_address.email`.
- Refund payload: `amount` (optional, defaults to the remaining refundable amount) must be
  an int ≥ 1; `reference` is an optional string; `order_rows` is optional but if present the
  sum of `total_amount` must equal `amount`. An empty body means "refund everything
  remaining".

### Error format

Errors are returned as `application/problem+json` (RFC 9457), with `type` of the form
`https://mockpay.local/problems/<slug>`.

| Status | Slug | Extra fields |
|---|---|---|
| 400 | `malformed-json` | — |
| 401 | `unauthorized` | `WWW-Authenticate: Basic realm="MockPay"` header |
| 404 | `not-found` | — |
| 405 | `method-not-allowed` | — |
| 409 | `invalid-state` | `action`, `current_status` |
| 422 | `validation-error` | `errors[]` (`field`, `message`) |
| 500 | `internal-error` | `detail` only when `APP_DEBUG=true` |

Example 422 body:

```json
{
  "type": "https://mockpay.local/problems/validation-error",
  "title": "Validation failed",
  "status": 422,
  "detail": "The request contains 2 invalid fields.",
  "errors": [
    { "field": "order_total", "message": "Must equal the sum of order_rows.total_amount (27500)." },
    { "field": "order_rows[2].total_amount", "message": "Must equal unit_amount * quantity (-2400)." }
  ]
}
```

## curl examples

All examples assume `curl -u username:password -H 'Content-Type: application/json'` against
`http://localhost:8888`.

**Health check**

```bash
curl http://localhost:8888/health
```

**Create a payment**

```bash
cat > payload.json <<'EOF'
{
  "currency": "SEK",
  "merchant_reference": "1001",
  "merchant_reference2": "WC-1001",
  "order_total": 24900,
  "order_vat_amount": 4980,
  "billing_address": {
    "first_name": "Anna",
    "last_name": "Andersson",
    "company": null,
    "street_address": "Storgatan 1",
    "street_address2": null,
    "postal_code": "11122",
    "city": "Stockholm",
    "region": null,
    "country": "SE",
    "email": "anna@example.com",
    "phone": "+46701234567"
  },
  "shipping_address": {
    "first_name": "Anna",
    "last_name": "Andersson",
    "company": null,
    "street_address": "Storgatan 1",
    "street_address2": null,
    "postal_code": "11122",
    "city": "Stockholm",
    "region": null,
    "country": "SE",
    "email": "anna@example.com",
    "phone": "+46701234567"
  },
  "order_rows": [
    {
      "type": "physical",
      "sku": "SKU-001",
      "name": "T-shirt",
      "reference": null,
      "quantity": 2,
      "unit_amount": 10000,
      "total_amount": 20000,
      "vat_amount": 4000,
      "vat_rate": 2500
    },
    {
      "type": "shipping",
      "sku": "SHIP",
      "name": "Standard shipping",
      "reference": null,
      "quantity": 1,
      "unit_amount": 4900,
      "total_amount": 4900,
      "vat_amount": 980,
      "vat_rate": 2500
    }
  ]
}
EOF

curl -u username:password -H 'Content-Type: application/json' \
  -X POST http://localhost:8888/payments -d @payload.json
# 201 Created, Location: /payments/pay_...
```

**401 (no credentials)**

```bash
curl -H 'Content-Type: application/json' \
  -X POST http://localhost:8888/payments -d @payload.json
# 401 Unauthorized, WWW-Authenticate: Basic realm="MockPay"
```

**422 (wrong order_total)**

```bash
curl -u username:password -H 'Content-Type: application/json' \
  -X POST http://localhost:8888/payments \
  -d '{"currency":"SEK","order_total":99999,"order_vat_amount":4980,"billing_address":{},"shipping_address":{},"order_rows":[]}'
# 422 validation-error
```

**Get a payment**

```bash
curl -u username:password http://localhost:8888/payments/pay_a1b2c3d4e5f6g7h8i9j0k1l2
```

**List payments (pagination + filters)**

```bash
curl -u username:password \
  'http://localhost:8888/payments?page=1&per_page=20&status=activated&merchant_reference=1001'
```

**Update a payment (PATCH, only while `created`)**

```bash
curl -u username:password -H 'Content-Type: application/json' \
  -X PATCH http://localhost:8888/payments/pay_a1b2c3d4e5f6g7h8i9j0k1l2 \
  -d '{"merchant_reference":"1002"}'
```

**Cancel a payment**

```bash
curl -u username:password -X POST \
  http://localhost:8888/payments/pay_a1b2c3d4e5f6g7h8i9j0k1l2/cancel
```

**Activate a payment (full capture)**

```bash
curl -u username:password -X POST \
  http://localhost:8888/payments/pay_a1b2c3d4e5f6g7h8i9j0k1l2/activate
```

**Partial refund (amount + reference)**

```bash
curl -u username:password -H 'Content-Type: application/json' \
  -X POST http://localhost:8888/payments/pay_a1b2c3d4e5f6g7h8i9j0k1l2/refund \
  -d '{"amount":10000,"reference":"Return of 1 item"}'
```

**Refund with order_rows**

```bash
curl -u username:password -H 'Content-Type: application/json' \
  -X POST http://localhost:8888/payments/pay_a1b2c3d4e5f6g7h8i9j0k1l2/refund \
  -d '{"order_rows":[{"type":"physical","sku":"SKU-001","name":"T-shirt","reference":null,"quantity":1,"unit_amount":10000,"total_amount":10000,"vat_amount":2000,"vat_rate":2500}]}'
```

**Full refund of remaining amount (empty body)**

```bash
curl -u username:password -H 'Content-Type: application/json' \
  -X POST http://localhost:8888/payments/pay_a1b2c3d4e5f6g7h8i9j0k1l2/refund \
  -d '{}'
```

**409 example (cancel after activate)**

```bash
curl -u username:password -X POST \
  http://localhost:8888/payments/pay_a1b2c3d4e5f6g7h8i9j0k1l2/cancel
# 409 invalid-state: {"action":"cancel","current_status":"activated"}
```

## Development

```bash
composer test      # PHPUnit (unit + functional)
composer cs         # php-cs-fixer, dry-run
composer cs-fix      # php-cs-fixer, apply fixes
composer stan       # PHPStan (level 8)
```

Project layout (under `src/`):

```
src/
  Domain/          aggregate, value objects, enums, exceptions, repository/clock/id interfaces
  Application/      PaymentService (one method per use case)
  Validation/      payload validators, field rules, validation exceptions
  Infrastructure/  PDO/SQLite persistence, migrator, clock, id generator
  Http/            actions, middleware, JSON responder, presenter, error handler
tests/
  Unit/            Domain, Validation, Infrastructure
  Functional/      one class per endpoint, plus Health/BasicAuth/ErrorHandling
```

Tests run against an in-memory SQLite database (`:memory:`), so `composer test` needs no
setup and leaves no files behind.

## License

MIT
