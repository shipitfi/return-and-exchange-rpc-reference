<?php

use App\Services\ReturnProviderRpc\DemoCatalog;
use App\Services\ReturnProviderRpc\JsonRpcDispatcher;
use App\Services\ReturnProviderRpc\JsonRpcRequestVerifier;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

dataset('provider rpc methods', [
    'order.search' => [
        [
            'jsonrpc' => '2.0',
            'id' => 'req_search',
            'method' => 'shipit.return_and_exchange.order.search',
            'params' => [
                'shop' => 'acme-demo.myshopify.com',
                'order_number' => '#2149',
                'email_or_phone' => 'alex.customer@example.com',
            ],
        ],
        function (array $json): void {
            expect($json['result']['orders'][0]['id'])->toBe('acme_ord_1001');
        },
    ],
    'order.get' => [
        [
            'jsonrpc' => '2.0',
            'id' => 'req_order_get',
            'method' => 'shipit.return_and_exchange.order.get',
            'params' => [
                'shop' => 'acme-demo.myshopify.com',
                'order_id' => 'acme_ord_1001',
            ],
        ],
        function (array $json): void {
            expect($json['result']['order']['name'])->toBe('#2149');
        },
    ],
    'order.returnable_fulfillments.get' => [
        [
            'jsonrpc' => '2.0',
            'id' => 'req_fulfillments',
            'method' => 'shipit.return_and_exchange.order.returnable_fulfillments.get',
            'params' => [
                'shop' => 'acme-demo.myshopify.com',
                'order_id' => 'acme_ord_1001',
            ],
        ],
        function (array $json): void {
            expect($json['result']['returnable_fulfillments'][0]['line_items'][0]['line_item_id'])
                ->toBe('acme_li_1');
        },
    ],
    'return.create' => [
        [
            'jsonrpc' => '2.0',
            'id' => 'req_return_create',
            'method' => 'shipit.return_and_exchange.return.create',
            'params' => [
                'shop' => 'acme-demo.myshopify.com',
                'order_id' => 'acme_ord_1001',
                'return_line_items' => [[
                    'fulfillment_line_item_id' => 'acme_fli_1',
                    'quantity' => 1,
                    'return_reason' => 'too_small',
                ]],
                'tracking_number' => 'JJFI1234567890',
                'tracking_url' => 'https://carrier.example/track/JJFI1234567890',
                'idempotency_key' => 'return-idempotency-key',
            ],
        ],
        function (array $json): void {
            expect($json['result']['return']['status'])->toBe('REQUESTED');
        },
    ],
    'exchange.create' => [
        [
            'jsonrpc' => '2.0',
            'id' => 'req_exchange_create',
            'method' => 'shipit.return_and_exchange.exchange.create',
            'params' => [
                'shop' => 'acme-demo.myshopify.com',
                'order_id' => 'acme_ord_1001',
                'return_line_items' => [[
                    'line_item_id' => 'acme_li_1',
                    'quantity' => 1,
                ]],
                'exchange_line_items' => [[
                    'variant_id' => 'acme_var_blue_tee_l',
                    'quantity' => 1,
                ]],
                'idempotency_key' => 'exchange-idempotency-key',
            ],
        ],
        function (array $json): void {
            expect($json['result']['exchange']['exchange_line_items'][0]['variant_id'])
                ->toBe('acme_var_blue_tee_l');
        },
    ],
    'refund.create' => [
        [
            'jsonrpc' => '2.0',
            'id' => 'req_refund_create',
            'method' => 'shipit.return_and_exchange.refund.create',
            'params' => [
                'shop' => 'acme-demo.myshopify.com',
                'order_id' => 'acme_ord_1001',
                'refund_line_items' => [[
                    'line_item_id' => 'acme_li_1',
                    'quantity' => 1,
                ]],
                'note' => 'Customer returned one item',
                'notify_customer' => false,
                'transactions' => [],
                'idempotency_key' => 'refund-idempotency-key',
            ],
        ],
        function (array $json): void {
            expect($json['result']['refund']['currency_code'])
                ->toBe('EUR');
        },
    ],
    'gift_card.create' => [
        [
            'jsonrpc' => '2.0',
            'id' => 'req_gift_card_create',
            'method' => 'shipit.return_and_exchange.gift_card.create',
            'params' => [
                'shop' => 'acme-demo.myshopify.com',
                'customer_id' => 'cust_acme_1001',
                'initial_value' => '89.90',
                'note' => 'Store credit for return',
                'idempotency_key' => 'gift-card-idempotency-key',
            ],
        ],
        function (array $json): void {
            expect($json['result']['gift_card_code'])->toStartWith('SHIPIT-');
        },
    ],
    'order.metafield.set' => [
        [
            'jsonrpc' => '2.0',
            'id' => 'req_metafield',
            'method' => 'shipit.return_and_exchange.order.metafield.set',
            'params' => [
                'shop' => 'acme-demo.myshopify.com',
                'order_id' => 'acme_ord_1001',
                'namespace' => 'shipit',
                'key' => 'return_id',
                'value' => 'ret_5001',
                'type' => 'json',
                'idempotency_key' => 'metafield-idempotency-key',
            ],
        ],
        function (array $json): void {
            expect($json['result']['metafield']['key'])->toBe('return_id');
        },
    ],
    'product.get' => [
        [
            'jsonrpc' => '2.0',
            'id' => 'req_product_get',
            'method' => 'shipit.return_and_exchange.product.get',
            'params' => [
                'shop' => 'acme-demo.myshopify.com',
                'product_id' => 'acme_prod_blue_tee',
            ],
        ],
        function (array $json): void {
            expect($json['result']['product']['variants'][0]['id'])
                ->toBe('acme_var_blue_tee_m');
        },
    ],
    'product_variant.get' => [
        [
            'jsonrpc' => '2.0',
            'id' => 'req_variant_get',
            'method' => 'shipit.return_and_exchange.product_variant.get',
            'params' => [
                'shop' => 'acme-demo.myshopify.com',
                'variant_id' => 'acme_var_blue_tee_m',
            ],
        ],
        function (array $json): void {
            expect($json['result']['variant']['sku'])->toBe('ACME-TSHIRT-BLUE-M');
        },
    ],
    'product.search' => [
        [
            'jsonrpc' => '2.0',
            'id' => 'req_product_search',
            'method' => 'shipit.return_and_exchange.product.search',
            'params' => [
                'shop' => 'acme-demo.myshopify.com',
                'price' => 89.90,
                'product_type' => 'Shirts',
                'limit' => 3,
            ],
        ],
        function (array $json): void {
            expect($json['result']['products'][0]['product_type'])
                ->toBe('Shirts');
        },
    ],
]);

