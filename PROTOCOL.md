# Shipit Return and Exchange Provider RPC Protocol

## Status and version

This document specifies version 1.0 of the Shipit Return and Exchange Provider
RPC Protocol. It is the normative interoperability contract between Shipit and
a custom return provider.

The application in this repository is a reference implementation. Examples in
the README and implementation-specific behavior are non-normative. If they
conflict with this document, this document controls.

Publication of this specification does not assert that every revision of the
reference implementation satisfies every requirement in this document.

## Requirements language

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT",
"SHOULD", "SHOULD NOT", "RECOMMENDED", "NOT RECOMMENDED", "MAY", and
"OPTIONAL" in this document are to be interpreted as described in BCP 14
[RFC2119] [RFC8174] when, and only when, they appear in all capitals, as
shown here.

## 1. Scope

This protocol allows Shipit Return and Exchange to use a merchant-controlled
commerce backend for order lookup, return eligibility, return outcomes, and
exchange-product discovery.

The protocol defines:

- endpoint and transport requirements;
- request authentication and replay protection;
- JSON-RPC request and response envelopes;
- canonical error codes;
- idempotency requirements;
- the version 1 method surface; and
- the canonical request and result shapes.

This protocol does not define merchant onboarding, user-interface behavior,
return policy decisions, payment processing, fulfillment orchestration, or
provider-internal persistence.

## 2. Terminology

**Client**
: Shipit Return and Exchange, acting through Shipit API.

**Provider**
: The merchant-controlled service implementing this protocol.

**Shop**
: The merchant or storefront identified by `params.shop`.

**Shared secret**
: The per-shop secret configured in Shipit and the Provider.

**Write method**
: A method that creates or changes provider state and therefore requires an
  `idempotency_key`.

**JSON object**
: A JSON value whose outermost type is an object, not an array.

Unless a field is marked OPTIONAL, the Client MUST supply every field listed in
a request shape and the Provider MUST validate it. Unless a field is marked
OPTIONAL, the Provider MUST return every field listed in a result shape.
Explicit Client fallback or alias handling is robustness behavior and does not
make a field optional in this protocol. Providers MAY include additional
fields. Clients MUST ignore unknown fields they do not understand.

## 3. Transport and endpoint

1. A Provider MUST expose one HTTP endpoint that accepts `POST` requests.
2. A production endpoint MUST use HTTPS.
3. A local development endpoint MAY use HTTP.
4. The Client MUST remove trailing `/` characters from the configured endpoint
   URL before sending every method.
5. The Client MUST NOT append method-specific paths to that URL.
6. Requests and responses MUST use UTF-8 encoded JSON.
7. The Client MUST send `Content-Type: application/json`.
8. A Provider MUST NOT require browser sessions, cookies, or CSRF tokens.
9. A Provider MAY rate-limit callers. A rate-limited response MUST use HTTP
   status `429`.

Rate limiting is a transport-level stage that occurs before the protocol
processing order in section 6. A `429` response is not a JSON-RPC response, and
its body is non-normative.

Batch requests and JSON-RPC notifications are not supported in version 1.

## 4. Authentication

### 4.1 Required headers

The Client MUST send:

| Header | Value |
| --- | --- |
| `X-Shipit-Timestamp` | Unix timestamp in whole seconds |
| `X-Shipit-Nonce` | Non-empty, unique value for this request |
| `X-Shipit-Signature` | Lowercase hexadecimal HMAC-SHA256 |
| `X-Shipit-Caller` | Caller identifier; Shipit currently uses `api-product-return` |

The Provider MUST authenticate with the shared secret selected by
`params.shop`. The Provider MUST NOT treat `X-Shipit-Caller` as authentication
on its own.

### 4.2 Canonical string

The canonical string is the byte concatenation:

```text
timestamp + "\n" + nonce + "\n" + raw_request_body
```

The separators are single line-feed bytes (`0x0A`). The body component MUST be
the exact bytes received over HTTP.

The signature is:

```text
lowercase_hex(HMAC-SHA256(shared_secret, canonical_string))
```

The Client MUST sign the exact serialized request body it sends. The Provider
MUST verify the signature against the exact body bytes it receives. The Client
and Provider MUST NOT parse and re-encode the body before calculating the
signature.

The Provider MUST compare signatures using a constant-time comparison
function.

### 4.3 Timestamp and nonce validation

1. The Provider MUST reject a timestamp that is not an unsigned integer.
2. The Provider MUST reject a timestamp whose absolute difference from the
   Provider's current time exceeds 300 seconds.
