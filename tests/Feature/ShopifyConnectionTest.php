<?php

namespace Tests\Feature;

use App\Services\Shopify\ShopifyConfig;
use App\Services\Shopify\ShopifyGraphqlClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalizes_shop_domain(): void
    {
        $this->assertSame('acme.myshopify.com', ShopifyConfig::normalizeDomain('https://acme.myshopify.com/admin'));
        $this->assertSame('acme.myshopify.com', ShopifyConfig::normalizeDomain('Acme'));
    }

    public function test_saves_encrypted_token_and_hides_it_on_the_settings_page(): void
    {
        $this->from('/admin/settings')->post('/admin/settings/shopify', [
            'shopify_store_domain' => 'https://north-studio.myshopify.com/admin',
            'shopify_access_token' => 'shpat_test_token_value',
            'shopify_webhook_secret' => 'hook-secret',
            'shopify_callback_url' => 'https://demo.example.com',
            'shopify_live' => '1',
        ])->assertRedirect();

        $this->assertSame('north-studio.myshopify.com', ShopifyConfig::storeDomain());
        $this->assertSame('shpat_test_token_value', ShopifyConfig::accessToken());
        $this->assertTrue(ShopifyConfig::isLive());
        $this->assertSame('https://demo.example.com/api/webhooks/shopify', ShopifyConfig::callbackUrl());

        $this->get('/admin/settings')
            ->assertOk()
            ->assertDontSee('shpat_test_token_value')
            ->assertSee('north-studio.myshopify.com');
    }

    public function test_strips_bearer_prefix_from_admin_token(): void
    {
        $this->post('/admin/settings/shopify', [
            'shopify_store_domain' => 'north-studio.myshopify.com',
            'shopify_access_token' => 'Bearer shpat_clean_token',
            'shopify_webhook_secret' => 'hook-secret',
            'shopify_callback_url' => 'https://demo.example.com',
            'shopify_live' => '1',
        ])->assertRedirect();

        $this->assertSame('shpat_clean_token', ShopifyConfig::accessToken());
    }

    public function test_rejects_api_secret_as_access_token(): void
    {
        $this->from('/admin/settings')->post('/admin/settings/shopify', [
            'shopify_store_domain' => 'north-studio.myshopify.com',
            'shopify_access_token' => 'shpss_not_the_admin_token',
            'shopify_webhook_secret' => 'hook-secret',
            'shopify_callback_url' => 'https://demo.example.com',
            'shopify_live' => '1',
        ])->assertRedirect('/admin/settings')->assertSessionHasErrors('shopify_access_token');
    }

    public function test_register_webhooks_rejects_localhost_callback(): void
    {
        ShopifyConfig::saveConnection(
            'demo.myshopify.com',
            'shpat_x',
            'secret',
            'http://127.0.0.1:43123',
            true,
        );

        $this->from('/admin/settings')
            ->post('/admin/settings/shopify/webhooks')
            ->assertRedirect('/admin/settings')
            ->assertSessionHasErrors('shopify_callback_url');
    }

    public function test_live_mode_requires_webhook_secret(): void
    {
        $this->from('/admin/settings')->post('/admin/settings/shopify', [
            'shopify_store_domain' => 'north-studio.myshopify.com',
            'shopify_access_token' => 'shpat_test',
            'shopify_live' => '1',
        ])->assertSessionHasErrors('shopify_webhook_secret');
    }

    public function test_shop_query_omits_empty_variables_array(): void
    {
        Http::fake([
            'https://hub.myshopify.com/*' => Http::response([
                'data' => [
                    'shop' => [
                        'name' => 'Hub',
                        'myshopifyDomain' => 'hub.myshopify.com',
                    ],
                ],
            ], 200),
        ]);

        ShopifyConfig::saveConnection(
            'hub.myshopify.com',
            'shpat_x',
            'secret',
            'https://example.com',
            true,
        );

        $result = app(ShopifyGraphqlClient::class)->pingShop();
        $this->assertSame('Hub', $result['name']);

        Http::assertSent(function ($request) {
            $json = json_decode($request->body(), true);

            return isset($json['query']) && ! array_key_exists('variables', $json);
        });
    }

    public function test_client_credentials_exchange_then_pings_shop(): void
    {
        Http::fake([
            'https://hub.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'fresh-oauth-token',
                'scope' => 'write_discounts',
                'expires_in' => 86399,
            ], 200),
            'https://hub.myshopify.com/admin/api/*' => Http::response([
                'data' => [
                    'shop' => [
                        'name' => 'Hub',
                        'myshopifyDomain' => 'hub.myshopify.com',
                    ],
                ],
            ], 200),
        ]);

        ShopifyConfig::saveConnection(
            'hub.myshopify.com',
            null,
            'shpss_secret',
            'https://example.com',
            true,
            'client-id-123',
            'shpss_secret',
        );

        $result = app(ShopifyGraphqlClient::class)->pingShop();
        $this->assertSame('Hub', $result['name']);
        $this->assertSame('fresh-oauth-token', ShopifyConfig::cachedOauthToken());
    }
}