test('rpc endpoint implements the full current method surface', function (
    array $payload,
    Closure $assertion,
): void {
    $response = signedRpcRequest($payload);

    $response->assertOk()
        ->assertJson([
            'jsonrpc' => '2.0',
            'id' => $payload['id'],
        ]);

    $assertion($response->json());
})->with('provider rpc methods');

test('rpc endpoint implements the order cancellation used by the api adapter', function (): void {
    $catalog = app(DemoCatalog::class);
    $shopIdentifier = $catalog->sampleShopIdentifier();
    $order = $catalog->sampleOrder($shopIdentifier);

    $response = signedRpcRequest([
        'jsonrpc' => '2.0',
        'id' => 'req_order_cancel',
        'method' => 'shipit.return_and_exchange.order.cancel',
        'params' => [
            'shop' => $shopIdentifier,
            'order_id' => $order['id'],
            'idempotency_key' => 'cancel-idempotency-key',
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('result.order_cancel.job.done', false);

    expect($response->json('result.order_cancel.job.id'))->toStartWith('job_');
});

test('rpc endpoint serves the shop identifier configured in the api ui', function (): void {
    $configuredShopIdentifier = 'developer-shop.myshopify.com';
    $shops = config('shipit_return_rpc.shops');
    $shops[$configuredShopIdentifier] = $shops['acme-demo.myshopify.com'];
    unset($shops['acme-demo.myshopify.com']);

    config()->set('shipit_return_rpc.primary_shop_identifier', $configuredShopIdentifier);
    config()->set('shipit_return_rpc.shops', $shops);

    $response = signedRpcRequest([
        'jsonrpc' => '2.0',
        'id' => 'req_configured_shop',
        'method' => 'shipit.return_and_exchange.order.search',
        'params' => [
            'shop' => $configuredShopIdentifier,
            'order_number' => '#2149',
            'email_or_phone' => 'alex.customer@example.com',
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('result.orders.0.id', 'acme_ord_1001');
});

test('rpc endpoint accepts authenticated server requests without a csrf token', function (): void {
    $originalEnvironment = $this->app['env'];
    $this->app['env'] = 'local';

    try {
        $catalog = app(DemoCatalog::class);
        $shopIdentifier = $catalog->sampleShopIdentifier();

        signedRpcRequest([
            'jsonrpc' => '2.0',
            'id' => 'req_without_csrf_token',
            'method' => 'shipit.return_and_exchange.order.search',
            'params' => [
                'shop' => $shopIdentifier,
                'order_number' => '#2149',
                'email_or_phone' => 'alex.customer@example.com',
            ],
        ])->assertOk();
    } finally {
        $this->app['env'] = $originalEnvironment;
    }
});

test('rpc endpoint rejects malformed json', function (): void {
    $response = $this->withHeaders([
        'Content-Type' => 'application/json',
    ])->call(
        'POST',
        '/rpc',
        server: [],
        content: '{"broken"',
    );

    $response->assertBadRequest()
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('id', null)
        ->assertJsonPath('error.code', -32700);
});

test('rpc endpoint rejects a top-level json array before authentication', function (): void {
    $this->withHeaders([
        'Content-Type' => 'application/json',
    ])->call(
        'POST',
        '/rpc',
        server: [],
        content: '[]',
    )->assertOk()
        ->assertJsonPath('id', null)
        ->assertJsonPath('error.code', -32600);
});

test('rpc endpoint rejects unsupported request id types', function (): void {
    signedRpcRequest([
        'jsonrpc' => '2.0',
        'id' => true,
        'method' => 'shipit.return_and_exchange.order.search',
        'params' => [
            'shop' => 'acme-demo.myshopify.com',
            'order_number' => '#2149',
            'email_or_phone' => 'alex.customer@example.com',
        ],
    ])->assertOk()
        ->assertJsonPath('id', null)
        ->assertJsonPath('error.code', -32600);
});

test('rpc endpoint never echoes an unsupported request id in an error', function (): void {
    signedRpcRequest([
        'jsonrpc' => '1.0',
        'id' => true,
        'method' => 'shipit.return_and_exchange.order.search',
        'params' => [
            'shop' => 'acme-demo.myshopify.com',
            'order_number' => '#2149',
            'email_or_phone' => 'alex.customer@example.com',
        ],
    ])->assertOk()
        ->assertJsonPath('id', null)
        ->assertJsonPath('error.code', -32600);

    $payload = [
        'jsonrpc' => '2.0',
        'id' => true,
        'method' => 'shipit.return_and_exchange.order.search',
        'params' => [
            'shop' => 'acme-demo.myshopify.com',
            'order_number' => '#2149',
            'email_or_phone' => 'alex.customer@example.com',
        ],
    ];

    $this->withHeaders([
        'Content-Type' => 'application/json',
    ])->call(
        'POST',
        '/rpc',
        server: [],
        content: jsonPayload($payload),
    )->assertUnauthorized()
        ->assertJsonPath('id', null)
        ->assertJsonPath('error.code', 40102);
});

test('rpc endpoint rate limits callers by ip address', function (): void {
    config()->set('shipit_return_rpc.rate_limit_per_minute', 1);

    $request = fn (): TestResponse => $this->withHeaders([
        'Content-Type' => 'application/json',
    ])->call(
        'POST',
        '/rpc',
        server: ['REMOTE_ADDR' => '192.0.2.10'],
        content: '{"broken"',
    );

    $request()->assertBadRequest();
    $request()->assertTooManyRequests();
});

test('rpc endpoint rejects invalid signatures', function (): void {
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 'req_invalid_signature',
        'method' => 'shipit.return_and_exchange.order.search',
        'params' => [
            'shop' => 'acme-demo.myshopify.com',
            'order_number' => '#2149',
            'email_or_phone' => 'alex.customer@example.com',
        ],
    ];

    $rawBody = jsonPayload($payload);

    $response = $this->call(
        'POST',
        '/rpc',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHIPIT_TIMESTAMP' => (string) time(),
            'HTTP_X_SHIPIT_NONCE' => 'invalid-signature-nonce',
            'HTTP_X_SHIPIT_SIGNATURE' => \str_repeat('0', 64),
            'HTTP_X_SHIPIT_CALLER' => 'shipit-return-demo',
        ],
        $rawBody,
    );

    $response->assertUnauthorized()
        ->assertJsonPath('error.code', 40106);
});

test('rpc endpoint rejects an empty nonce', function (): void {
    signedRpcRequest(
        payload: [
            'jsonrpc' => '2.0',
            'id' => 'req_empty_nonce',
            'method' => 'shipit.return_and_exchange.order.search',
            'params' => [
                'shop' => 'acme-demo.myshopify.com',
                'order_number' => '#2149',
                'email_or_phone' => 'alex.customer@example.com',
            ],
        ],
        nonce: '',
    )->assertUnauthorized()
        ->assertJsonPath('error.code', 40102);
});

test('rpc endpoint rejects nonce replay', function (): void {
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 'req_replay',
        'method' => 'shipit.return_and_exchange.order.search',
        'params' => [
            'shop' => 'acme-demo.myshopify.com',
            'order_number' => '#2149',
            'email_or_phone' => 'alex.customer@example.com',
        ],
    ];

    $timestamp = (string) time();
    $nonce = 'replay-nonce';

    signedRpcRequest($payload, $timestamp, $nonce)->assertOk();

    signedRpcRequest($payload, $timestamp, $nonce)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 40105);
});

test('rpc verifier claims a nonce atomically across concurrent requests', function (): void {
    $originalCache = Cache::getFacadeRoot();
    $raceableCache = new class(new ArrayStore) extends Repository
    {
        public function has($key): bool
        {
            Fiber::suspend();

            return false;
        }
    };

    Cache::swap($raceableCache);

    try {
        $catalog = app(DemoCatalog::class);
        $verifier = app(JsonRpcRequestVerifier::class);
        $shopIdentifier = $catalog->sampleShopIdentifier();
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 'req_concurrent_replay',
            'method' => 'shipit.return_and_exchange.order.search',
            'params' => [
                'shop' => $shopIdentifier,
                'order_number' => '#2149',
                'email_or_phone' => 'alex.customer@example.com',
            ],
        ];
        $rawBody = jsonPayload($payload);
        $timestamp = (string) time();
        $nonce = 'concurrent-replay-nonce';
        $signature = $verifier->sign(
            sharedSecret: (string) $catalog->sharedSecretForShop($shopIdentifier),
            timestamp: $timestamp,
            nonce: $nonce,
            rawBody: $rawBody,
        );
        $request = static fn (): Request => Request::create(
            '/rpc',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SHIPIT_TIMESTAMP' => $timestamp,
                'HTTP_X_SHIPIT_NONCE' => $nonce,
                'HTTP_X_SHIPIT_SIGNATURE' => $signature,
            ],
            content: $rawBody,
        );
        $requests = [
            new Fiber(fn (): ?array => $verifier->verify($request(), $rawBody, $payload)),
            new Fiber(fn (): ?array => $verifier->verify($request(), $rawBody, $payload)),
        ];

        foreach ($requests as $concurrentRequest) {
            $concurrentRequest->start();
        }

        foreach ($requests as $concurrentRequest) {
            if ($concurrentRequest->isSuspended()) {
                $concurrentRequest->resume();
            }
        }

        $results = array_map(
            static fn (Fiber $concurrentRequest): ?array => $concurrentRequest->getReturn(),
            $requests,
        );
        $rejections = array_values(array_filter($results));

        expect($results)->toContain(null)
            ->and($rejections)->toHaveCount(1)
            ->and($rejections[0]['code'])->toBe(40105);
    } finally {
        Cache::swap($originalCache);
    }
});

