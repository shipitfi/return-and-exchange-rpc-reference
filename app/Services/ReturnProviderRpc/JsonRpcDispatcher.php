<?php

namespace App\Services\ReturnProviderRpc;

use BadMethodCallException;
use Closure;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * @phpstan-type JsonObject array<array-key, mixed>
 * @phpstan-type JsonObjectList list<JsonObject>
 */
class JsonRpcDispatcher
{
    public function __construct(
        private readonly DemoCatalog $demoCatalog,
    ) {}

    /**
     * @param  JsonObject  $params
     * @return JsonObject
     */
    public function dispatch(string $method, array $params): array
    {
        return match ($method) {
            'shipit.return_and_exchange.order.search' => [
                'orders' => $this->demoCatalog->searchOrders(
                    shopIdentifier: $this->stringParam($params, 'shop'),
                    orderNumber: $this->stringParam($params, 'order_number'),
                    emailOrPhone: $this->stringParam($params, 'email_or_phone'),
                ),
            ],
            'shipit.return_and_exchange.order.get' => [
                'order' => $this->demoCatalog->getOrder(
                    shopIdentifier: $this->stringParam($params, 'shop'),
                    orderId: $this->stringParam($params, 'order_id'),
                ),
            ],
            'shipit.return_and_exchange.order.returnable_fulfillments.get' => [
                'returnable_fulfillments' => $this->demoCatalog->getReturnableFulfillments(
                    shopIdentifier: $this->stringParam($params, 'shop'),
                    orderId: $this->stringParam($params, 'order_id'),
                ),
            ],
            'shipit.return_and_exchange.return.create' => [
                'return' => $this->rememberWriteResult(
                    method: $method,
                    shopIdentifier: $this->stringParam($params, 'shop'),
                    idempotencyKey: $this->stringParam($params, 'idempotency_key'),
                    callback: fn (): array => $this->createReturn($params),
                ),
            ],
            'shipit.return_and_exchange.exchange.create' => [
                'exchange' => $this->rememberWriteResult(
                    method: $method,
                    shopIdentifier: $this->stringParam($params, 'shop'),
                    idempotencyKey: $this->stringParam($params, 'idempotency_key'),
                    callback: fn (): array => $this->createExchange($params),
                ),
            ],
            'shipit.return_and_exchange.refund.create' => [
                'refund' => $this->rememberWriteResult(
                    method: $method,
                    shopIdentifier: $this->stringParam($params, 'shop'),
                    idempotencyKey: $this->stringParam($params, 'idempotency_key'),
                    callback: fn (): array => $this->createRefund($params),
                ),
            ],
            'shipit.return_and_exchange.order.cancel' => [
                'order_cancel' => $this->rememberWriteResult(
                    method: $method,
                    shopIdentifier: $this->stringParam($params, 'shop'),
                    idempotencyKey: $this->stringParam($params, 'idempotency_key'),
                    callback: fn (): array => $this->cancelOrder($params),
                ),
            ],
            'shipit.return_and_exchange.gift_card.create' => $this->rememberWriteResult(
                method: $method,
                shopIdentifier: $this->stringParam($params, 'shop'),
                idempotencyKey: $this->stringParam($params, 'idempotency_key'),
                callback: fn (): array => $this->createGiftCard($params),
            ),
            'shipit.return_and_exchange.order.metafield.set' => [
                'metafield' => $this->rememberWriteResult(
                    method: $method,
                    shopIdentifier: $this->stringParam($params, 'shop'),
                    idempotencyKey: $this->stringParam($params, 'idempotency_key'),
                    callback: fn (): array => $this->setOrderMetafield($params),
                ),
            ],
            'shipit.return_and_exchange.product.get' => [
                'product' => $this->demoCatalog->getProduct(
                    shopIdentifier: $this->stringParam($params, 'shop'),
                    productId: $this->stringParam($params, 'product_id'),
                ),
            ],
            'shipit.return_and_exchange.product_variant.get' => [
                'variant' => $this->demoCatalog->getVariant(
                    shopIdentifier: $this->stringParam($params, 'shop'),
                    variantId: $this->stringParam($params, 'variant_id'),
                ),
            ],
            'shipit.return_and_exchange.product.search' => [
                'products' => $this->demoCatalog->searchProducts(
                    shopIdentifier: $this->stringParam($params, 'shop'),
                    price: $this->numericParam($params, 'price'),
                    productType: $this->nullableStringParam($params, 'product_type'),
                    limit: $this->limitParam($params),
                ),
            ],
            default => throw new BadMethodCallException('Unknown RPC method: '.$method),
        };
    }

