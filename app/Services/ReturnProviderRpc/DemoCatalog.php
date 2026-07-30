<?php

namespace App\Services\ReturnProviderRpc;

use OutOfBoundsException;

/**
 * @phpstan-type JsonObject array<array-key, mixed>
 * @phpstan-type JsonObjectList list<JsonObject>
 * @phpstan-type Variant array{id: string, title: string, sku: string, price: string, available_for_sale: bool, inventory_policy: string, image: array{url: string}, selected_options: list<array{name: string, value: string}>}
 * @phpstan-type Product array{id: string, title: string, product_type: string, tags: list<string>, variants: list<Variant>}
 * @phpstan-type OrderLineItem array{line_item_id: string, unit_price: string, ...}
 * @phpstan-type ReturnableLineItem array{fulfillment_line_item_id: string, line_item_id: string, ...}
 * @phpstan-type ReturnableFulfillment array{id: string, fulfillment_id: string, line_items: list<ReturnableLineItem>}
 * @phpstan-type Order array{id: string, name: string, email: string, phone: string, currency_code: string, line_items: list<OrderLineItem>, returnable_fulfillments: list<ReturnableFulfillment>, ...}
 * @phpstan-type ShopDefinition array{shared_secret: string}
 */
class DemoCatalog
{
    public function sampleShopIdentifier(): string
    {
        return \array_key_first($this->shopDefinitions()) ?? 'acme-demo.myshopify.com';
    }

    /** @return Order */
    public function sampleOrder(string $shopIdentifier): array
    {
        return $this->ordersForShop($shopIdentifier)[0];
    }

    public function sharedSecretForShop(string $shopIdentifier): ?string
    {
        return $this->shopDefinitions()[$shopIdentifier]['shared_secret'] ?? null;
    }

    /** @return list<Order> */
    public function searchOrders(
        string $shopIdentifier,
        string $orderNumber,
        string $emailOrPhone,
    ): array {
        return \array_values(\array_filter(
            $this->ordersForShop($shopIdentifier),
            function (array $order) use ($orderNumber, $emailOrPhone): bool {
                if ($order['name'] !== $orderNumber) {
                    return false;
                }

                return $this->normalizeLocator($order['email']) === $this->normalizeLocator($emailOrPhone)
                    || $this->normalizeLocator($order['phone']) === $this->normalizeLocator($emailOrPhone);
            },
        ));
    }

    /** @return Order */
    public function getOrder(string $shopIdentifier, string $orderId): array
    {
        foreach ($this->ordersForShop($shopIdentifier) as $order) {
            if ($order['id'] === $orderId) {
                return $order;
            }
        }

        throw new OutOfBoundsException('Order not found for the given shop.');
    }

    /** @return list<ReturnableFulfillment> */
    public function getReturnableFulfillments(
        string $shopIdentifier,
        string $orderId,
    ): array {
        return $this->getOrder($shopIdentifier, $orderId)['returnable_fulfillments'];
    }

    /** @return Product */
    public function getProduct(string $shopIdentifier, string $productId): array
    {
        foreach ($this->productsForShop($shopIdentifier) as $product) {
            if ($product['id'] === $productId) {
                return $product;
            }
        }

        throw new OutOfBoundsException('Product not found for the given shop.');
    }

    /** @return Variant */
    public function getVariant(string $shopIdentifier, string $variantId): array
    {
        foreach ($this->productsForShop($shopIdentifier) as $product) {
            foreach ($product['variants'] as $variant) {
                if ($variant['id'] === $variantId) {
                    return $variant;
                }
            }
        }

        throw new OutOfBoundsException('Variant not found for the given shop.');
    }

    /** @return list<Product> */
    public function searchProducts(
        string $shopIdentifier,
        float $price,
        ?string $productType,
        int $limit,
    ): array {
        $products = \array_values(\array_filter(
            $this->productsForShop($shopIdentifier),
            function (array $product) use ($price, $productType): bool {
                if ($productType !== null && $product['product_type'] !== $productType) {
                    return false;
                }

                foreach ($product['variants'] as $variant) {
                    if (\abs((float) $variant['price'] - $price) <= 25.0) {
                        return true;
                    }
                }

                return false;
            },
        ));

        return \array_slice($products, 0, $limit);
    }