test('rpc endpoint rejects scalar line items as invalid params', function (): void {
    $catalog = app(DemoCatalog::class);
    $shopIdentifier = $catalog->sampleShopIdentifier();
    $order = $catalog->sampleOrder($shopIdentifier);

    signedRpcRequest([
        'jsonrpc' => '2.0',
        'id' => 'req_invalid_line_item',
        'method' => 'shipit.return_and_exchange.return.create',
        'params' => [
            'shop' => $shopIdentifier,
            'order_id' => $order['id'],
            'return_line_items' => ['not-an-object'],
            'idempotency_key' => 'invalid-line-item',
        ],
    ])->assertOk()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath(
            'error.message',
            'The return_line_items field must contain only objects.',
        );
});

test('write methods reject invalid protocol parameter types', function (
    string $method,
    array $params,
): void {
    signedRpcRequest([
        'jsonrpc' => '2.0',
        'id' => 'req_invalid_write_parameter',
        'method' => $method,
        'params' => [
            'shop' => 'acme-demo.myshopify.com',
            ...$params,
        ],
    ])->assertOk()
        ->assertJsonPath('error.code', -32602);
})->with([
    'relative tracking URL' => [
        'shipit.return_and_exchange.return.create',
        [
            'order_id' => 'acme_ord_1001',
            'return_line_items' => [[
                'line_item_id' => 'acme_li_1',
                'quantity' => 1,
            ]],
            'tracking_url' => '/tracking/123',
            'idempotency_key' => 'invalid-tracking-url',
        ],
    ],
    'non-boolean refund notification flag' => [
        'shipit.return_and_exchange.refund.create',
        [
            'order_id' => 'acme_ord_1001',
            'refund_line_items' => [[
                'line_item_id' => 'acme_li_1',
                'quantity' => 1,
            ]],
            'notify_customer' => 'false',
            'transactions' => [],
            'idempotency_key' => 'invalid-refund-notification-flag',
        ],
    ],
    'non-array refund transactions' => [
        'shipit.return_and_exchange.refund.create',
        [
            'order_id' => 'acme_ord_1001',
            'refund_line_items' => [[
                'line_item_id' => 'acme_li_1',
                'quantity' => 1,
            ]],
            'notify_customer' => false,
            'transactions' => 'not-an-array',
            'idempotency_key' => 'invalid-refund-transactions',
        ],
    ],
    'numeric gift card initial value' => [
        'shipit.return_and_exchange.gift_card.create',
        [
            'customer_id' => 'cust_acme_1001',
            'initial_value' => 89.90,
            'idempotency_key' => 'invalid-gift-card-initial-value',
        ],
    ],
    'non-decimal gift card initial value' => [
        'shipit.return_and_exchange.gift_card.create',
        [
            'customer_id' => 'cust_acme_1001',
            'initial_value' => 'not-money',
            'idempotency_key' => 'non-decimal-gift-card-initial-value',
        ],
    ],
]);