3. A nonce MUST be unique within a Shop.
4. The Provider MUST retain accepted nonces for at least 600 seconds.
5. The Provider MUST atomically reject a nonce already retained for that Shop.
6. The Provider MUST record a nonce only after the request signature is valid.

## 5. JSON-RPC framing

### 5.1 Request

Every request MUST be one JSON object with this shape:

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

Requirements:

1. `jsonrpc` MUST be the string `2.0`.
2. `id` MUST be present. It MAY be a JSON string, number, or `null`.
3. `method` MUST be a non-empty string.
4. `params` MUST be a JSON object.
5. `params.shop` MUST be a non-empty string.
6. Method segments and protocol-defined field names MUST use `snake_case`.

### 5.2 Success response

A successful response MUST have this shape:

```json
{
    "jsonrpc": "2.0",
    "id": "req_01jx8m6f8x4rz7e4fah5m8a0tb",
    "result": {}
}
```

The response `id` MUST equal the request `id`. The response MUST contain
exactly one of `result` or `error`.

### 5.3 Error response

Except for the transport-level `429` response defined in section 3, an error
response MUST have this shape:

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

`error.code` MUST be an integer. `error.message` MUST be a non-empty string.
`error.data` MAY be omitted. When present, `error.data` MUST be a JSON object.

For malformed JSON, a missing request `id`, or an unsupported request `id` type,
the Provider MUST set the response `id` to `null`. For any other error, the
Provider MUST echo the request `id` when it can be determined.

## 6. HTTP status and error codes

After any transport-level rate-limit check, the Provider MUST process a request
in this order:

1. parse the JSON body;
2. confirm that the decoded body is a JSON object;
3. resolve `params.shop` and authenticate the request;
4. validate the remaining JSON-RPC envelope; and
5. validate and dispatch the method.

The first failing stage determines the error response. In particular, an
otherwise invalid envelope without an authenticated `params.shop` MUST return
the applicable authentication error rather than an envelope error.

The Provider MUST use these canonical mappings:

| Code | HTTP | Meaning |
| ---: | ---: | --- |
| `-32700` | `400` | Malformed JSON |
| `-32600` | `200` | Invalid JSON-RPC request object |
| `-32601` | `200` | Unknown method |
| `-32602` | `200` | Invalid method parameters |
| `-32603` | `500` | Unexpected Provider failure |
| `40100` | `401` | Missing `params.shop` before authentication |
| `40101` | `401` | Unknown Shop or missing shared secret |
| `40102` | `401` | Missing signature headers or empty nonce |
| `40103` | `401` | Invalid timestamp format |
| `40104` | `401` | Timestamp outside the allowed window |
| `40105` | `401` | Nonce replay |
| `40106` | `401` | Signature mismatch |
| `40401` | `200` | Shop resource not found |
| `42201` | `200` | Business validation failure |

A Provider MAY define additional business error codes outside the reserved
JSON-RPC error range. Such codes MUST be documented for integrators.

The Provider MUST NOT include stack traces, credentials, shared secrets, or
other internal exception details in error responses.

## 7. Data conventions

1. Provider-owned business and resource identifiers MUST be non-empty strings.
   The identifier returned for the same resource MUST remain stable across
   calls.
2. Business-object timestamp fields MUST be RFC 3339 strings in UTC. The
   authentication timestamp uses the Unix format defined in section 4.3.
3. Currency codes MUST be uppercase ISO 4217 codes.
4. A field defined as a decimal string MUST match
   `^-?(0|[1-9][0-9]*)(\.[0-9]+)?$` and MUST NOT use exponent notation or a
   binary floating-point JSON number.
5. Requested and created line-item `quantity` values MUST be integers greater
   than or equal to `1`. A `returnable_quantity` MUST be an integer greater than
   or equal to `0`.
6. Lists MUST be JSON arrays.
7. `null` MAY be used only for a field explicitly described as nullable or
   OPTIONAL.
8. A URL MUST be an absolute URL.

## 8. Idempotency

The following methods are write methods:

- `shipit.return_and_exchange.return.create`
- `shipit.return_and_exchange.exchange.create`
- `shipit.return_and_exchange.refund.create`
- `shipit.return_and_exchange.order.cancel`
- `shipit.return_and_exchange.gift_card.create`
- `shipit.return_and_exchange.order.metafield.set`

Every write request MUST include a non-empty `idempotency_key`.

The tuple `shop + method + idempotency_key` is the retry identity:

1. When the Client retries the same serialized JSON-RPC request at the
   transport layer, it MUST preserve the request and its idempotency key. A new
   invocation of the intended operation MAY use a new idempotency key.
2. The Provider MUST execute the operation at most once for one retry identity.
3. Concurrent requests with the same retry identity MUST be serialized or
   otherwise coordinated atomically.
4. A completed retry MUST return the same logical result as the original
   request.
5. The Provider MUST retain retry results for at least 30 minutes.
6. Retry identities for different Shops or methods MUST NOT collide.

## 9. Common object shapes

### 9.1 Address

| Field | Type | Notes |
| --- | --- | --- |
| `name` | string | Full recipient name |
| `first_name` | string | |
| `last_name` | string | |
| `address1` | string | |
| `address2` | string or null | OPTIONAL |
| `city` | string | |
| `province_code` | string or null | OPTIONAL |
| `country` | string | |
| `country_code` | string | ISO 3166-1 alpha-2 |
| `zip` | string | Postal code |
| `phone` | string or null | OPTIONAL |

### 9.2 Image

| Field | Type |
| --- | --- |
| `url` | string |

### 9.3 Order line item

| Field | Type | Notes |
| --- | --- | --- |
| `line_item_id` | string | Canonical line-item ID; REQUIRED unless `id` is present |
| `id` | string | Legacy alias; REQUIRED unless `line_item_id` is present |
| `title` | string | |
| `name` | string | Display name including variant when applicable |
| `quantity` | integer | |
| `sku` | string or null | OPTIONAL |
| `variant_id` | string or null | OPTIONAL |
| `variant_title` | string or null | OPTIONAL |
| `product_id` | string | |
| `product_type` | string or null | OPTIONAL |
| `product_tags` | array of strings | OPTIONAL |
| `image` | Image or null | OPTIONAL |
| `unit_price` | decimal string | |

At least one of `line_item_id` and `id` MUST be present. When both are present,
they MUST be equal.

### 9.4 Order

| Field | Type | Notes |
| --- | --- | --- |
| `id` | string | |
| `customer_id` | string or null | OPTIONAL |
| `name` | string | Customer-visible order number |
| `email` | string or null | At least email or phone SHOULD be present |
| `phone` | string or null | At least email or phone SHOULD be present |
| `created_at` | RFC 3339 string | |
| `cancelled_at` | RFC 3339 string or null | |
| `closed_at` | RFC 3339 string or null | |
| `display_financial_status` | string | |
| `display_fulfillment_status` | string | |
| `amount` | decimal string | |
| `currency_code` | string | |
| `shipping_address` | Address or null | |
| `billing_address` | Address or null | |
| `discount_codes` | array of strings | |
| `tags` | array of strings | |
| `metafields` | object | String keys; JSON values |
| `line_items` | array of Order line items | |

### 9.5 Returnable fulfillment

Each returnable fulfillment MUST contain:

| Field | Type |
| --- | --- |
| `id` | string |
| `fulfillment_id` | string |
| `line_items` | array of Returnable fulfillment line items |

Each returnable fulfillment line item MUST contain:

| Field | Type |
| --- | --- |
| `fulfillment_line_item_id` | string |
| `line_item_id` | string |
| `name` | string |
| `sku` | string or null |
| `variant_id` | string or null |
| `product_id` | string |
| `returnable_quantity` | integer greater than or equal to `0` |
| `amount` | decimal string |
| `currency_code` | string |

### 9.6 Return line item input

| Field | Type | Requirement |
| --- | --- | --- |
| `line_item_id` | string | REQUIRED unless `fulfillment_line_item_id` is present |
| `fulfillment_line_item_id` | string | REQUIRED unless `line_item_id` is present |
| `quantity` | integer | REQUIRED; minimum `1` |
| `return_reason` | string | OPTIONAL |
| `return_reason_note` | string | OPTIONAL |

At least one of `line_item_id` and `fulfillment_line_item_id` MUST be present.
When both are present, they MUST identify the same order line.

### 9.7 Variant

| Field | Type | Notes |
| --- | --- | --- |
| `id` | string | |
| `title` | string | |
| `sku` | string or null | |
| `price` | decimal string | |
| `available_for_sale` | boolean | |
| `inventory_policy` | string or null | OPTIONAL |
| `image` | Image or null | OPTIONAL |
| `selected_options` | array of `{name: string, value: string}` | |

### 9.8 Product

| Field | Type |
| --- | --- |
| `id` | string |
| `title` | string |
| `product_type` | string or null |
| `tags` | array of strings |
| `variants` | array of Variant objects |