    /**
     * @param  JsonObject  $params
     * @return JsonObject
     */
    private function createReturn(array $params): array
    {
        $shopIdentifier = $this->stringParam($params, 'shop');
        $orderId = $this->stringParam($params, 'order_id');
        $returnLineItems = $this->arrayParam($params, 'return_line_items');
        $idempotencyKey = $this->stringParam($params, 'idempotency_key');

        if ($returnLineItems === []) {
            throw new InvalidArgumentException('return_line_items must contain at least one line item.');
        }

        return [
            'id' => $this->generatedId('ret', $shopIdentifier, $idempotencyKey),
            'status' => 'REQUESTED',
            'order_id' => $orderId,
            'tracking_number' => $this->nullableStringParam($params, 'tracking_number'),
            'tracking_url' => $this->nullableStringParam($params, 'tracking_url'),
            'return_line_items' => \array_map(
                fn (array $lineItem): array => $this->normalizeReturnLineItem(
                    shopIdentifier: $shopIdentifier,
                    orderId: $orderId,
                    lineItem: $lineItem,
                ),
                $returnLineItems,
            ),
        ];
    }

    /**
     * @param  JsonObject  $params
     * @return JsonObject
     */
    private function createExchange(array $params): array
    {
        $shopIdentifier = $this->stringParam($params, 'shop');
        $orderId = $this->stringParam($params, 'order_id');
        $returnLineItems = $this->arrayParam($params, 'return_line_items');
        $exchangeLineItems = $this->arrayParam($params, 'exchange_line_items');
        $idempotencyKey = $this->stringParam($params, 'idempotency_key');

        if ($returnLineItems === []) {
            throw new InvalidArgumentException('return_line_items must contain at least one line item.');
        }

        if ($exchangeLineItems === []) {
            throw new InvalidArgumentException('exchange_line_items must contain at least one variant.');
        }

        return [
            'id' => $this->generatedId('exc', $shopIdentifier, $idempotencyKey),
            'order_id' => $orderId,
            'status' => 'OPEN',
            'return_line_items' => \array_map(
                fn (array $lineItem): array => $this->normalizeReturnLineItem(
                    shopIdentifier: $shopIdentifier,
                    orderId: $orderId,
                    lineItem: $lineItem,
                ),
                $returnLineItems,
            ),
            'exchange_line_items' => \array_map(
                function (array $lineItem) use ($shopIdentifier): array {
                    return [
                        'id' => $this->generatedId(
                            'exl',
                            $shopIdentifier,
                            $this->stringParam($lineItem, 'variant_id'),
                        ),
                        'variant_id' => $this->stringParam($lineItem, 'variant_id'),
                        'quantity' => $this->quantityParam($lineItem),
                    ];
                },
                $exchangeLineItems,
            ),
        ];
    }

    /**
     * @param  JsonObject  $params
     * @return JsonObject
     */
    private function createRefund(array $params): array
    {
        $shopIdentifier = $this->stringParam($params, 'shop');
        $orderId = $this->stringParam($params, 'order_id');
        $refundLineItems = $this->arrayParam($params, 'refund_line_items');
        $idempotencyKey = $this->stringParam($params, 'idempotency_key');
        $currencyCode = $this->stringParam(
            $this->demoCatalog->getOrder($shopIdentifier, $orderId),
            'currency_code',
        );

        if ($refundLineItems === []) {
            throw new InvalidArgumentException('refund_line_items must contain at least one line item.');
        }

        $amount = 0.0;

        foreach ($refundLineItems as $lineItem) {
            $resolvedLineItemId = $this->demoCatalog->resolveLineItemId(
                shopIdentifier: $shopIdentifier,
                orderId: $orderId,
                lineItemId: $this->nullableStringParam($lineItem, 'line_item_id'),
                fulfillmentLineItemId: $this->nullableStringParam($lineItem, 'fulfillment_line_item_id'),
            );

            $orderLineItem = $this->demoCatalog->findLineItem(
                shopIdentifier: $shopIdentifier,
                orderId: $orderId,
                lineItemId: $resolvedLineItemId,
            );

            $amount += $this->numericParam($orderLineItem, 'unit_price') * $this->quantityParam($lineItem);
        }

        return [
            'id' => $this->generatedId('ref', $shopIdentifier, $idempotencyKey),
            'created_at' => now()->toIso8601String(),
            'note' => $this->nullableStringParam($params, 'note'),
            'amount' => \number_format($amount, 2, '.', ''),
            'currency_code' => $currencyCode,
        ];
    }

    /**
     * @param  JsonObject  $params
     * @return JsonObject
     */
    private function createGiftCard(array $params): array
    {
        $shopIdentifier = $this->stringParam($params, 'shop');
        $idempotencyKey = $this->stringParam($params, 'idempotency_key');
        $customerId = $this->stringParam($params, 'customer_id');

        return [
            'gift_card' => [
                'id' => $this->generatedId('gc', $shopIdentifier, $idempotencyKey),
                'customer_id' => $customerId,
                'initial_value' => $this->stringParam($params, 'initial_value'),
                'expires_on' => null,
                'note' => $this->nullableStringParam($params, 'note'),
            ],
            'gift_card_code' => 'SHIPIT-'.\strtoupper(\substr(\sha1($shopIdentifier.$idempotencyKey), 0, 4)).'-'.\strtoupper(\substr(\sha1($customerId.$idempotencyKey), 0, 4)),
        ];
    }