test('order metafield writes reject missing required parameters', function (
    string $missingParameter,
    string $expectedMessage,
): void {
    $catalog = app(DemoCatalog::class);
    $shopIdentifier = $catalog->sampleShopIdentifier();
    $order = $catalog->sampleOrder($shopIdentifier);
    $params = [
        'shop' => $shopIdentifier,
        'order_id' => $order['id'],
        'namespace' => 'shipit',
        'key' => 'return_id',
        'value' => 'ret_1001',
        'type' => 'single_line_text_field',
        'idempotency_key' => 'metafield-validation',
    ];
    unset($params[$missingParameter]);

    signedRpcRequest([
        'jsonrpc' => '2.0',
        'id' => 'req_invalid_metafield',
        'method' => 'shipit.return_and_exchange.order.metafield.set',
        'params' => $params,
    ])->assertOk()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath(
            'error.message',
            $expectedMessage,
        );
})->with([
    'order id' => ['order_id', 'The order_id field must be a non-empty string.'],
    'value' => ['value', 'The value field is required.'],
]);

test('order metafield writes reject orders outside the authenticated shop', function (): void {
    $catalog = app(DemoCatalog::class);
    $shopIdentifier = $catalog->sampleShopIdentifier();

    signedRpcRequest([
        'jsonrpc' => '2.0',
        'id' => 'req_unknown_metafield_order',
        'method' => 'shipit.return_and_exchange.order.metafield.set',
        'params' => [
            'shop' => $shopIdentifier,
            'order_id' => 'fjord_ord_1001',
            'namespace' => 'shipit',
            'key' => 'return_id',
            'value' => 'ret_1001',
            'type' => 'single_line_text_field',
            'idempotency_key' => 'unknown-metafield-order',
        ],
    ])->assertOk()
        ->assertJsonPath('error.code', 40401);
});

