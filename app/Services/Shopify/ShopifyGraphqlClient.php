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

    public const CUSTOMER_BIRTHDAY_SEARCH = <<<'GQL'
query customerBirthday($query: String!) {
  customers(first: 1, query: $query) {
    nodes {
      id
      email
      tags
      emailMarketingConsent {
        marketingState
      }
      metafield(namespace: "custom", key: "birthday") {
        value
      }
    }
  }
}
GQL;

    public const CUSTOMER_BIRTHDAY_BY_ID = <<<'GQL'
query customerBirthdayById($id: ID!) {
  customer(id: $id) {
    id
    email
    tags
    emailMarketingConsent {
      marketingState
    }
    metafield(namespace: "custom", key: "birthday") {
      value
    }
  }
}
GQL;

    public const METAFIELDS_SET = <<<'GQL'
mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    metafields {
      id
      key
      value
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

    public const RECENT_CUSTOMERS = <<<'GQL'
query recentCustomers {
  customers(first: 25, sortKey: CREATED_AT, reverse: true) {
    nodes {
      id
      email
      firstName
      tags
      emailMarketingConsent {
        marketingState
      }
      metafield(namespace: "custom", key: "birthday") {
        value
      }
    }
  }
}
GQL;

    public const RECENT_ORDERS = <<<'GQL'
query recentOrders {
  orders(first: 25, sortKey: CREATED_AT, reverse: true) {
    nodes {
      id
      name
      email
      displayFinancialStatus
      currentSubtotalPriceSet {
        shopMoney {
          amount
        }
      }
      customer {
        id
        email
        firstName
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
        $token = $this->resolveAccessToken();
        if ($token === '' || $domain === '') {
            throw new ShopifyGraphQLException('Add your shop domain and either an Admin API access token (shpat_) or Dev Dashboard client ID + secret.');
        }

        $payload = ['query' => $query];
        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        $http = $this->postGraphql($domain, $version, $token, $payload);
        $body = $http->json() ?? ['errors' => [['message' => 'Empty Shopify response']]];
        $this->log($operation, $query, $variables, $body, $http->status(), false);

        if ($http->status() === 401 && ShopifyConfig::hasClientCredentials()) {
            ShopifyConfig::forgetCachedOauthToken();
            $token = $this->exchangeClientCredentials();
            $http = $this->postGraphql($domain, $version, $token, $payload);
            $body = $http->json() ?? ['errors' => [['message' => 'Empty Shopify response']]];
            $this->log($operation.'-retry', $query, $variables, $body, $http->status(), false);
        }

        if ($http->failed()) {
            throw new ShopifyGraphQLException($this->httpFailureMessage($http->status(), $operation, $domain, $body));
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

    /**
     * @return array{id: ?string, email: ?string, birthday: ?string, tags: ?string, marketing_state: ?string}
     */
    public function findCustomerBirthday(?string $email = null, ?string $customerGid = null): array
    {
        return $this->findCustomerProfile($email, $customerGid);
    }

    /**
     * @return array{id: ?string, email: ?string, birthday: ?string, tags: ?string, marketing_state: ?string}
     */
    public function findCustomerProfile(?string $email = null, ?string $customerGid = null): array
    {
        $empty = [
            'id' => $customerGid,
            'email' => $email,
            'birthday' => null,
            'tags' => null,
            'marketing_state' => null,
        ];

        if ($this->shouldMock()) {
            return $empty;
        }

        try {
            if ($customerGid && str_starts_with($customerGid, 'gid://')) {
                $raw = $this->mutate('customerBirthdayById', self::CUSTOMER_BIRTHDAY_BY_ID, ['id' => $customerGid], true);
                $this->throwUserErrors($raw, 'errors');
                $node = data_get($raw, 'data.customer');
            } elseif (filled($email)) {
                $raw = $this->mutate('customerBirthday', self::CUSTOMER_BIRTHDAY_SEARCH, [
                    'query' => 'email:'.strtolower($email),
                ], true);
                $this->throwUserErrors($raw, 'errors');
                $node = data_get($raw, 'data.customers.nodes.0');
            } else {
                return $empty;
            }
        } catch (\Throwable) {
            return $empty;
        }

        if (! is_array($node) || $node === []) {
            return $empty;
        }

        $value = data_get($node, 'metafield.value');
        $tags = data_get($node, 'tags', []);
        if (is_array($tags)) {
            $tags = implode(',', $tags);
        }

        $state = data_get($node, 'emailMarketingConsent.marketingState');

        return [
            'id' => (string) data_get($node, 'id', $customerGid),
            'email' => strtolower((string) data_get($node, 'email', $email)),
            'birthday' => is_string($value) && $value !== '' ? $value : null,
            'tags' => is_string($tags) && $tags !== '' ? $tags : null,
            'marketing_state' => is_string($state) && $state !== '' ? strtolower($state) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentCustomers(): array
    {
        if ($this->shouldMock()) {
            return [];
        }

        $raw = $this->mutate('recentCustomers', self::RECENT_CUSTOMERS, [], true);
        $this->throwUserErrors($raw, 'errors');

        return array_values(array_filter((array) data_get($raw, 'data.customers.nodes', []), 'is_array'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentOrders(): array
    {
        if ($this->shouldMock()) {
            return [];
        }

        $raw = $this->mutate('recentOrders', self::RECENT_ORDERS, [], true);
        $this->throwUserErrors($raw, 'errors');

        return array_values(array_filter((array) data_get($raw, 'data.orders.nodes', []), 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public function graphqlOrderToWebhookPayload(array $order): array
    {
        $amount = (string) data_get($order, 'currentSubtotalPriceSet.shopMoney.amount', '0');
        $email = (string) data_get($order, 'email', data_get($order, 'customer.email', ''));
        $status = strtolower((string) data_get($order, 'displayFinancialStatus', 'paid'));

        return [
            'id' => data_get($order, 'id'),
            'admin_graphql_api_id' => data_get($order, 'id'),
            'name' => data_get($order, 'name'),
            'email' => $email,
            'financial_status' => $status,
            'subtotal_price' => $amount,
            'current_subtotal_price' => $amount,
            'customer' => [
                'id' => data_get($order, 'customer.id'),
                'admin_graphql_api_id' => data_get($order, 'customer.id'),
                'email' => data_get($order, 'customer.email', $email),
                'first_name' => data_get($order, 'customer.firstName', ''),
            ],
        ];
    }

    public function writeCustomerBirthday(string $customerGid, string $ymd): void
    {
        if ($this->shouldMock() || $customerGid === '' || ! str_starts_with($customerGid, 'gid://shopify/Customer')) {
            return;
        }

        $raw = $this->mutate('metafieldsSet', self::METAFIELDS_SET, [
            'metafields' => [[
                'ownerId' => $customerGid,
                'namespace' => 'custom',
                'key' => 'birthday',
                'type' => 'date',
                'value' => $ymd,
            ]],
        ], true);
        $this->throwUserErrors($raw, 'data.metafieldsSet.userErrors');
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
            'ORDERS_CREATE',
            'REFUNDS_CREATE',
            'CUSTOMERS_CREATE',
            'CUSTOMERS_UPDATE',
            'CUSTOMERS_EMAIL_MARKETING_CONSENT_UPDATE',
        ];
        $created = [];

        foreach ($topics as $topic) {
            $subscription = [
                'callbackUrl' => $callbackUrl,
                'format' => 'JSON',
            ];
            if (str_starts_with($topic, 'CUSTOMERS')) {
                $subscription['metafieldNamespaces'] = ['custom'];
            }
            $raw = $this->mutate('webhookSubscriptionCreate', self::WEBHOOK_CREATE, [
                'topic' => $topic,
                'webhookSubscription' => $subscription,
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
     * Prefer a fresh Dev Dashboard client-credentials token. Static shpat_ tokens
     * from CLI apps expire and produce 401 Invalid API key or access token.
     */
    private function resolveAccessToken(): string
    {
        if ($cached = ShopifyConfig::cachedOauthToken()) {
            return $cached;
        }

        if (ShopifyConfig::hasClientCredentials()) {
            return $this->exchangeClientCredentials();
        }

        return ShopifyConfig::accessToken();
    }

    private function exchangeClientCredentials(): string
    {
        $domain = ShopifyConfig::storeDomain();
        $clientId = ShopifyConfig::clientId();
        $clientSecret = ShopifyConfig::clientSecret();
        if ($domain === '' || $clientId === '' || $clientSecret === '') {
            throw new ShopifyGraphQLException('Client ID and Client secret are required to fetch a Shopify access token.');
        }

        $http = Http::asForm()->timeout(20)->post("https://{$domain}/admin/oauth/access_token", [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        $body = $http->json() ?? [];
        $this->log('clientCredentials', 'oauth/access_token', ['grant_type' => 'client_credentials'], $body, $http->status(), false);

        $token = (string) data_get($body, 'access_token', '');
        if ($http->failed() || $token === '') {
            $detail = is_string($body['error_description'] ?? null)
                ? (string) $body['error_description']
                : (is_string($body['error'] ?? null) ? (string) $body['error'] : 'HTTP '.$http->status());
            throw new ShopifyGraphQLException(
                'Could not exchange Client ID/secret for a Shopify token ('.$detail.'). The app must be installed on '.$domain.', and the app and store must be in the same Dev Dashboard organization. Or create a custom app inside this store and paste the shpat_ token shown at install.'
            );
        }

        ShopifyConfig::rememberOauthToken($token, (int) data_get($body, 'expires_in', 86399));

        return $token;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postGraphql(string $domain, string $version, string $token, array $payload): \Illuminate\Http\Client\Response
    {
        return Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(20)->post("https://{$domain}/admin/api/{$version}/graphql.json", $payload);
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
     * @param  array<string, mixed>  $body
     */
    private function httpFailureMessage(int $status, string $operation, string $domain, array $body): string
    {
        $shopify = $this->shopifyErrorText($body);

        if ($status === 401 && $operation === 'webhookSubscriptionCreate') {
            return 'Shopify returned 401 for webhook create. Custom apps usually cannot register webhooks through the API. Add the webhooks by hand on Admin → Shopify settings.';
        }

        if ($status === 401) {
            return 'Shopify rejected the Admin API token for '.$domain
                .' (401'
                .($shopify !== '' ? ': '.$shopify : ' Invalid API key or access token')
                .'). That usually means the saved shpat_ token is expired, from a Shopify CLI / Dev Dashboard app, or from a different shop. On Admin → Shopify settings paste Client ID + Client secret from the Dev Dashboard (or create a custom app inside this store, install it, and paste a new shpat_ token shown only once). Shop must be exactly '.$domain.'. Then Test connection.';
        }

        if ($status === 404) {
            return 'Shopify returned 404 for Admin API '.$this->apiVersionHint().' on '.$domain.'. Check the shop domain is the *.myshopify.com hostname.';
        }

        return 'Shopify Admin GraphQL HTTP '.$status.($shopify !== '' ? ': '.$shopify : '');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function shopifyErrorText(array $body): string
    {
        $errors = $body['errors'] ?? $body['error'] ?? null;
        if (is_string($errors)) {
            return $errors;
        }
        if (is_array($errors)) {
            $parts = collect($errors)->map(function ($item) {
                if (is_string($item)) {
                    return $item;
                }
                if (is_array($item)) {
                    return (string) ($item['message'] ?? json_encode($item));
                }

                return '';
            })->filter()->all();

            return implode('; ', $parts);
        }

        return '';
    }

    private function apiVersionHint(): string
    {
        return ShopifyConfig::apiVersion();
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
            $text = $errors->implode('; ');
            if (str_contains(strtolower($text), 'access denied') || str_contains(strtolower($text), 'access scope')) {
                throw new ShopifyGraphQLException(
                    $text.' Enable write_discounts (and write_gift_cards for gift cards) on the custom app, click Save, then reinstall the app so a new Admin API access token is issued. Paste that token on Admin → Shopify settings.'
                );
            }
            throw new ShopifyGraphQLException($text);
        }
    }
}