    /**
     * @param  JsonObject  $params
     * @return JsonObject
     */
    private function cancelOrder(array $params): array
    {
        $shopIdentifier = $this->stringParam($params, 'shop');
        $orderId = $this->stringParam($params, 'order_id');
        $idempotencyKey = $this->stringParam($params, 'idempotency_key');

        $this->demoCatalog->getOrder($shopIdentifier, $orderId);

        return [
            'job' => [
                'id' => $this->generatedId('job', $shopIdentifier, $idempotencyKey),
                'done' => false,
            ],
        ];
    }

    /**
     * @param  JsonObject  $params
     * @return JsonObject
     */
    private function setOrderMetafield(array $params): array
    {
        $shopIdentifier = $this->stringParam($params, 'shop');
        $namespace = $this->stringParam($params, 'namespace');
        $key = $this->stringParam($params, 'key');

        return [
            'id' => $this->demoCatalog->buildMetafieldId($shopIdentifier, $namespace, $key),
            'namespace' => $namespace,
            'key' => $key,
            'value' => $params['value'],
            'type' => $this->nullableStringParam($params, 'type') ?? 'json',
        ];
    }

    /**
     * @param  Closure(): JsonObject  $callback
     * @return JsonObject
     */
    private function rememberWriteResult(
        string $method,
        string $shopIdentifier,
        string $idempotencyKey,
        Closure $callback,
    ): array {
        $cacheKey = 'shipit-return-rpc:idempotency:'.$shopIdentifier.':'.$method.':'.$idempotencyKey;

        return Cache::remember(
            $cacheKey,
            now()->addSeconds($this->configuredPositiveInteger('idempotency_ttl_seconds', 1800)),
            $callback,
        );
    }

    /**
     * @param  JsonObject  $lineItem
     * @return JsonObject
     */
    private function normalizeReturnLineItem(
        string $shopIdentifier,
        string $orderId,
        array $lineItem,
    ): array {
        return [
            'line_item_id' => $this->demoCatalog->resolveLineItemId(
                shopIdentifier: $shopIdentifier,
                orderId: $orderId,
                lineItemId: $this->nullableStringParam($lineItem, 'line_item_id'),
                fulfillmentLineItemId: $this->nullableStringParam($lineItem, 'fulfillment_line_item_id'),
            ),
            'fulfillment_line_item_id' => $this->nullableStringParam($lineItem, 'fulfillment_line_item_id'),
            'quantity' => $this->quantityParam($lineItem),
            'return_reason' => $this->nullableStringParam($lineItem, 'return_reason'),
            'return_reason_note' => $this->nullableStringParam($lineItem, 'return_reason_note'),
        ];
    }

    /** @param JsonObject $params */
    private function stringParam(array $params, string $key): string
    {
        $value = $params[$key] ?? null;

        if (! \is_string($value) || \trim($value) === '') {
            throw new InvalidArgumentException('The '.$key.' field must be a non-empty string.');
        }

        return $value;
    }

    /** @param JsonObject $params */
    private function nullableStringParam(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! \is_string($value)) {
            throw new InvalidArgumentException('The '.$key.' field must be a string when present.');
        }

        return $value;
    }

    /**
     * @param  JsonObject  $params
     * @return JsonObjectList
     */
    private function arrayParam(array $params, string $key): array
    {
        $value = $params[$key] ?? null;

        if (! \is_array($value) || ! \array_is_list($value)) {
            throw new InvalidArgumentException('The '.$key.' field must be an array.');
        }

        foreach ($value as $item) {
            if (! \is_array($item)) {
                throw new InvalidArgumentException(
                    'The '.$key.' field must contain only objects.',
                );
            }
        }

        return $value;
    }

    /** @param JsonObject $params */
    private function quantityParam(array $params): int
    {
        $quantity = $params['quantity'] ?? null;

        if (! \is_int($quantity) || $quantity < 1) {
            throw new InvalidArgumentException('The quantity field must be an integer greater than or equal to 1.');
        }

        return $quantity;
    }

    /** @param JsonObject $params */
    private function numericParam(array $params, string $key): float
    {
        $value = $params[$key] ?? null;

        if (! \is_int($value) && ! \is_float($value) && (! \is_string($value) || ! \is_numeric($value))) {
            throw new InvalidArgumentException('The '.$key.' field must be numeric.');
        }

        return (float) $value;
    }

    /** @param JsonObject $params */
    private function limitParam(array $params): int
    {
        $limit = $params['limit'] ?? 10;

        if (! \is_int($limit) || $limit < 1 || $limit > 25) {
            throw new InvalidArgumentException('The limit field must be an integer between 1 and 25.');
        }

        return $limit;
    }

    private function configuredPositiveInteger(string $key, int $default): int
    {
        $value = config('shipit_return_rpc.'.$key, $default);

        return \is_int($value) && $value > 0 ? $value : $default;
    }

    private function generatedId(string $prefix, string $shopIdentifier, string $source): string
    {
        return $prefix.'_'.\substr(\sha1($shopIdentifier.':'.$source), 0, 12);
    }
}