test('write methods reject resources outside the authenticated shop', function (
    string $method,
    array $params,
    int $expectedErrorCode,
): void {
    signedRpcRequest([
        'jsonrpc' => '2.0',
        'id' => 'req_outside_shop_resource',
        'method' => $method,
        'params' => [
            'shop' => 'acme-demo.myshopify.com',
            ...$params,
        ],
    ])->assertOk()
        ->assertJsonPath('error.code', $expectedErrorCode);
})->with([
    'return order' => [
        'shipit.return_and_exchange.return.create',
        [
            'order_id' => 'fjord_ord_1001',
            'return_line_items' => [[
                'line_item_id' => 'fjord_li_1',
                'quantity' => 1,
            ]],
            'idempotency_key' => 'outside-shop-return-order',
        ],
        40401,
    ],
    'return line item' => [
        'shipit.return_and_exchange.return.create',
        [
            'order_id' => 'acme_ord_1001',
            'return_line_items' => [[
                'line_item_id' => 'acme_li_2',
                'quantity' => 1,
            ]],
            'idempotency_key' => 'outside-order-return-line',
        ],
        40401,
    ],
    'cross-shop return line item' => [
        'shipit.return_and_exchange.return.create',
        [
            'order_id' => 'acme_ord_1001',
            'return_line_items' => [[
                'line_item_id' => 'fjord_li_1',
                'quantity' => 1,
            ]],
            'idempotency_key' => 'outside-shop-return-line',
        ],
        40401,
    ],
    'conflicting return line item identifiers' => [
        'shipit.return_and_exchange.return.create',
        [
            'order_id' => 'acme_ord_1001',
            'return_line_items' => [[
                'line_item_id' => 'acme_li_1',
                'fulfillment_line_item_id' => 'acme_fli_2',
                'quantity' => 1,
            ]],
            'idempotency_key' => 'conflicting-return-line-identifiers',
        ],
        -32602,
    ],
    'missing return line item identifier' => [
        'shipit.return_and_exchange.return.create',
        [
            'order_id' => 'acme_ord_1001',
            'return_line_items' => [[
                'quantity' => 1,
            ]],
            'idempotency_key' => 'missing-return-line-identifier',
        ],
        -32602,
    ],
    'exchange variant' => [
        'shipit.return_and_exchange.exchange.create',
        [
            'order_id' => 'acme_ord_1001',
            'return_line_items' => [[
                'line_item_id' => 'acme_li_1',
                'quantity' => 1,
            ]],
            'exchange_line_items' => [[
                'variant_id' => 'fjord_var_blue_tee_l',
                'quantity' => 1,
            ]],
            'idempotency_key' => 'outside-shop-exchange-variant',
        ],
        40401,
    ],
    'gift card customer' => [
        'shipit.return_and_exchange.gift_card.create',
        [
            'customer_id' => 'cust_fjord_1001',
            'initial_value' => '89.90',
            'idempotency_key' => 'outside-shop-gift-card-customer',
        ],
        40401,
    ],
]);

