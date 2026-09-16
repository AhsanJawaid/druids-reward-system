<?php

namespace Tests\Feature;

use App\Exceptions\IneligibleProductException;
use App\Exceptions\InsufficientPointsException;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\ProgramSetting;
use App\Models\Reward;
use App\Services\Rewards\RewardsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RewardsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProgramSetting::putValue('program_name', 'Test Rewards');
        ProgramSetting::putValue('points_per_pound', '2');
        ProgramSetting::putValue('min_order_amount', '0');
        ProgramSetting::putValue('discount_expiry_days', '30');
        ProgramSetting::putValue('hold_days', '14');
        ProgramSetting::putValue('free_product_collection_id', 'gid://shopify/Collection/99');
        Reward::syncCatalog();
    }

    public function test_paid_order_awards_two_points_per_pound_after_discounts_and_holds(): void
    {
        $tx = $this->service()->earnFromOrder($this->order('gid://shopify/Order/1', '48.90'), 'demo.myshopify.com');

        $this->assertSame(97, $tx->points);
        $this->assertNotNull($tx->available_at);
        $this->assertTrue($tx->available_at->isFuture());
        $customer = Customer::query()->where('email', 'maya@example.com')->first();
        $this->assertSame(97, $customer->points_balance);
        $this->assertSame(97, $customer->pendingPoints());
        $this->assertSame(0, $customer->spendablePoints());
    }

    public function test_pending_order_does_not_award_points(): void
    {
        $order = $this->order('gid://shopify/Order/pending', '50.00');
        $order['financial_status'] = 'pending';

        $this->assertNull($this->service()->earnFromOrder($order, 'demo.myshopify.com'));
        $this->assertSame(0, PointTransaction::query()->count());
    }

    public function test_duplicate_order_webhook_is_idempotent(): void
    {
        $order = $this->order('gid://shopify/Order/9', '100.00');
        $first = $this->service()->earnFromOrder($order, 'demo.myshopify.com');
        $second = $this->service()->earnFromOrder($order, 'demo.myshopify.com');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, PointTransaction::query()->count());
        $this->assertSame(200, Customer::query()->value('points_balance'));
    }

    public function test_refund_reverses_points_without_going_negative(): void
    {
        $this->service()->earnFromOrder($this->order('gid://shopify/Order/2', '40.00'), 'demo.myshopify.com');
        $tx = $this->service()->refundOrder([
            'id' => 'gid://shopify/Refund/2',
            'order_id' => 'gid://shopify/Order/2',
            'email' => 'maya@example.com',
            'transactions' => [['amount' => '100.00']],
        ], 'demo.myshopify.com');

        $this->assertSame(-80, $tx->points);
        $this->assertSame(0, Customer::query()->value('points_balance'));
    }

    public function test_account_create_awards_200_once(): void
    {
        $payload = $this->customerPayload();
        $first = $this->service()->awardAccountCreated($payload, 'demo.myshopify.com');
        $second = $this->service()->awardAccountCreated($payload, 'demo.myshopify.com');

        $this->assertSame(200, $first->points);
        $this->assertTrue($first->is($second));
        $this->assertSame(200, Customer::query()->value('points_balance'));
        $this->assertSame(200, Customer::query()->first()->spendablePoints());
    }

    public function test_newsletter_awards_only_on_subscribed_opt_in(): void
    {
        $payload = $this->customerPayload();
        $payload['accepts_marketing'] = false;
        $payload['email_marketing_consent'] = ['state' => 'not_subscribed'];
        $this->assertNull($this->service()->awardNewsletterOptIn($payload, 'demo.myshopify.com'));

        $payload['email_marketing_consent'] = ['state' => 'subscribed'];
        $tx = $this->service()->awardNewsletterOptIn($payload, 'demo.myshopify.com');
        $this->assertSame(100, $tx->points);

        $again = $this->service()->awardNewsletterOptIn($payload, 'demo.myshopify.com');
        $this->assertTrue($tx->is($again));
        $this->assertSame(1, PointTransaction::query()->where('source', 'newsletter')->count());
    }

    public function test_portal_save_birthday_today_awards_250(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 16)->setTime(12, 0));

        $this->post('/birthday', [
            'email' => 'maya@example.com',
            'birthday' => '1990-09-16',
        ])->assertRedirect();

        $this->assertSame(250, Customer::query()->value('points_balance'));
        $this->assertSame('1990-09-16', Customer::query()->first()->birthday->toDateString());
        $this->assertSame(2026, Customer::query()->first()->last_birthday_reward_year);
    }

    public function test_portal_save_birthday_other_day_stores_without_points(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 2)->setTime(12, 0));

        $this->post('/birthday', [
            'email' => 'maya@example.com',
            'birthday' => '1990-09-16',
        ])->assertRedirect();

        $this->assertSame('1990-09-16', Customer::query()->first()->birthday->toDateString());
        $this->assertSame(0, PointTransaction::query()->where('source', 'birthday')->count());
        $this->assertSame(0, Customer::query()->value('points_balance'));
    }

    public function test_birthday_awards_250_once_per_calendar_year(): void
    {
        $this->travelTo(now()->setDate(2026, 3, 15)->setTime(10, 0));
        $payload = $this->customerPayload();
        $payload['birthday'] = '1990-03-15';

        $tx = $this->service()->awardBirthdayIfDue($payload, 'demo.myshopify.com');
        $this->assertSame(250, $tx->points);

        $again = $this->service()->awardBirthdayIfDue($payload, 'demo.myshopify.com');
        $this->assertTrue($tx->is($again));

        $this->travelTo(now()->setDate(2027, 3, 15)->setTime(10, 0));
        $next = $this->service()->awardBirthdayIfDue($payload, 'demo.myshopify.com');
        $this->assertSame(250, $next->points);
        $this->assertFalse($next->is($tx));
    }

    public function test_held_purchase_points_cannot_be_spent(): void
    {
        $this->service()->earnFromOrder($this->order('gid://shopify/Order/hold', '250.00'), 'demo.myshopify.com');
        $customer = Customer::query()->first();
        $reward = Reward::query()->where('slug', 'money_off')->first();

        $this->expectException(InsufficientPointsException::class);
        $this->service()->redeem($customer, $reward);
    }

    public function test_redeem_rejects_insufficient_balance(): void
    {
        $customer = Customer::query()->create([
            'shop_domain' => 'demo.myshopify.com',
            'email' => 'maya@example.com',
            'name' => 'Maya',
            'points_balance' => 20,
        ]);
        $reward = Reward::query()->where('slug', 'money_off')->first();

        $this->expectException(InsufficientPointsException::class);
        $this->service()->redeem($customer, $reward);
    }

    public function test_money_off_creates_shopify_discount_and_deducts_spendable_points(): void
    {
        $customer = Customer::query()->create([
            'shop_domain' => 'demo.myshopify.com',
            'email' => 'maya@example.com',
            'name' => 'Maya',
            'points_balance' => 500,
        ]);
        $reward = Reward::query()->where('slug', 'money_off')->first();

        $redemption = $this->service()->redeem($customer, $reward);

        $this->assertNotEmpty($redemption->discount_code);
        $this->assertSame('discount_code', $redemption->shopify_object_type);
        $this->assertStringStartsWith('gid://shopify/DiscountCodeNode/', $redemption->shopify_discount_id);
        $this->assertSame(0, $customer->fresh()->points_balance);
        $this->assertDatabaseHas('graphql_logs', ['operation' => 'discountCodeBasicCreate']);
    }

    public function test_gift_card_creates_shopify_gift_card_object(): void
    {
        $customer = Customer::query()->create([
            'shop_domain' => 'demo.myshopify.com',
            'email' => 'maya@example.com',
            'name' => 'Maya',
            'points_balance' => 2500,
        ]);
        $reward = Reward::query()->where('slug', 'gift_card')->first();

        $redemption = $this->service()->redeem($customer, $reward);

        $this->assertSame('gift_card', $redemption->shopify_object_type);
        $this->assertStringStartsWith('gid://shopify/GiftCard/', $redemption->shopify_discount_id);
        $this->assertDatabaseHas('graphql_logs', ['operation' => 'giftCardCreate']);
    }

    public function test_free_product_rejects_item_outside_collection(): void
    {
        $customer = Customer::query()->create([
            'shop_domain' => 'demo.myshopify.com',
            'email' => 'maya@example.com',
            'name' => 'Maya',
            'points_balance' => 1500,
        ]);
        $reward = Reward::query()->where('slug', 'free_product')->first();

        $this->expectException(IneligibleProductException::class);
        $this->service()->redeem($customer, $reward, 'gid://shopify/Product/ineligible');
    }

    public function test_free_product_verifies_collection_then_issues_discount(): void
    {
        $customer = Customer::query()->create([
            'shop_domain' => 'demo.myshopify.com',
            'email' => 'maya@example.com',
            'name' => 'Maya',
            'points_balance' => 1500,
        ]);
        $reward = Reward::query()->where('slug', 'free_product')->first();

        $redemption = $this->service()->redeem($customer, $reward, 'gid://shopify/Product/1001');

        $this->assertSame('discount_code', $redemption->shopify_object_type);
        $this->assertSame('gid://shopify/Product/1001', $redemption->product_gid);
    }

    public function test_webhook_without_secret_is_accepted_in_demo(): void
    {
        $response = $this->postJson('/api/webhooks/shopify', $this->order('gid://shopify/Order/77', '32.00'), [
            'X-Shopify-Topic' => 'orders/paid',
            'X-Shopify-Shop-Domain' => 'demo.myshopify.com',
        ]);

        $response->assertOk();
        $this->assertSame(264, Customer::query()->value('points_balance'));
    }

    public function test_customer_create_webhook_awards_account_points(): void
    {
        $this->postJson('/api/webhooks/shopify', $this->customerPayload(), [
            'X-Shopify-Topic' => 'customers/create',
            'X-Shopify-Shop-Domain' => 'demo.myshopify.com',
        ])->assertOk();

        $this->assertSame(200, Customer::query()->value('points_balance'));
    }

    public function test_unpaid_order_webhook_still_creates_customer_and_account_points(): void
    {
        $order = $this->order('gid://shopify/Order/unpaid', '40.00');
        $order['financial_status'] = 'pending';

        $this->postJson('/api/webhooks/shopify', $order, [
            'X-Shopify-Topic' => 'orders/create',
            'X-Shopify-Shop-Domain' => 'demo.myshopify.com',
        ])->assertOk();

        $this->assertSame(200, Customer::query()->value('points_balance'));
        $this->assertSame(0, PointTransaction::query()->where('source', 'shopify_order')->count());
        $this->assertDatabaseHas('shopify_events', ['message' => 'account_create:200,order-received-not-paid']);
    }

    public function test_webhook_url_is_reachable_with_get(): void
    {
        $this->getJson('/api/webhooks/shopify')
            ->assertOk()
            ->assertSee('Webhook URL is reachable');
    }

    public function test_paid_order_uses_amount_after_discounts_not_list_price(): void
    {
        $order = $this->order('gid://shopify/Order/disc', '80.00');
        $order['total_line_items_price'] = '100.00';
        $order['total_discounts'] = '20.00';
        $order['current_subtotal_price'] = '80.00';

        $tx = $this->service()->earnFromOrder($order, 'demo.myshopify.com');

        $this->assertSame(160, $tx->points);
    }

    public function test_free_shipping_creates_shopify_free_shipping_discount(): void
    {
        $customer = Customer::query()->create([
            'shop_domain' => 'demo.myshopify.com',
            'email' => 'maya@example.com',
            'name' => 'Maya',
            'points_balance' => 500,
        ]);
        $reward = Reward::query()->where('slug', 'free_shipping')->first();

        $redemption = $this->service()->redeem($customer, $reward);

        $this->assertSame('discount_code', $redemption->shopify_object_type);
        $this->assertDatabaseHas('graphql_logs', ['operation' => 'discountCodeFreeShippingCreate']);
    }

    public function test_newsletter_webhook_ignores_profile_save_without_subscribe(): void
    {
        $payload = $this->customerPayload();
        $this->postJson('/api/webhooks/shopify', $payload, [
            'X-Shopify-Topic' => 'customers/update',
            'X-Shopify-Shop-Domain' => 'demo.myshopify.com',
        ])->assertOk();

        $this->assertSame(0, PointTransaction::query()->where('source', 'newsletter')->count());

        $payload['email_marketing_consent'] = ['state' => 'subscribed'];
        $this->postJson('/api/webhooks/shopify', $payload, [
            'X-Shopify-Topic' => 'customers/update',
            'X-Shopify-Shop-Domain' => 'demo.myshopify.com',
        ])->assertOk();

        $this->assertSame(100, PointTransaction::query()->where('source', 'newsletter')->sum('points'));
    }

    public function test_footer_newsletter_tag_awards_100_points(): void
    {
        $payload = $this->customerPayload();
        $payload['tags'] = 'newsletter';
        $payload['email_marketing_consent'] = ['state' => 'not_subscribed'];

        $this->postJson('/api/webhooks/shopify', $payload, [
            'X-Shopify-Topic' => 'customers/create',
            'X-Shopify-Shop-Domain' => 'demo.myshopify.com',
        ])->assertOk();

        $this->assertSame(100, PointTransaction::query()->where('source', 'newsletter')->sum('points'));
        $this->assertSame(300, Customer::query()->value('points_balance'));
    }

    public function test_paid_order_awards_newsletter_for_existing_subscriber(): void
    {
        $order = $this->order('gid://shopify/Order/news', '20.00');
        $order['customer']['accepts_marketing'] = true;
        $order['customer']['email_marketing_consent'] = ['state' => 'subscribed'];

        $this->postJson('/api/webhooks/shopify', $order, [
            'X-Shopify-Topic' => 'orders/paid',
            'X-Shopify-Shop-Domain' => 'demo.myshopify.com',
        ])->assertOk();

        $this->assertSame(100, PointTransaction::query()->where('source', 'newsletter')->sum('points'));
        $this->assertSame(340, Customer::query()->value('points_balance'));
    }

    public function test_email_marketing_consent_webhook_awards_newsletter_points(): void
    {
        $this->postJson('/api/webhooks/shopify', [
            'customer_id' => 4411,
            'email_address' => 'maya@example.com',
            'email_marketing_consent' => ['state' => 'subscribed'],
        ], [
            'X-Shopify-Topic' => 'customers_email_marketing_consent/update',
            'X-Shopify-Shop-Domain' => 'demo.myshopify.com',
        ])->assertOk();

        $this->assertSame(100, PointTransaction::query()->where('source', 'newsletter')->sum('points'));
    }

    public function test_admin_settings_lists_required_webhooks(): void
    {
        $this->get('/admin/settings')
            ->assertOk()
            ->assertSee('orders/paid')
            ->assertSee('refunds/create')
            ->assertSee('customers/create')
            ->assertSee('customers/update')
            ->assertSee('customers_email_marketing_consent/update')
            ->assertSee('Create these Shopify webhooks')
            ->assertSee('Birthday 250 points')
            ->assertSee('Pull latest from Shopify');
    }

    public function test_storefront_and_admin_render_spec_copy(): void
    {
        $this->seed();
        $this->get('/')->assertOk()->assertSee('Earn points from Shopify')->assertSee('Save birthday');
        $this->get('/admin')->assertOk()->assertSee('Issued Shopify codes');
        $this->get('/admin')->assertDontSee('Add test points');
        $this->get('/admin/rewards')
            ->assertOk()
            ->assertSee('Money off (small)')
            ->assertSee('Free shipping')
            ->assertSee('Free product')
            ->assertSee('Gift card')
            ->assertSee('2 points per £1 spent')
            ->assertDontSee('Add a reward')
            ->assertDontSee('Current rewards')
            ->assertDontSee('$5 studio credit');
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
            'email' => 'maya@example.com',
            'financial_status' => 'paid',
            'subtotal_price' => $subtotal,
            'total_discounts' => '0.00',
            'currency' => 'GBP',
            'customer' => [
                'id' => 'gid://shopify/Customer/maya',
                'email' => 'maya@example.com',
                'first_name' => 'Maya Chen',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerPayload(): array
    {
        return [
            'id' => 4411,
            'admin_graphql_api_id' => 'gid://shopify/Customer/4411',
            'email' => 'maya@example.com',
            'first_name' => 'Maya',
            'accepts_marketing' => false,
            'email_marketing_consent' => ['state' => 'not_subscribed'],
        ];
    }
}
