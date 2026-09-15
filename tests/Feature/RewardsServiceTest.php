<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientPointsException;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\ProgramSetting;
use App\Models\Reward;
use App\Services\Rewards\RewardsService;
use App\Services\Shopify\ShopifyGraphqlClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RewardsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProgramSetting::putValue('program_name', 'Test Rewards');
        ProgramSetting::putValue('points_per_dollar', '1');
        ProgramSetting::putValue('min_order_amount', '0');
        ProgramSetting::putValue('discount_expiry_days', '30');
    }

    public function test_paid_order_awards_floor_points_on_subtotal(): void
    {
        $tx = $this->service()->earnFromOrder($this->order('gid://shopify/Order/1', '48.90'), 'demo.myshopify.com');

        $this->assertSame(48, $tx->points);
        $this->assertSame(48, Customer::query()->where('email', 'maya@atelier.example')->value('points_balance'));
    }

    public function test_duplicate_order_webhook_is_idempotent(): void
    {
        $order = $this->order('gid://shopify/Order/9', '100.00');
        $first = $this->service()->earnFromOrder($order, 'demo.myshopify.com');
        $second = $this->service()->earnFromOrder($order, 'demo.myshopify.com');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, PointTransaction::query()->count());
        $this->assertSame(100, Customer::query()->value('points_balance'));
    }

    public function test_refund_reverses_points_without_going_negative(): void
    {
        $this->service()->earnFromOrder($this->order('gid://shopify/Order/2', '40.00'), 'demo.myshopify.com');
        $tx = $this->service()->refundOrder([
            'id' => 'gid://shopify/Refund/2',
            'order_id' => 'gid://shopify/Order/2',
            'email' => 'maya@atelier.example',
            'transactions' => [['amount' => '100.00']],
        ], 'demo.myshopify.com');

        $this->assertSame(-40, $tx->points);
        $this->assertSame(0, Customer::query()->value('points_balance'));
    }

    public function test_redeem_rejects_insufficient_balance(): void
    {
        $customer = Customer::query()->create([
            'shop_domain' => 'demo.myshopify.com',
            'email' => 'maya@atelier.example',
            'name' => 'Maya',
            'points_balance' => 20,
        ]);
        $reward = Reward::query()->create([
            'name' => '$5',
            'points_cost' => 100,
            'discount_type' => 'fixed_amount',
            'discount_value' => 5,
            'active' => true,
        ]);

        $this->expectException(InsufficientPointsException::class);
        $this->service()->redeem($customer, $reward);
    }

    public function test_redeem_creates_shopify_discount_and_deducts_points(): void
    {
        $customer = Customer::query()->create([
            'shop_domain' => 'demo.myshopify.com',
            'email' => 'maya@atelier.example',
            'name' => 'Maya',
            'points_balance' => 150,
        ]);
        $reward = Reward::query()->create([
            'name' => '$5 studio credit',
            'points_cost' => 100,
            'discount_type' => 'fixed_amount',
            'discount_value' => 5,
            'active' => true,
        ]);

        $redemption = $this->service()->redeem($customer, $reward);

        $this->assertNotEmpty($redemption->discount_code);
        $this->assertStringStartsWith('RWD-', $redemption->discount_code);
        $this->assertStringStartsWith('gid://shopify/DiscountCodeNode/', $redemption->shopify_discount_id);
        $this->assertSame(50, $customer->fresh()->points_balance);
        $this->assertDatabaseHas('graphql_logs', ['operation' => 'discountCodeBasicCreate']);
    }

    public function test_webhook_without_secret_is_accepted_in_demo(): void
    {
        $response = $this->postJson('/api/webhooks/shopify', $this->order('gid://shopify/Order/77', '32.00'), [
            'X-Shopify-Topic' => 'orders/paid',
            'X-Shopify-Shop-Domain' => 'demo.myshopify.com',
        ]);

        $response->assertOk();
        $this->assertSame(32, Customer::query()->value('points_balance'));
    }

    public function test_storefront_and_admin_render(): void
    {
        $this->seed();
        $this->get('/')->assertOk()->assertSee('Atelier');
        $this->get('/admin')->assertOk()->assertSee('Overview');
    }

    private function service(): RewardsService
    {
        return app(RewardsService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function order(string $id, string $subtotal): array
    {
        return [
            'id' => $id,
            'admin_graphql_api_id' => $id,
            'name' => '#T-1',
            'email' => 'maya@atelier.example',
            'financial_status' => 'paid',
            'subtotal_price' => $subtotal,
            'total_price' => $subtotal,
            'currency' => 'USD',
            'customer' => [
                'id' => 'gid://shopify/Customer/maya',
                'email' => 'maya@atelier.example',
                'first_name' => 'Maya Chen',
            ],
        ];
    }
}