test('rpc endpoint does not disclose exception details for internal errors', function (): void {
    app()->instance(
        JsonRpcDispatcher::class,
        new class(app(DemoCatalog::class)) extends JsonRpcDispatcher
        {
            public function dispatch(string $method, array $params): array
            {
                throw new RuntimeException('Sensitive implementation detail.');
            }
        },
    );

    $response = signedRpcRequest([
        'jsonrpc' => '2.0',
        'id' => 'req_internal_error',
        'method' => 'shipit.return_and_exchange.order.search',
        'params' => [
            'shop' => 'acme-demo.myshopify.com',
            'order_number' => '#2149',
            'email_or_phone' => 'alex.customer@example.com',
        ],
    ])->assertInternalServerError()
        ->assertJsonPath('error.code', -32603)
        ->assertJsonPath('error.message', 'Internal error');

    expect($response->json('error'))->not->toHaveKey('data');
});

test('write methods are idempotent by method shop and idempotency key', function (): void {
    $catalog = app(DemoCatalog::class);
    $shopIdentifier = $catalog->sampleShopIdentifier();
    $order = $catalog->sampleOrder($shopIdentifier);
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 'req_idempotent_return',
        'method' => 'shipit.return_and_exchange.return.create',
        'params' => [
            'shop' => $shopIdentifier,
            'order_id' => $order['id'],
            'return_line_items' => [[
                'line_item_id' => $order['line_items'][0]['line_item_id'],
                'quantity' => 1,
            ]],
            'idempotency_key' => 'same-key-same-response',
        ],
    ];

    $firstResponse = signedRpcRequest(
        payload: $payload,
        timestamp: (string) time(),
        nonce: 'idempotency-first',
    )->assertOk();

    $secondResponse = signedRpcRequest(
        payload: $payload,
        timestamp: (string) time(),
        nonce: 'idempotency-second',
    )->assertOk();

    expect($firstResponse->json('result.return.id'))
        ->toBe($secondResponse->json('result.return.id'));
});

