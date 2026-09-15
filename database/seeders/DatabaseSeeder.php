<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\ProgramSetting;
use App\Models\Reward;
use App\Services\Rewards\RewardsService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        ProgramSetting::putValue('program_name', 'Rewards System');
        ProgramSetting::putValue('points_per_dollar', '1');
        ProgramSetting::putValue('min_order_amount', '0');
        ProgramSetting::putValue('discount_expiry_days', '30');

        Reward::query()->create([
            'name' => '$5 studio credit',
            'description' => 'Single-use amount-off code for the Shopify cart.',
            'points_cost' => 100,
            'discount_type' => 'fixed_amount',
            'discount_value' => 5,
            'active' => true,
        ]);
        Reward::query()->create([
            'name' => '$15 table credit',
            'description' => 'Larger amount-off for members sitting on a pile of points.',
            'points_cost' => 250,
            'discount_type' => 'fixed_amount',
            'discount_value' => 15,
            'active' => true,
        ]);
        Reward::query()->create([
            'name' => '10% off the order',
            'description' => 'Percent discount via discountCodeBasicCreate.',
            'points_cost' => 400,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'active' => true,
        ]);

        $shop = (string) config('shopify.store_domain');
        $rewards = app(RewardsService::class);

        $maya = [
            'id' => 'gid://shopify/Order/1001',
            'admin_graphql_api_id' => 'gid://shopify/Order/1001',
            'name' => '#1001',
            'email' => 'maya@example.com',
            'financial_status' => 'paid',
            'subtotal_price' => '120.00',
            'total_price' => '120.00',
            'currency' => 'USD',
            'customer' => [
                'id' => 'gid://shopify/Customer/maya',
                'email' => 'maya@example.com',
                'first_name' => 'Maya Chen',
            ],
        ];
        $rewards->earnFromOrder($maya, $shop);
        $rewards->earnFromOrder([
            ...$maya,
            'id' => 'gid://shopify/Order/1002',
            'admin_graphql_api_id' => 'gid://shopify/Order/1002',
            'name' => '#1002',
            'subtotal_price' => '60.00',
            'total_price' => '60.00',
        ], $shop);

        $rewards->earnFromOrder([
            'id' => 'gid://shopify/Order/2001',
            'admin_graphql_api_id' => 'gid://shopify/Order/2001',
            'name' => '#2001',
            'email' => 'jordan@example.com',
            'financial_status' => 'paid',
            'subtotal_price' => '40.00',
            'total_price' => '40.00',
            'currency' => 'USD',
            'customer' => [
                'id' => 'gid://shopify/Customer/jordan',
                'email' => 'jordan@example.com',
                'first_name' => 'Jordan Blake',
            ],
        ], $shop);

        // Touch the query so seeder customers exist even if earn skipped.
        Customer::query()->where('email', 'maya@example.com')->first();
        PointTransaction::query()->count();
    }
}
