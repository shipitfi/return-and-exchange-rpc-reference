<?php

$primaryShopIdentifier = (string) env(
    'SHIPIT_RETURN_RPC_SHOP',
    'acme-demo.myshopify.com',
);

return [
    'primary_shop_identifier' => $primaryShopIdentifier,
    'timestamp_tolerance_seconds' => 300,
    'nonce_ttl_seconds' => 600,
    'idempotency_ttl_seconds' => 1800,
    'rate_limit_per_minute' => (int) env('SHIPIT_RETURN_RPC_RATE_LIMIT_PER_MINUTE', 300),
    'shops' => [
        $primaryShopIdentifier => [
            'shared_secret' => (string) env(
                'SHIPIT_RETURN_RPC_SHARED_SECRET',
                'insecure-local-demo-value',
            ),
        ],
    ],
];
