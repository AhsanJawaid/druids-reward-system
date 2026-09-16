<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyGraphQLException;
use App\Models\GraphqlLog;
use App\Models\Reward;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ShopifyGraphqlClient
{
    public const SHOP_QUERY = <<<'GQL'
query shop {
  shop {
    name
    email
    myshopifyDomain
    currencyCode
  }
}
GQL;

    public const WEBHOOK_CREATE = <<<'GQL'
mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
  webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
    webhookSubscription {
      id
      topic
      uri
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

    public const DISCOUNT_CREATE = <<<'GQL'
mutation discountCodeBasicCreate($basicCodeDiscount: DiscountCodeBasicInput!) {
  discountCodeBasicCreate(basicCodeDiscount: $basicCodeDiscount) {
    codeDiscountNode {
      id
      codeDiscount {
        ... on DiscountCodeBasic {
          title
          startsAt
          endsAt
          codes(first: 1) {
            nodes {
              code
            }
          }
        }
      }
    }
    userErrors {
      field
      message
      code
    }
  }
}
GQL;

    public const FREE_SHIPPING_CREATE = <<<'GQL'
mutation discountCodeFreeShippingCreate($freeShippingCodeDiscount: DiscountCodeFreeShippingInput!) {
  discountCodeFreeShippingCreate(freeShippingCodeDiscount: $freeShippingCodeDiscount) {
    codeDiscountNode {
      id
      codeDiscount {
        ... on DiscountCodeFreeShipping {
          title
          codes(first: 1) {
            nodes {
              code
            }
          }
        }
      }
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

    public const GIFT_CARD_CREATE = <<<'GQL'
mutation giftCardCreate($input: GiftCardCreateInput!) {
  giftCardCreate(input: $input) {
    giftCard {
      id
      initialValue {
        amount
        currencyCode
      }
    }
    giftCardCode
    userErrors {
      field
      message
    }
  }
}
GQL;

    public const PRODUCT_IN_COLLECTION = <<<'GQL'
query productInCollection($productId: ID!, $collectionId: ID!) {
  product(id: $productId) {
    id
    title
    inCollection(id: $collectionId)
  }
}
GQL;

    public const COLLECTION_PRODUCTS = <<<'GQL'
query collectionProducts($id: ID!) {
  collection(id: $id) {
    id
    title
    products(first: 50) {
      nodes {
        id
        title
        handle
      }
    }
  }
}
GQL;

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function mutate(string $operation, string $query, array $variables = [], bool $forceLive = false): array
    {
        if (! $forceLive && $this->shouldMock()) {
            $response = $this->mockResponse($operation, $variables);
            $this->log($operation, $query, $variables, $response, 200, true);

            return $response;
        }

        $domain = ShopifyConfig::storeDomain();
        $version = ShopifyConfig::apiVersion();
        $token = ShopifyConfig::accessToken();
        if ($token === '' || $domain === '') {
            throw new ShopifyGraphQLException('Add your shop domain and Admin API access token first.');
        }

        $payload = ['query' => $query];
        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        $http = Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
        ])->timeout(20)->post("https://{$domain}/admin/api/{$version}/graphql.json", $payload);

        $body = $http->json() ?? ['errors' => [['message' => 'Empty Shopify response']]];
        $this->log($operation, $query, $variables, $body, $http->status(), false);

        if ($http->failed()) {
            if ($http->status() === 401 && $operation === 'webhookSubscriptionCreate') {
                throw new ShopifyGraphQLException(
                    'Shopify returned 401 for webhook create. Custom apps usually cannot register webhooks through the API. Add Order payment, Refund create, Customer creation, and Customer update webhooks in Shopify Admin (steps on the Shopify settings page).'
                );
            }
            if ($http->status() === 401) {
                throw new ShopifyGraphQLException(
                    'Shopify returned 401 Unauthorized. Re-install the custom app, copy a new Admin API access token, and paste it on this page.'
                );
            }
            throw new ShopifyGraphQLException('Shopify Admin GraphQL HTTP '.$http->status());
        }

        return $body;
    }

    /**
     * Create the real Shopify object for a redeemed reward.
     *
     * @return array{code: string, id: string, object_type: string, mocked: bool, raw: array<string, mixed>}
     */
    public function issueReward(Reward $reward, string $code, int $expiresInDays, ?string $productId = null): array
    {
        return match ($reward->slug) {
            'free_shipping' => $this->createFreeShippingCode($reward, $code, $expiresInDays),
            'gift_card' => $this->createGiftCard($reward),
            'free_product' => $this->createFreeProductDiscount($reward, $code, $expiresInDays, (string) $productId),
            default => $this->createDiscountCode($reward, $code, $expiresInDays),
        };
    }

    /**
     * @return array{code: string, id: string, object_type: string, mocked: bool, raw: array<string, mixed>}
     */
    public function createDiscountCode(Reward $reward, string $code, int $expiresInDays): array
    {
        $startsAt = now()->toIso8601String();
        $endsAt = now()->addDays($expiresInDays)->toIso8601String();

        $value = $reward->discount_type === 'percentage'
            ? ['percentage' => ((float) $reward->discount_value) / 100]
            : ['discountAmount' => [
                'amount' => (float) $reward->discount_value,
                'appliesOnEachItem' => false,
            ]];

        $variables = [
            'basicCodeDiscount' => [
                'title' => 'Rewards · '.$reward->name,
                'code' => $code,
                'startsAt' => $startsAt,
                'endsAt' => $endsAt,
                'usageLimit' => 1,
                'appliesOncePerCustomer' => true,
                'customerSelection' => ['all' => true],
                'customerGets' => [
                    'value' => $value,
                    'items' => ['all' => true],
                ],
            ],
        ];

        $raw = $this->mutate('discountCodeBasicCreate', self::DISCOUNT_CREATE, $variables);
        $this->throwUserErrors($raw, 'data.discountCodeBasicCreate.userErrors');
        $payload = data_get($raw, 'data.discountCodeBasicCreate');
        $id = (string) data_get($payload, 'codeDiscountNode.id', 'gid://shopify/DiscountCodeNode/mock');
        $issued = (string) data_get($payload, 'codeDiscountNode.codeDiscount.codes.nodes.0.code', $code);

        return [
            'code' => $issued,
            'id' => $id,
            'object_type' => 'discount_code',
            'mocked' => $this->shouldMock(),
            'raw' => $raw,
        ];
    }

    /**
     * @return array{code: string, id: string, object_type: string, mocked: bool, raw: array<string, mixed>}
     */
    public function createFreeShippingCode(Reward $reward, string $code, int $expiresInDays): array
    {
        $variables = [
            'freeShippingCodeDiscount' => [
                'title' => 'Rewards · '.$reward->name,
                'code' => $code,
                'startsAt' => now()->toIso8601String(),
                'endsAt' => now()->addDays($expiresInDays)->toIso8601String(),
                'usageLimit' => 1,
                'appliesOncePerCustomer' => true,
                'customerSelection' => ['all' => true],
                'destination' => ['all' => true],
            ],
        ];

        $raw = $this->mutate('discountCodeFreeShippingCreate', self::FREE_SHIPPING_CREATE, $variables);
        $this->throwUserErrors($raw, 'data.discountCodeFreeShippingCreate.userErrors');
        $payload = data_get($raw, 'data.discountCodeFreeShippingCreate');
        $id = (string) data_get($payload, 'codeDiscountNode.id', 'gid://shopify/DiscountCodeNode/mock-shipping');
        $issued = (string) data_get($payload, 'codeDiscountNode.codeDiscount.codes.nodes.0.code', $code);

        return [
            'code' => $issued,
            'id' => $id,
            'object_type' => 'discount_code',
            'mocked' => $this->shouldMock(),
            'raw' => $raw,
        ];
    }

    /**
     * @return array{code: string, id: string, object_type: string, mocked: bool, raw: array<string, mixed>}
     */
    public function createFreeProductDiscount(Reward $reward, string $code, int $expiresInDays, string $productId): array
    {
        $variables = [
            'basicCodeDiscount' => [
                'title' => 'Rewards · Free product',
                'code' => $code,
                'startsAt' => now()->toIso8601String(),
                'endsAt' => now()->addDays($expiresInDays)->toIso8601String(),
                'usageLimit' => 1,
                'appliesOncePerCustomer' => true,
                'customerSelection' => ['all' => true],
                'customerGets' => [
                    'value' => ['percentage' => 1.0],
                    'items' => [
                        'products' => [
                            'productsToAdd' => [$productId],
                        ],
                    ],
                ],
            ],
        ];

        $raw = $this->mutate('discountCodeBasicCreate', self::DISCOUNT_CREATE, $variables);
        $this->throwUserErrors($raw, 'data.discountCodeBasicCreate.userErrors');
        $payload = data_get($raw, 'data.discountCodeBasicCreate');
        $id = (string) data_get($payload, 'codeDiscountNode.id', 'gid://shopify/DiscountCodeNode/mock-product');
        $issued = (string) data_get($payload, 'codeDiscountNode.codeDiscount.codes.nodes.0.code', $code);

        return [
            'code' => $issued,
            'id' => $id,
            'object_type' => 'discount_code',
            'mocked' => $this->shouldMock(),
            'raw' => $raw,
        ];
    }

    /**
     * @return array{code: string, id: string, object_type: string, mocked: bool, raw: array<string, mixed>}
     */
    public function createGiftCard(Reward $reward): array
    {
        $variables = [
            'input' => [
                'initialValue' => number_format((float) $reward->discount_value, 2, '.', ''),
                'note' => 'Rewards System · '.$reward->name,
            ],
        ];

        $raw = $this->mutate('giftCardCreate', self::GIFT_CARD_CREATE, $variables);
        $this->throwUserErrors($raw, 'data.giftCardCreate.userErrors');
        $payload = data_get($raw, 'data.giftCardCreate');
        $id = (string) data_get($payload, 'giftCard.id', 'gid://shopify/GiftCard/mock');
        $code = (string) data_get($payload, 'giftCardCode', 'GC-MOCK');

        return [
            'code' => $code,
            'id' => $id,
            'object_type' => 'gift_card',
            'mocked' => $this->shouldMock(),
            'raw' => $raw,
        ];
    }

    public function productBelongsToCollection(string $productId, string $collectionId): bool
    {
        $collectionId = $this->normalizeCollectionGid($collectionId);
        $productId = str_starts_with($productId, 'gid://') ? $productId : 'gid://shopify/Product/'.$productId;

        if ($this->shouldMock()) {
            return ! str_contains($productId, 'ineligible') && $collectionId !== '';
        }

        $raw = $this->mutate('productInCollection', self::PRODUCT_IN_COLLECTION, [
            'productId' => $productId,
            'collectionId' => $collectionId,
        ], true);
        $this->throwUserErrors($raw, 'errors');

        return (bool) data_get($raw, 'data.product.inCollection');
    }

    /**
     * @return list<array{id: string, title: string, handle: string}>
     */
    public function collectionProducts(string $collectionId): array
    {
        $collectionId = $this->normalizeCollectionGid($collectionId);
        if ($collectionId === '') {
            return [];
        }

        if ($this->shouldMock()) {
            return [
                ['id' => 'gid://shopify/Product/1001', 'title' => 'Eligible sample mug', 'handle' => 'eligible-mug'],
                ['id' => 'gid://shopify/Product/1002', 'title' => 'Eligible sample tote', 'handle' => 'eligible-tote'],
            ];
        }

        $raw = $this->mutate('collectionProducts', self::COLLECTION_PRODUCTS, ['id' => $collectionId], true);
        $this->throwUserErrors($raw, 'errors');

        $nodes = data_get($raw, 'data.collection.products.nodes', []) ?: [];

        return collect($nodes)->map(fn ($node) => [
            'id' => (string) data_get($node, 'id'),
            'title' => (string) data_get($node, 'title'),
            'handle' => (string) data_get($node, 'handle'),
        ])->all();
    }

    private function normalizeCollectionGid(string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            return '';
        }
        if (str_starts_with($id, 'gid://')) {
            return $id;
        }
        if (is_numeric($id)) {
            return 'gid://shopify/Collection/'.$id;
        }

        return $id;
    }

    /**
     * @return array{name: string, domain: string, raw: array<string, mixed>}
     */
    public function pingShop(): array
    {
        $raw = $this->mutate('shop', self::SHOP_QUERY, [], true);
        $this->throwUserErrors($raw, 'errors');
        $name = (string) data_get($raw, 'data.shop.name');
        if ($name === '') {
            throw new ShopifyGraphQLException('Shopify did not return a shop. Check the token and scopes.');
        }

        return [
            'name' => $name,
            'domain' => (string) data_get($raw, 'data.shop.myshopifyDomain'),
            'raw' => $raw,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function registerOrderWebhooks(string $callbackUrl): array
    {
        $topics = [
            'ORDERS_PAID',
            'REFUNDS_CREATE',
            'CUSTOMERS_CREATE',
            'CUSTOMERS_UPDATE',
            'CUSTOMERS_EMAIL_MARKETING_CONSENT_UPDATE',
        ];
        $created = [];

        foreach ($topics as $topic) {
            $raw = $this->mutate('webhookSubscriptionCreate', self::WEBHOOK_CREATE, [
                'topic' => $topic,
                'webhookSubscription' => [
                    'callbackUrl' => $callbackUrl,
                    'format' => 'JSON',
                ],
            ], true);
            $errors = collect(data_get($raw, 'data.webhookSubscriptionCreate.userErrors', []))->pluck('message')->filter();
            $already = $errors->contains(fn ($message) => str_contains(strtolower((string) $message), 'already')
                || str_contains(strtolower((string) $message), 'taken'));
            if ($errors->isNotEmpty() && ! $already) {
                $this->throwUserErrors($raw, 'data.webhookSubscriptionCreate.userErrors');
            }
            $created[] = [
                'topic' => $topic,
                'id' => data_get($raw, 'data.webhookSubscriptionCreate.webhookSubscription.id'),
                'uri' => data_get($raw, 'data.webhookSubscriptionCreate.webhookSubscription.uri') ?: $callbackUrl,
                'already' => $already,
            ];
        }

        return $created;
    }

    public function shouldMock(): bool
    {
        if (! ShopifyConfig::isLive()) {
            return true;
        }

        return ! ShopifyConfig::hasToken();
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function mockResponse(string $operation, array $variables): array
    {
        $code = (string) data_get($variables, 'basicCodeDiscount.code', 'RWD-MOCK');
        $id = 'gid://shopify/DiscountCodeNode/'.Str::lower(Str::random(10));

        if ($operation === 'shop') {
            return [
                'data' => [
                    'shop' => [
                        'name' => 'Demo store',
                        'email' => 'merchant@example.com',
                        'myshopifyDomain' => ShopifyConfig::storeDomain() ?: 'demo-store.myshopify.com',
                        'currencyCode' => 'USD',
                    ],
                ],
                'mocked' => true,
            ];
        }

        if ($operation === 'webhookSubscriptionCreate') {
            return [
                'data' => [
                    'webhookSubscriptionCreate' => [
                        'webhookSubscription' => [
                            'id' => 'gid://shopify/WebhookSubscription/'.Str::lower(Str::random(8)),
                            'topic' => data_get($variables, 'topic'),
                            'uri' => data_get($variables, 'webhookSubscription.callbackUrl'),
                        ],
                        'userErrors' => [],
                    ],
                ],
                'mocked' => true,
            ];
        }

        if ($operation === 'discountCodeFreeShippingCreate') {
            $shipCode = (string) data_get($variables, 'freeShippingCodeDiscount.code', 'RWD-SHIP');

            return [
                'data' => [
                    'discountCodeFreeShippingCreate' => [
                        'codeDiscountNode' => [
                            'id' => 'gid://shopify/DiscountCodeNode/'.Str::lower(Str::random(10)),
                            'codeDiscount' => [
                                'title' => (string) data_get($variables, 'freeShippingCodeDiscount.title', 'Free shipping'),
                                'codes' => ['nodes' => [['code' => $shipCode]]],
                            ],
                        ],
                        'userErrors' => [],
                    ],
                ],
                'mocked' => true,
            ];
        }

        if ($operation === 'giftCardCreate') {
            return [
                'data' => [
                    'giftCardCreate' => [
                        'giftCard' => [
                            'id' => 'gid://shopify/GiftCard/'.Str::lower(Str::random(10)),
                            'initialValue' => [
                                'amount' => (string) data_get($variables, 'input.initialValue', '25.00'),
                                'currencyCode' => 'GBP',
                            ],
                        ],
                        'giftCardCode' => strtoupper(Str::random(4).'-'.Str::random(4).'-'.Str::random(4).'-'.Str::random(4)),
                        'userErrors' => [],
                    ],
                ],
                'mocked' => true,
            ];
        }

        return [
            'data' => [
                'discountCodeBasicCreate' => [
                    'codeDiscountNode' => [
                        'id' => $id,
                        'codeDiscount' => [
                            'title' => (string) data_get($variables, 'basicCodeDiscount.title', 'Rewards'),
                            'startsAt' => data_get($variables, 'basicCodeDiscount.startsAt'),
                            'endsAt' => data_get($variables, 'basicCodeDiscount.endsAt'),
                            'codes' => [
                                'nodes' => [['code' => $code]],
                            ],
                        ],
                    ],
                    'userErrors' => [],
                ],
            ],
            'mocked' => true,
            'operation' => $operation,
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $response
     */
    private function log(string $operation, string $query, array $variables, array $response, ?int $status, bool $mocked): void
    {
        GraphqlLog::query()->create([
            'operation' => $operation,
            'mocked' => $mocked,
            'query' => $query,
            'variables' => $variables,
            'response' => $response,
            'http_status' => $status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function throwUserErrors(array $raw, string $path): void
    {
        $errors = collect(data_get($raw, 'errors', []))
            ->merge(data_get($raw, $path, []) ?: [])
            ->pluck('message')
            ->filter();

        if ($errors->isNotEmpty()) {
            throw new ShopifyGraphQLException($errors->implode('; '));
        }
    }
}
