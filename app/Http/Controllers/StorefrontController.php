<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientPointsException;
use App\Models\Customer;
use App\Models\ProgramSetting;
use App\Models\Reward;
use App\Services\Rewards\RewardsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StorefrontController extends Controller
{
    /** @var array<int, array<string, mixed>> */
    public const CATALOG = [
        [
            'sku' => 'apron-linen',
            'name' => 'Waxed linen apron',
            'price' => 48,
            'blurb' => 'Heavyweight studio apron, brass hardware.',
        ],
        [
            'sku' => 'bowl-stone',
            'name' => 'Speckled stoneware bowl',
            'price' => 32,
            'blurb' => 'Thrown in small batches. Holds a serious salad.',
        ],
        [
            'sku' => 'lamp-brass',
            'name' => 'Task lamp in aged brass',
            'price' => 120,
            'blurb' => 'Weighted base, linen shade, dimmable.',
        ],
    ];

    public function home(Request $request): View
    {
        $email = strtolower((string) $request->session()->get('storefront_email', ''));
        $customer = $email !== ''
            ? Customer::query()->where('email', $email)->first()
            : null;

        return view('storefront.home', [
            'products' => self::CATALOG,
            'customer' => $customer,
            'issuedCodes' => $customer
                ? $customer->redemptions()->with('reward')->latest()->get()
                : collect(),
            'rewards' => Reward::query()->where('active', true)->orderBy('points_cost')->get(),
            'settings' => ProgramSetting::current(),
            'shop' => config('shopify.store_domain'),
            'mocked' => app(\App\Services\Shopify\ShopifyGraphqlClient::class)->shouldMock(),
            'justIssued' => session('discount_code'),
        ]);
    }

    public function identify(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $request->session()->put('storefront_email', strtolower($data['email']));

        $exists = Customer::query()->where('email', strtolower($data['email']))->exists();

        return back()->with(
            $exists ? 'status' : 'status',
            $exists
                ? 'Rewards card loaded.'
                : 'No ledger yet for this email. Place a demo order to start earning.'
        );
    }

    public function checkout(Request $request, RewardsService $rewards): RedirectResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]);

        $product = collect(self::CATALOG)->firstWhere('sku', $data['sku']);
        if (! $product) {
            return back()->withErrors(['sku' => 'Unknown product.']);
        }

        $email = strtolower($data['email']);
        $request->session()->put('storefront_email', $email);

        $orderId = 'gid://shopify/Order/'.now()->format('YmdHis').random_int(10, 99);
        $order = [
            'id' => $orderId,
            'admin_graphql_api_id' => $orderId,
            'name' => '#DEMO-'.strtoupper(substr(md5($orderId), 0, 5)),
            'email' => $email,
            'financial_status' => 'paid',
            'subtotal_price' => number_format((float) $product['price'], 2, '.', ''),
            'total_price' => number_format((float) $product['price'], 2, '.', ''),
            'currency' => 'USD',
            'customer' => [
                'id' => 'gid://shopify/Customer/'.substr(sha1($email), 0, 8),
                'email' => $email,
                'first_name' => strstr($email, '@', true) ?: 'Customer',
            ],
            'line_items' => [[
                'title' => $product['name'],
                'sku' => $product['sku'],
                'price' => $product['price'],
                'quantity' => 1,
            ]],
        ];

        $tx = $rewards->earnFromOrder($order, (string) config('shopify.store_domain'));

        \App\Models\ShopifyEvent::query()->create([
            'topic' => 'orders/paid',
            'shop_domain' => config('shopify.store_domain'),
            'webhook_id' => 'demo-'.$orderId,
            'hmac_verified' => false,
            'status' => 'processed',
            'message' => 'Storefront demo checkout',
            'payload' => $order,
        ]);

        $pts = $tx?->points ?? 0;

        return back()->with('status', "Paid order {$order['name']}. {$pts} points posted to the ledger.");
    }

    public function redeem(Request $request, RewardsService $rewards): RedirectResponse
    {
        $data = $request->validate([
            'reward_id' => ['required', 'integer', 'exists:rewards,id'],
        ]);

        $email = strtolower((string) $request->session()->get('storefront_email', ''));
        $customer = Customer::query()->where('email', $email)->first();

        if (! $customer) {
            return back()->withErrors(['email' => 'Look up a customer email first.']);
        }

        $reward = Reward::query()->findOrFail($data['reward_id']);

        try {
            $redemption = $rewards->redeem($customer, $reward);
        } catch (InsufficientPointsException $e) {
            return back()->withErrors(['reward_id' => $e->getMessage()]);
        }

        return back()->with([
            'status' => 'Your discount code is ready. Copy it and paste it at Shopify checkout.',
            'discount_code' => $redemption->discount_code,
            'discount_reward' => $reward->name,
        ]);
    }
}
