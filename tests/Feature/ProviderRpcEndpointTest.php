<?php

use App\Services\ReturnProviderRpc\DemoCatalog;
use App\Services\ReturnProviderRpc\JsonRpcDispatcher;
use App\Services\ReturnProviderRpc\JsonRpcRequestVerifier;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