    public function resolveLineItemId(
        string $shopIdentifier,
        string $orderId,
        ?string $lineItemId,
        ?string $fulfillmentLineItemId,
    ): string {
        if (\is_string($lineItemId) && $lineItemId !== '') {
            return $lineItemId;
        }

        if (! \is_string($fulfillmentLineItemId) || $fulfillmentLineItemId === '') {
            throw new OutOfBoundsException(
                'Either line_item_id or fulfillment_line_item_id is required.',
            );
        }

        foreach ($this->getReturnableFulfillments($shopIdentifier, $orderId) as $fulfillment) {
            foreach ($fulfillment['line_items'] as $lineItem) {
                if ($lineItem['fulfillment_line_item_id'] === $fulfillmentLineItemId) {
                    return $lineItem['line_item_id'];
                }
            }
        }

        throw new OutOfBoundsException('Fulfillment line item not found.');
    }

    /** @return OrderLineItem */
    public function findLineItem(
        string $shopIdentifier,
        string $orderId,
        string $lineItemId,
    ): array {
        foreach ($this->getOrder($shopIdentifier, $orderId)['line_items'] as $lineItem) {
            if ($lineItem['line_item_id'] === $lineItemId) {
                return $lineItem;
            }
        }

        throw new OutOfBoundsException('Line item not found for the order.');
    }

    public function buildMetafieldId(
        string $shopIdentifier,
        string $namespace,
        string $key,
    ): string {
        return 'mf_'.\substr(\sha1($shopIdentifier.':'.$namespace.':'.$key), 0, 12);
    }

    /** @return array<string, ShopDefinition> */
    private function shopDefinitions(): array
    {
        $configuredShops = config('shipit_return_rpc.shops', []);

        if (! \is_array($configuredShops)) {
            return [];
        }

        $shopDefinitions = [];

        foreach ($configuredShops as $shopIdentifier => $definition) {
            if (! \is_string($shopIdentifier) || ! \is_array($definition)) {
                continue;
            }

            $sharedSecret = $definition['shared_secret'] ?? null;

            if (\is_string($sharedSecret)) {
                $shopDefinitions[$shopIdentifier] = ['shared_secret' => $sharedSecret];
            }
        }

        return $shopDefinitions;
    }

    /** @return list<Order> */
    private function ordersForShop(string $shopIdentifier): array
    {
        return $this->catalog()[$shopIdentifier]['orders']
            ?? throw new OutOfBoundsException('Shop not found in the demo catalog.');
    }

    /** @return list<Product> */
    private function productsForShop(string $shopIdentifier): array
    {
        return $this->catalog()[$shopIdentifier]['products']
            ?? throw new OutOfBoundsException('Shop not found in the demo catalog.');
    }

    /**
     * @return array<string, array{products: list<Product>, orders: list<Order>}>
     */
    private function catalog(): array
    {
        $configuredShopIdentifier = config(
            'shipit_return_rpc.primary_shop_identifier',
            'acme-demo.myshopify.com',
        );
        $primaryShopIdentifier = \is_string($configuredShopIdentifier)
            ? $configuredShopIdentifier
            : 'acme-demo.myshopify.com';

        return [
            $primaryShopIdentifier => [
                'products' => $this->buildProducts(
                    prefix: 'acme',
                    heroBaseUrl: 'https://cdn.example.com/acme',
                    currencyCode: 'EUR',
                ),
                'orders' => $this->buildOrders(
                    prefix: 'acme',
                    customerId: 'cust_acme_1001',
                    email: 'alex.customer@example.com',
                    phone: '+358401234567',
                    city: 'Helsinki',
                    country: 'Finland',
                    countryCode: 'FI',
                    provinceCode: '18',
                    zip: '00100',
                    discountCode: 'SUMMER10',
                    tags: ['returned-before'],
                ),
            ],
            'fjord-demo.myshopify.com' => [
                'products' => $this->buildProducts(
                    prefix: 'fjord',
                    heroBaseUrl: 'https://cdn.example.com/fjord',
                    currencyCode: 'SEK',
                ),
                'orders' => $this->buildOrders(
                    prefix: 'fjord',
                    customerId: 'cust_fjord_1001',
                    email: 'pat.retail@example.com',
                    phone: '+46701234567',
                    city: 'Stockholm',
                    country: 'Sweden',
                    countryCode: 'SE',
                    provinceCode: 'AB',
                    zip: '11122',
                    discountCode: 'NORDIC15',
                    tags: ['vip'],
                ),
            ],
        ];
    }