## 10. Method contracts

### 10.1 `shipit.return_and_exchange.order.search`

Request parameters:

| Field | Type |
| --- | --- |
| `shop` | string |
| `order_number` | string |
| `email_or_phone` | string |

The result MUST contain `orders`, an array of full Order objects. A Provider
MUST return an empty array when no order matches. A Provider MUST NOT return a
summary shape in this method.

When multiple orders match, the Provider SHOULD order the most relevant match
first because the current Client consumes the first result.

### 10.2 `shipit.return_and_exchange.order.get`

Request parameters:

| Field | Type |
| --- | --- |
| `shop` | string |
| `order_id` | string |

The result MUST contain `order`, a full Order object.

### 10.3 `shipit.return_and_exchange.order.returnable_fulfillments.get`

Request parameters:

| Field | Type |
| --- | --- |
| `shop` | string |
| `order_id` | string |

The result MUST contain `returnable_fulfillments`, an array of Returnable
fulfillment objects. The Provider MUST return an empty array when no item is
returnable.

### 10.4 `shipit.return_and_exchange.return.create`

Request parameters:

| Field | Type | Notes |
| --- | --- | --- |
| `shop` | string | |
| `order_id` | string | |
| `return_line_items` | non-empty array of Return line item inputs | |
| `tracking_number` | string | OPTIONAL |
| `tracking_url` | string | OPTIONAL |
| `idempotency_key` | string | |

The result MUST contain `return` with:

| Field | Type |
| --- | --- |
| `id` | string |
| `status` | string |
| `order_id` | string |
| `return_line_items` | array of objects |

Each returned line item MUST have this shape:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | string or null | OPTIONAL |
| `line_item_id` | string or null | OPTIONAL |
| `fulfillment_line_item_id` | string or null | OPTIONAL |
| `quantity` | integer | Minimum `1` |
| `return_reason` | string or null | OPTIONAL |
| `return_reason_note` | string or null | OPTIONAL |

At least one of `id`, `line_item_id`, or `fulfillment_line_item_id` MUST be a
non-empty string. When multiple non-null identifiers are present, they MUST
identify the same order line. The line item SHOULD echo `return_reason` and
`return_reason_note` when the Provider retains them.

### 10.5 `shipit.return_and_exchange.exchange.create`

Request parameters:

| Field | Type |
| --- | --- |
| `shop` | string |
| `order_id` | string |
| `return_line_items` | non-empty array of Return line item inputs |
| `exchange_line_items` | non-empty array of Exchange line item inputs |
| `idempotency_key` | string |

An Exchange line item input MUST contain `variant_id` and `quantity`.

The result MUST contain `exchange` with:

| Field | Type |
| --- | --- |
| `id` | string |
| `order_id` | string |
| `status` | string |
| `return_line_items` | array of objects |
| `exchange_line_items` | array of objects |

Each returned exchange line item MUST contain `variant_id` and `quantity`.
Each returned return line item MUST satisfy the returned line-item requirements
in section 10.4.

### 10.6 `shipit.return_and_exchange.refund.create`

Request parameters:

| Field | Type | Notes |
| --- | --- | --- |
| `shop` | string | |
| `order_id` | string | |
| `refund_line_items` | non-empty array of Return line item inputs | |
| `note` | string or null | OPTIONAL |
| `notify_customer` | boolean | OPTIONAL; defaults to `false` |
| `transactions` | array | OPTIONAL; Provider-defined descriptors |
| `idempotency_key` | string | |

The result MUST contain `refund` with:

| Field | Type |
| --- | --- |
| `id` | string |
| `created_at` | RFC 3339 string |
| `note` | string or null |
| `amount` | decimal string |
| `currency_code` | string |

### 10.7 `shipit.return_and_exchange.order.cancel`

Request parameters:

| Field | Type |
| --- | --- |
| `shop` | string |
| `order_id` | string |
| `idempotency_key` | string |

The result MUST contain `order_cancel.job`. The job MUST contain a stable
string `id` and a boolean `done`.

### 10.8 `shipit.return_and_exchange.gift_card.create`

Request parameters:

| Field | Type | Notes |
| --- | --- | --- |
| `shop` | string | |
| `customer_id` | string | |
| `initial_value` | decimal string | |
| `note` | string or null | OPTIONAL |
| `idempotency_key` | string | |

The result MUST contain `gift_card` and `gift_card_code`. `gift_card_code` MUST
be a non-empty string that the Client can present to the customer.

`gift_card` MUST contain:

| Field | Type |
| --- | --- |
| `id` | string |
| `customer_id` | string |
| `initial_value` | decimal string |
| `expires_on` | RFC 3339 full-date (`YYYY-MM-DD`) or null |
| `note` | string or null |

### 10.9 `shipit.return_and_exchange.order.metafield.set`

Request parameters:

| Field | Type | Notes |
| --- | --- | --- |
| `shop` | string | |
| `order_id` | string | |
| `namespace` | string | |
| `key` | string | |
| `value` | any JSON value | `null` is valid |
| `type` | string | OPTIONAL; defaults to `json` |
| `idempotency_key` | string | |

The Provider MUST verify that `order_id` belongs to `shop`.

The result MUST contain `metafield` with:

| Field | Type |
| --- | --- |
| `id` | string |
| `namespace` | string |
| `key` | string |
| `value` | any JSON value |
| `type` | string |

### 10.10 `shipit.return_and_exchange.product.get`

Request parameters:

| Field | Type |
| --- | --- |
| `shop` | string |
| `product_id` | string |

The result MUST contain `product`, a Product object.

### 10.11 `shipit.return_and_exchange.product_variant.get`

Request parameters:

| Field | Type |
| --- | --- |
| `shop` | string |
| `variant_id` | string |

The result MUST contain `variant`, a Variant object.

### 10.12 `shipit.return_and_exchange.product.search`

Request parameters:

| Field | Type | Notes |
| --- | --- | --- |
| `shop` | string | |
| `price` | number | Target price |
| `product_type` | string | OPTIONAL |
| `limit` | integer | OPTIONAL; defaults to `10`; range `1` to `25` |

The result MUST contain `products`, an array of Product objects. The Provider
MUST return no more than `limit` products.

## 11. Resource isolation

Every resource identifier MUST be resolved within `params.shop`. A Provider
MUST NOT return, mutate, or reveal a resource owned by another Shop. A
cross-Shop identifier MUST be treated as not found and returned as error
`40401`.

## 12. Extensibility and compatibility

1. A Provider MAY add result fields without changing this protocol version.
2. A Client MUST ignore unknown result fields.
3. A Client MAY send additional OPTIONAL fields documented by a Provider.
4. A Provider SHOULD ignore unknown request fields unless doing so would create
   ambiguous or unsafe behavior.
5. New methods MAY be added compatibly.
6. A breaking change to authentication, framing, existing method semantics, or
   required fields MUST use a new major protocol version.
7. Because version 1 has no in-band negotiation field, a future breaking
   version MUST define negotiation before deployment.

## 13. Security considerations

1. Shared secrets MUST be generated from a cryptographically secure random
   source.
2. Shared secrets MUST NOT be logged, returned in responses, committed to
   source control, or included in URLs.
3. Providers MUST isolate secrets and nonce storage by Shop.
4. Providers MUST authenticate before executing a method.
5. Providers MUST validate all parameters before performing a write.
6. Providers MUST enforce authorization and resource ownership independently
   of identifiers supplied by the Client.
7. Providers SHOULD apply request-size, execution-time, and rate limits.
8. Providers SHOULD log the request ID, caller, method, Shop, outcome, and
   latency without logging secrets or sensitive payload fields.
9. Providers MUST use atomic storage primitives for nonce replay protection and
   idempotent writes.

## 14. Conformance

A version 1 Provider conforms to this specification only if it:

1. implements the transport, authentication, framing, error, and isolation
   requirements;
2. implements all 12 methods in section 10;
3. returns the required result shapes defined here; and
4. satisfies the replay and idempotency requirements under concurrency.

A version 1 Client conforms to this specification only if it:

1. signs the exact request body as specified;
2. sends the required headers and envelope fields;
3. sends method parameters in the defined shapes;
4. preserves the serialized request and idempotency key across transport
   retries of that request; and
5. accepts compatible additional result fields.

The tests in this repository exercise the implemented wire surface, but they
are not a complete conformance suite and do not establish full conformance with
this specification.

## 15. References

- [JSON-RPC 2.0 Specification](https://www.jsonrpc.org/specification)
- [RFC2119: Key words for use in RFCs to Indicate Requirement Levels](https://www.rfc-editor.org/rfc/rfc2119)
- [RFC8174: Ambiguity of Uppercase vs Lowercase in RFC 2119 Key Words](https://www.rfc-editor.org/rfc/rfc8174)

[RFC2119]: https://www.rfc-editor.org/rfc/rfc2119
[RFC8174]: https://www.rfc-editor.org/rfc/rfc8174
