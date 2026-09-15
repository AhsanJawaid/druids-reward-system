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
                    'Shopify returned 401 for webhook create. Custom apps usually cannot register webhooks through the API. Add Order payment and Refund create webhooks in Shopify Admin (steps on the Shopify settings page).'
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
     * @return array{code: string, id: string, mocked: bool, raw: array<string, mixed>}
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
            'mocked' => $this->shouldMock(),
            'raw' => $raw,
        ];
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
        $topics = ['ORDERS_PAID', 'REFUNDS_CREATE'];
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
            'extensions' => [
                'cost' => [
                    'requestedQueryCost' => 10,
                    'actualQueryCost' => 10,
                    'throttleStatus' => [
                        'maximumAvailable' => 1000,
                        'currentlyAvailable' => 990,
                        'restoreRate' => 50,
                    ],
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
