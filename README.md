# Shipit Return and Exchange provider RPC reference

## What this is for

Shipit hosts and operates the Return and Exchange portal, its settings, and the
customer-facing return workflow. This RPC protocol is the backend connection
between that Shipit product and a commerce system that Shipit does not natively
support.

Use it when a merchant, ecommerce platform, ERP, or integration partner needs
Shipit Return and Exchange to read and write data in its own system. The
integration works as follows:

1. The customer uses the Shipit-hosted Return and Exchange portal.
2. Shipit sends authenticated JSON-RPC requests to a public HTTPS endpoint
   operated by the merchant or integration provider.
3. That provider translates the requests into calls to its commerce platform,
   ERP, or in-house software.
4. The provider returns order, product, return, exchange, refund, and related
   data to Shipit using the structures defined in
   [`PROTOCOL.md`](PROTOCOL.md).

The provider can be implemented in any language or framework. Shipit only needs
its endpoint URL, shop identifier, and shared secret. This Laravel application
is a runnable reference implementation and possible starting point; it is not a
required runtime or SDK.

This is a standalone provider contract, not part of Shipit's general API.
Developers building a provider need this repository and its
[`PROTOCOL.md`](PROTOCOL.md); they do not need separate Shipit API
documentation. Anyone who is not building or operating a provider does not
need this repository.

### What this is not

- It is not a white-label or standalone return portal. The portal, settings,
  branding, policies, and workflow remain in Shipit.
- It does not connect Shipit to an arbitrary ecommerce platform merely by being
  deployed. The provider must implement the platform-specific reads and writes.
- It is not needed for an ecommerce platform already supported directly by
  Shipit, unless a custom integration is intentionally required.
- The included fake catalog and write responses are demonstrations, not a
  production commerce backend.

This repository is primarily for:

- merchants with custom commerce or in-house systems;
- platform vendors that want Shipit Return and Exchange to work with their
  platform;
- integration partners building and hosting a provider for customers; and
- Shipit developers implementing another commerce integration against the same
  contract.

## What the reference application provides

The application provides:

- one JSON-RPC 2.0 endpoint at `POST /rpc`;
- the 12 methods currently called by Shipit API's product-return adapter;
- HMAC-SHA256 request authentication, timestamp validation, and nonce replay
  protection;
- idempotent demo responses for write methods;
- deterministic fake merchants, orders, fulfillments, products, and variants.

The normative version 1 wire contract is defined in
[`PROTOCOL.md`](PROTOCOL.md). This README is the operational setup and testing
guide.

## Run it with Docker

Prerequisites:

- Docker with Docker Compose;
- [`just`](https://github.com/casey/just) (optional, but recommended).

Start the application:

```bash
just up
```

The RPC endpoint is <http://localhost:8000/rpc>. Its health check is available
at <http://localhost:8000/up>.

Useful commands:

```bash
just status
just logs
just test
just down
```

Without `just`, run:

```bash
docker compose up --build --detach --wait
```

The image installs PHP and Composer dependencies, creates the SQLite database,
generates an application key, runs the migrations, and starts Laravel. No host
PHP, Composer, or database is required.

## Test the reference implementation

Run the focused contract suite against the container:

```bash
just test
```

The suite sends authenticated requests through `POST /rpc`, covers every method
listed below, verifies authentication failures and nonce replay protection, and
checks idempotent writes. A local smoke-check sequence is:

1. Run `just up` and wait for Docker to report the service as healthy.
2. Confirm `http://localhost:8000/up` returns HTTP 200.
3. Run `just test`.
4. Configure the public HTTPS endpoint in Shipit API using the next section.
5. Use the Return and Exchange tool to search for order `#2149` with
   `alex.customer@example.com`.

There is intentionally no web demo page. This README is the setup, testing, and
provider-contract playbook; the running application exposes only service and
framework routes.

## Connect it to Shipit API

`api.shipit.ax` runs outside your machine, so it cannot call a
`localhost` endpoint. Either deploy this application to a public HTTPS host or
expose the local container through an HTTPS tunnel. For example:

> [!WARNING]
> Replace `insecure-local-demo-value` before exposing the application through a
> tunnel or deploying it. The built-in value is public and provides no security.

```bash
cloudflared tunnel --url http://localhost:8000
```

Use the resulting HTTPS origin with `/rpc` appended, such as
`https://example.trycloudflare.com/rpc`.

In <https://api.shipit.ax>:

1. Open the organization that owns the Return and Exchange tool.
2. Go to **Settings → Return branding**.
3. In **Communication**, select **JSON-RPC provider**.
4. Copy the generated **RPC shared secret**.
5. Copy the final path segment from **Your return URL** on the same page. This
   is the identifier Shipit sends as `params.shop`.
6. Set that identifier and the generated secret in this repository's `.env`:

    ```dotenv
    SHIPIT_RETURN_RPC_SHOP=merchant-return-url-slug
    SHIPIT_RETURN_RPC_SHARED_SECRET=replace-with-the-secret-from-shipit
    ```

7. Run `just up` again so Docker recreates the service with those values.
8. Set **RPC endpoint URL** in Shipit to the public HTTPS `/rpc` URL and save.

The shop identifier must match the `params.shop` value sent by Shipit. The
shared secret must match exactly. Rotating the secret in Shipit requires
updating `.env` and recreating this service. The endpoint permits 300 requests
per minute for each source IP observed by Laravel by default; set
`SHIPIT_RETURN_RPC_RATE_LIMIT_PER_MINUTE` to adjust that limit. When using a
reverse proxy or tunnel, configure its trusted-proxy boundary so Laravel sees
the caller address; otherwise all proxy traffic shares one rate-limit bucket.

For the built-in local demo, the defaults are:

| Setting        | Value                       |
| -------------- | --------------------------- |
| Shop           | `acme-demo.myshopify.com`   |
| Order number   | `#2149`                     |
| Customer email | `alex.customer@example.com` |
| RPC endpoint   | `http://localhost:8000/rpc` |

Do not use the built-in demo secret for a real provider.

## Provider RPC protocol

Shipit removes trailing slashes from the endpoint URL configured in the UI,
sends every call to that normalized URL, and does not append method-specific
paths.

### Authentication

Every request has these headers:

| Header               | Description                                       |
| -------------------- | ------------------------------------------------- |
| `Content-Type`       | `application/json`                                |
| `X-Shipit-Timestamp` | Unix timestamp in seconds                         |
| `X-Shipit-Nonce`     | Unique random value per request                   |
| `X-Shipit-Signature` | Lowercase hexadecimal HMAC-SHA256 signature       |
| `X-Shipit-Caller`    | Caller identifier; currently `api-product-return` |

Sign this canonical string using the merchant's shared secret:

```text
{timestamp}\n{nonce}\n{raw_json_body}
```

Equivalent pseudocode:

```text
signature = hex(hmac_sha256(shared_secret, canonical_string))
```

Providers should reject timestamps outside a 300-second window, retain nonces
for at least 10 minutes, and compare signatures using a constant-time function.
The signature covers the raw body bytes; do not parse and re-encode the body
before verification.

### JSON-RPC envelope

Request:

```json
{
    "jsonrpc": "2.0",
    "id": "req_01jx8m6f8x4rz7e4fah5m8a0tb",
    "method": "shipit.return_and_exchange.order.search",
    "params": {
        "shop": "merchant.example",
        "order_number": "#2149",
        "email_or_phone": "customer@example.com"
    }
}
```

Success:

```json
{
    "jsonrpc": "2.0",
    "id": "req_01jx8m6f8x4rz7e4fah5m8a0tb",
    "result": {
        "orders": []
    }
}
```

Business or protocol error:

```json
{
    "jsonrpc": "2.0",
    "id": "req_01jx8m6f8x4rz7e4fah5m8a0tb",
    "error": {
        "code": 42201,
        "message": "Validation failed",
        "data": {
            "field": "order_number"
        }
    }
}
```

Echo `id` unchanged. Public method segments and payload keys use `snake_case`.
Use HTTP 200 for valid JSON-RPC success and business errors, 400 for malformed
JSON, 401 for protocol authentication failures, 429 for rate limiting, and 500
for unexpected provider failures.

### Methods called by Shipit API

| Method                                                         | Required parameters                                                               | Result key                    |
| -------------------------------------------------------------- | --------------------------------------------------------------------------------- | ----------------------------- |
| `shipit.return_and_exchange.order.search`                      | `shop`, `order_number`, `email_or_phone`                                          | `orders`                      |
| `shipit.return_and_exchange.order.get`                         | `shop`, `order_id`                                                                | `order`                       |
| `shipit.return_and_exchange.order.returnable_fulfillments.get` | `shop`, `order_id`                                                                | `returnable_fulfillments`     |
| `shipit.return_and_exchange.return.create`                     | `shop`, `order_id`, `return_line_items`, `idempotency_key`                        | `return`                      |
| `shipit.return_and_exchange.exchange.create`                   | `shop`, `order_id`, `return_line_items`, `exchange_line_items`, `idempotency_key` | `exchange`                    |
| `shipit.return_and_exchange.refund.create`                     | `shop`, `order_id`, `refund_line_items`, `idempotency_key`                        | `refund`                      |
| `shipit.return_and_exchange.order.cancel`                      | `shop`, `order_id`, `idempotency_key`                                             | `order_cancel`                |
| `shipit.return_and_exchange.gift_card.create`                  | `shop`, `customer_id`, `initial_value`, `idempotency_key`                         | `gift_card`, `gift_card_code` |
| `shipit.return_and_exchange.order.metafield.set`               | `shop`, `order_id`, `namespace`, `key`, `value`, `idempotency_key`                | `metafield`                   |
| `shipit.return_and_exchange.product.get`                       | `shop`, `product_id`                                                              | `product`                     |
| `shipit.return_and_exchange.product_variant.get`               | `shop`, `variant_id`                                                              | `variant`                     |
| `shipit.return_and_exchange.product.search`                    | `shop`, `price`; optional `product_type`, `limit`                                 | `products`                    |

All write methods must treat `shop + method + idempotency_key` as the stable
retry identity.

### Shared order shape

`order.search` returns full order objects, not summaries. Shipit currently uses
the first matching order directly. `order.get` returns the same object shape.

```json
{
    "id": "ord_1001",
    "name": "#2149",
    "email": "customer@example.com",
    "phone": "+358401234567",
    "created_at": "2026-06-09T12:00:00Z",
    "cancelled_at": null,
    "closed_at": null,
    "display_financial_status": "PAID",
    "display_fulfillment_status": "FULFILLED",
    "amount": "89.90",
    "currency_code": "EUR",
    "shipping_address": {
        "name": "Pat Example",
        "first_name": "Pat",
        "last_name": "Example",
        "address1": "Testikatu 1",
        "address2": null,
        "city": "Helsinki",
        "province_code": "18",
        "country": "Finland",
        "country_code": "FI",
        "zip": "00100",
        "phone": "+358401234567"
    },
    "billing_address": null,
    "discount_codes": ["SUMMER10"],
    "tags": ["returned-before"],
    "metafields": {
        "shipit.return_id": "ret_5001"
    },
    "line_items": [
        {
            "line_item_id": "li_1",
            "id": "li_1",
            "title": "Blue T-Shirt",
            "name": "Blue T-Shirt / M",
            "quantity": 1,
            "sku": "TSHIRT-BLUE-M",
            "variant_id": "var_1",
            "variant_title": "M",
            "product_id": "prod_1",
            "product_type": "Shirts",
            "product_tags": ["tops"],
            "image": {
                "url": "https://cdn.example.com/products/blue-shirt.jpg"
            },
            "unit_price": "89.90"
        }
    ]
}
```

### Returnable fulfillment shape

```json
{
    "returnable_fulfillments": [
        {
            "id": "rf_1",
            "fulfillment_id": "ful_1",
            "line_items": [
                {
                    "fulfillment_line_item_id": "fli_1",
                    "line_item_id": "li_1",
                    "name": "Blue T-Shirt / M",
                    "sku": "TSHIRT-BLUE-M",
                    "variant_id": "var_1",
                    "product_id": "prod_1",
                    "returnable_quantity": 1,
                    "amount": "89.90",
                    "currency_code": "EUR"
                }
            ]
        }
    ]
}
```

Each return line item must contain `quantity` and either `line_item_id` or
`fulfillment_line_item_id`. It may also contain `return_reason` and
`return_reason_note`.

### Write results

Return creation:

```json
{
    "return": {
        "id": "ret_5001",
        "status": "REQUESTED",
        "order_id": "ord_1001",
        "return_line_items": [
            {
                "line_item_id": "li_1",
                "quantity": 1,
                "return_reason": "too_small",
                "return_reason_note": "Customer needs one size bigger"
            }
        ]
    }
}
```

Order cancellation:

```json
{
    "order_cancel": {
        "job": {
            "id": "job_1001",
            "done": false
        }
    }
}
```

Exchange creation returns `exchange.id`, `exchange.order_id`,
`return_line_items`, and `exchange_line_items`. Refund creation returns
`refund.id`, `refund.created_at`, `refund.note`, `refund.amount`, and
`refund.currency_code`. Gift-card creation returns a `gift_card` object and a
separate `gift_card_code`. Metafield creation returns `namespace`, `key`,
`value`, and `type` inside `metafield`.

### Product and variant shapes

```json
{
    "id": "prod_1",
    "title": "Blue T-Shirt",
    "product_type": "Shirts",
    "tags": ["tops"],
    "variants": [
        {
            "id": "var_1",
            "title": "M",
            "sku": "TSHIRT-BLUE-M",
            "price": "89.90",
            "available_for_sale": true,
            "inventory_policy": "DENY",
            "image": {
                "url": "https://cdn.example.com/products/blue-shirt.jpg"
            },
            "selected_options": [{ "name": "Size", "value": "M" }]
        }
    ]
}
```

`product.get` and every item in `product.search` use the product shape above.
`product_variant.get` returns one object matching an item in `variants`.

### Error codes

| Code     | HTTP | Meaning                                     |
| -------- | ---- | ------------------------------------------- |
| `-32700` | 400  | Malformed JSON                              |
| `-32600` | 200  | Invalid JSON-RPC request object             |
| `-32601` | 200  | Unknown RPC method                          |
| `-32602` | 200  | Invalid method parameters                   |
| `-32603` | 500  | Unexpected provider failure                 |
| `40100`  | 401  | Missing `params.shop` before authentication |
| `40101`  | 401  | Unknown shop or missing shared secret       |
| `40102`  | 401  | Missing HMAC headers or empty nonce         |
| `40103`  | 401  | Invalid timestamp format                    |
| `40104`  | 401  | Timestamp outside the allowed window        |
| `40105`  | 401  | Reused nonce                                |
| `40106`  | 401  | Signature mismatch                          |
| `40401`  | 200  | Shop resource not found                     |
| `42201`  | 200  | Business validation failure                 |

## Compatibility with Shipit API

The contract in this repository is matched to Shipit API's
`ReturnToolRpcConnector`, `CallReturnToolRpcRequest`, and
`JsonRpcReturnDataProvider`. Compatibility includes the raw-body signature,
headers, JSON-RPC envelope, all 12 methods, snake-case payloads, result wrapper
keys, and the legacy response fields consumed by the product-return flow.

The focused endpoint suite exercises every method and its API-facing result
shape. The API repository's own provider and connector tests remain the source
of truth for request construction; when that adapter changes, update this
reference implementation and its method dataset together.

Before using a real provider in production, verify a staging flow that searches
an order, loads returnable fulfillments, loads exchange products, and creates
the chosen return outcome. This repository cannot prove connectivity from
`api.shipit.ax` to a developer's private tunnel or deployed provider.

## Troubleshooting

- A local URL is not reachable from `api.shipit.ax`; use a public HTTPS URL.
- A signature mismatch usually means the shared secrets differ or the provider
  signed re-encoded JSON instead of the raw request body.
- `Unknown shop` means `SHIPIT_RETURN_RPC_SHOP` does not match `params.shop`.
- A rotated secret requires recreating the Docker service.
- Reusing a nonce within 10 minutes is rejected even when the signature is
  valid.
- Return stable IDs and honor idempotency keys; retries must not create
  duplicate returns, exchanges, refunds, gift cards, cancellations, or
  metafields.
