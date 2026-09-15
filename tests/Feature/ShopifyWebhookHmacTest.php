<?php

namespace Tests\Feature;

use App\Models\ProgramSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyWebhookHmacTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_hmac_is_rejected_when_secret_configured(): void
    {
        config(['shopify.webhook_secret' => 'super-secret']);
        ProgramSetting::putValue('points_per_dollar', '1');

        $payload = json_encode(['id' => 1, 'email' => 'a@b.com', 'subtotal_price' => '10.00', 'financial_status' => 'paid']);

        $this->call(
            'POST',
            '/api/webhooks/shopify',
            [],
            [],
            [],
            [
                'HTTP_X_SHOPIFY_TOPIC' => 'orders/paid',
                'HTTP_X_SHOPIFY_HMAC_SHA256' => 'not-valid',
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload
        )->assertStatus(401);
    }

    public function test_valid_hmac_is_accepted(): void
    {
        $secret = 'super-secret';
        config(['shopify.webhook_secret' => $secret]);
        ProgramSetting::putValue('points_per_dollar', '1');
        ProgramSetting::putValue('min_order_amount', '0');

        $payload = json_encode([
            'id' => 'gid://shopify/Order/hmac',
            'admin_graphql_api_id' => 'gid://shopify/Order/hmac',
            'email' => 'hmac@example.com',
            'financial_status' => 'paid',
            'subtotal_price' => '10.00',
            'customer' => ['email' => 'hmac@example.com', 'first_name' => 'Pat'],
        ]);
        $hmac = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        $this->call(
            'POST',
            '/api/webhooks/shopify',
            [],
            [],
            [],
            [
                'HTTP_X_SHOPIFY_TOPIC' => 'orders/paid',
                'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload
        )->assertOk();
    }
}