    /** @return list<Product> */
    private function buildProducts(
        string $prefix,
        string $heroBaseUrl,
        string $currencyCode,
    ): array {
        return [
            [
                'id' => $prefix.'_prod_blue_tee',
                'title' => 'Blue T-Shirt',
                'product_type' => 'Shirts',
                'tags' => ['tops', 'cotton'],
                'variants' => [
                    $this->buildVariant(
                        id: $prefix.'_var_blue_tee_m',
                        title: 'M',
                        sku: \strtoupper($prefix).'-TSHIRT-BLUE-M',
                        price: $currencyCode === 'EUR' ? '89.90' : '949.00',
                        imageUrl: $heroBaseUrl.'/products/blue-shirt.jpg',
                        optionValue: 'M',
                    ),
                    $this->buildVariant(
                        id: $prefix.'_var_blue_tee_l',
                        title: 'L',
                        sku: \strtoupper($prefix).'-TSHIRT-BLUE-L',
                        price: $currencyCode === 'EUR' ? '89.90' : '949.00',
                        imageUrl: $heroBaseUrl.'/products/blue-shirt.jpg',
                        optionValue: 'L',
                    ),
                ],
            ],
            [
                'id' => $prefix.'_prod_green_hoodie',
                'title' => 'Green Hoodie',
                'product_type' => 'Outerwear',
                'tags' => ['hoodies', 'winter'],
                'variants' => [
                    $this->buildVariant(
                        id: $prefix.'_var_green_hoodie_m',
                        title: 'M',
                        sku: \strtoupper($prefix).'-HOODIE-GREEN-M',
                        price: $currencyCode === 'EUR' ? '119.90' : '1249.00',
                        imageUrl: $heroBaseUrl.'/products/green-hoodie.jpg',
                        optionValue: 'M',
                    ),
                    $this->buildVariant(
                        id: $prefix.'_var_green_hoodie_l',
                        title: 'L',
                        sku: \strtoupper($prefix).'-HOODIE-GREEN-L',
                        price: $currencyCode === 'EUR' ? '119.90' : '1249.00',
                        imageUrl: $heroBaseUrl.'/products/green-hoodie.jpg',
                        optionValue: 'L',
                    ),
                ],
            ],
            [
                'id' => $prefix.'_prod_black_cap',
                'title' => 'Black Cap',
                'product_type' => 'Accessories',
                'tags' => ['caps'],
                'variants' => [
                    $this->buildVariant(
                        id: $prefix.'_var_black_cap_os',
                        title: 'One Size',
                        sku: \strtoupper($prefix).'-CAP-BLACK-OS',
                        price: $currencyCode === 'EUR' ? '39.90' : '429.00',
                        imageUrl: $heroBaseUrl.'/products/black-cap.jpg',
                        optionValue: 'One Size',
                    ),
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $tags
     * @return list<Order>
     */
    private function buildOrders(
        string $prefix,
        string $customerId,
        string $email,
        string $phone,
        string $city,
        string $country,
        string $countryCode,
        string $provinceCode,
        string $zip,
        string $discountCode,
        array $tags,
    ): array {
        $currencyCode = $prefix === 'acme' ? 'EUR' : 'SEK';
        $shirtAmount = $currencyCode === 'EUR' ? '89.90' : '949.00';
        $hoodieAmount = $currencyCode === 'EUR' ? '119.90' : '1249.00';

        return [
            [
                'id' => $prefix.'_ord_1001',
                'customer_id' => $customerId,
                'name' => '#2149',
                'email' => $email,
                'phone' => $phone,
                'created_at' => '2026-06-09T12:00:00Z',
                'cancelled_at' => null,
                'closed_at' => null,
                'display_financial_status' => 'PAID',
                'display_fulfillment_status' => 'FULFILLED',
                'amount' => $shirtAmount,
                'currency_code' => $currencyCode,
                'shipping_address' => [
                    'name' => 'Alex Customer',
                    'first_name' => 'Alex',
                    'last_name' => 'Customer',
                    'address1' => 'Testikatu 1',
                    'address2' => null,
                    'city' => $city,
                    'province_code' => $provinceCode,
                    'country' => $country,
                    'country_code' => $countryCode,
                    'zip' => $zip,
                    'phone' => $phone,
                ],
                'billing_address' => null,
                'discount_codes' => [$discountCode],
                'tags' => $tags,
                'metafields' => [
                    'shipit.return_id' => $prefix.'_ret_5001',
                ],
                'line_items' => [
                    [
                        'line_item_id' => $prefix.'_li_1',
                        'id' => $prefix.'_li_1',
                        'title' => 'Blue T-Shirt',
                        'name' => 'Blue T-Shirt / M',
                        'quantity' => 1,
                        'sku' => \strtoupper($prefix).'-TSHIRT-BLUE-M',
                        'variant_id' => $prefix.'_var_blue_tee_m',
                        'variant_title' => 'M',
                        'product_id' => $prefix.'_prod_blue_tee',
                        'product_type' => 'Shirts',
                        'product_tags' => ['tops', 'cotton'],
                        'image' => [
                            'url' => 'https://cdn.example.com/'.$prefix.'/products/blue-shirt.jpg',
                        ],
                        'unit_price' => $shirtAmount,
                    ],
                ],
                'returnable_fulfillments' => [
                    [
                        'id' => $prefix.'_rf_1',
                        'fulfillment_id' => $prefix.'_ful_1',
                        'line_items' => [
                            [
                                'fulfillment_line_item_id' => $prefix.'_fli_1',
                                'line_item_id' => $prefix.'_li_1',
                                'name' => 'Blue T-Shirt / M',
                                'sku' => \strtoupper($prefix).'-TSHIRT-BLUE-M',
                                'variant_id' => $prefix.'_var_blue_tee_m',
                                'product_id' => $prefix.'_prod_blue_tee',
                                'returnable_quantity' => 1,
                                'amount' => $shirtAmount,
                                'currency_code' => $currencyCode,
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => $prefix.'_ord_1002',
                'customer_id' => $customerId,
                'name' => '#2150',
                'email' => $email,
                'phone' => $phone,
                'created_at' => '2026-06-10T09:15:00Z',
                'cancelled_at' => null,
                'closed_at' => null,
                'display_financial_status' => 'PAID',
                'display_fulfillment_status' => 'FULFILLED',
                'amount' => $hoodieAmount,
                'currency_code' => $currencyCode,
                'shipping_address' => [
                    'name' => 'Alex Customer',
                    'first_name' => 'Alex',
                    'last_name' => 'Customer',
                    'address1' => 'Testikatu 1',
                    'address2' => null,
                    'city' => $city,
                    'province_code' => $provinceCode,
                    'country' => $country,
                    'country_code' => $countryCode,
                    'zip' => $zip,
                    'phone' => $phone,
                ],
                'billing_address' => null,
                'discount_codes' => [],
                'tags' => [],
                'metafields' => [],
                'line_items' => [
                    [
                        'line_item_id' => $prefix.'_li_2',
                        'id' => $prefix.'_li_2',
                        'title' => 'Green Hoodie',
                        'name' => 'Green Hoodie / L',
                        'quantity' => 1,
                        'sku' => \strtoupper($prefix).'-HOODIE-GREEN-L',
                        'variant_id' => $prefix.'_var_green_hoodie_l',
                        'variant_title' => 'L',
                        'product_id' => $prefix.'_prod_green_hoodie',
                        'product_type' => 'Outerwear',
                        'product_tags' => ['hoodies', 'winter'],
                        'image' => [
                            'url' => 'https://cdn.example.com/'.$prefix.'/products/green-hoodie.jpg',
                        ],
                        'unit_price' => $hoodieAmount,
                    ],
                ],
                'returnable_fulfillments' => [
                    [
                        'id' => $prefix.'_rf_2',
                        'fulfillment_id' => $prefix.'_ful_2',
                        'line_items' => [
                            [
                                'fulfillment_line_item_id' => $prefix.'_fli_2',
                                'line_item_id' => $prefix.'_li_2',
                                'name' => 'Green Hoodie / L',
                                'sku' => \strtoupper($prefix).'-HOODIE-GREEN-L',
                                'variant_id' => $prefix.'_var_green_hoodie_l',
                                'product_id' => $prefix.'_prod_green_hoodie',
                                'returnable_quantity' => 1,
                                'amount' => $hoodieAmount,
                                'currency_code' => $currencyCode,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return Variant */
    private function buildVariant(
        string $id,
        string $title,
        string $sku,
        string $price,
        string $imageUrl,
        string $optionValue,
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'sku' => $sku,
            'price' => $price,
            'available_for_sale' => true,
            'inventory_policy' => 'DENY',
            'image' => [
                'url' => $imageUrl,
            ],
            'selected_options' => [
                [
                    'name' => 'Size',
                    'value' => $optionValue,
                ],
            ],
        ];
    }

    private function normalizeLocator(string $locator): string
    {
        $trimmed = \mb_strtolower(\trim($locator));

        if (\str_contains($trimmed, '@')) {
            return $trimmed;
        }

        return (string) \preg_replace('/\D+/', '', $trimmed);
    }
}