test('concurrent write retries execute their callback once', function (): void {
    $cacheStore = 'idempotency-test';
    $cacheDirectory = storage_path('framework/testing/'.str()->uuid());
    $counterFile = $cacheDirectory.'/callback-count';
    $firstCallbackFile = $cacheDirectory.'/first-callback-started';
    $releaseFirstCallbackFile = $cacheDirectory.'/release-first-callback';
    $originalCacheStore = config('cache.default');

    File::ensureDirectoryExists($cacheDirectory);
    File::put($counterFile, '');

    config()->set('cache.default', $cacheStore);
    config()->set("cache.stores.{$cacheStore}", [
        'driver' => 'file',
        'path' => $cacheDirectory,
        'lock_path' => $cacheDirectory,
    ]);
    Cache::setDefaultDriver($cacheStore);

    try {
        $shopIdentifier = app(DemoCatalog::class)->sampleShopIdentifier();
        $order = app(DemoCatalog::class)->sampleOrder($shopIdentifier);
        $params = [
            'shop' => $shopIdentifier,
            'order_id' => $order['id'],
            'return_line_items' => [[
                'line_item_id' => $order['line_items'][0]['line_item_id'],
                'quantity' => 1,
            ]],
            'idempotency_key' => 'concurrent-return',
        ];
        $processIds = [];

        foreach (['first', 'second'] as $processRole) {
            $processId = pcntl_fork();

            expect($processId)->not->toBe(-1);

            if ($processId === 0) {
                $catalog = new class($counterFile, $firstCallbackFile, $releaseFirstCallbackFile, $processRole) extends DemoCatalog
                {
                    public function __construct(
                        private readonly string $counterFile,
                        private readonly string $firstCallbackFile,
                        private readonly string $releaseFirstCallbackFile,
                        private readonly string $processRole,
                    ) {}

                    public function resolveLineItemId(
                        string $shopIdentifier,
                        string $orderId,
                        ?string $lineItemId,
                        ?string $fulfillmentLineItemId,
                    ): string {
                        file_put_contents(
                            $this->counterFile,
                            "callback\n",
                            FILE_APPEND | LOCK_EX,
                        );

                        if ($this->processRole === 'first') {
                            touch($this->firstCallbackFile);

                            while (! file_exists($this->releaseFirstCallbackFile)) {
                                usleep(10_000);
                            }
                        } else {
                            touch($this->releaseFirstCallbackFile);
                        }

                        return parent::resolveLineItemId(
                            $shopIdentifier,
                            $orderId,
                            $lineItemId,
                            $fulfillmentLineItemId,
                        );
                    }
                };
                $result = (new JsonRpcDispatcher($catalog))->dispatch(
                    'shipit.return_and_exchange.return.create',
                    $params,
                );

                exit(isset($result['return']['id']) ? 0 : 1);
            }

            $processIds[] = $processId;

            if ($processRole === 'first') {
                while (! File::exists($firstCallbackFile)) {
                    usleep(10_000);
                }
            }
        }

        $releaseDeadline = microtime(true) + 1;

        while (! File::exists($releaseFirstCallbackFile) && microtime(true) < $releaseDeadline) {
            usleep(10_000);
        }

        File::put($releaseFirstCallbackFile, '');

        $exitCodes = [];

        foreach ($processIds as $processId) {
            pcntl_waitpid($processId, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        $callbackExecutions = File::lines($counterFile)
            ->filter()
            ->count();

        expect($exitCodes)->toBe([0, 0])
            ->and($callbackExecutions)->toBe(1);
    } finally {
        Cache::setDefaultDriver((string) $originalCacheStore);
        File::deleteDirectory($cacheDirectory);
    }
});

function signedRpcRequest(
    array $payload,
    ?string $timestamp = null,
    ?string $nonce = null,
): TestResponse {
    $catalog = app(DemoCatalog::class);
    $verifier = app(JsonRpcRequestVerifier::class);
    $rawBody = jsonPayload($payload);
    $timestamp ??= (string) time();
    $nonce ??= 'nonce-'.\str()->ulid();
    $shopIdentifier = $payload['params']['shop'];
    $sharedSecret = $catalog->sharedSecretForShop($shopIdentifier);

    $signature = $verifier->sign(
        sharedSecret: (string) $sharedSecret,
        timestamp: $timestamp,
        nonce: $nonce,
        rawBody: $rawBody,
    );

    return test()
        ->call(
            'POST',
            '/rpc',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SHIPIT_TIMESTAMP' => $timestamp,
                'HTTP_X_SHIPIT_NONCE' => $nonce,
                'HTTP_X_SHIPIT_SIGNATURE' => $signature,
                'HTTP_X_SHIPIT_CALLER' => 'shipit-return-demo',
            ],
            $rawBody,
        );
}

function jsonPayload(array $payload): string
{
    return \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
}
