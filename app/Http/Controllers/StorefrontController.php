<?php

namespace App\Http\Controllers;

use App\Exceptions\IneligibleProductException;
use App\Exceptions\InsufficientPointsException;
use App\Models\Customer;
use App\Models\ProgramSetting;
use App\Models\Reward;
use App\Services\Rewards\RewardsService;
use App\Services\Shopify\ShopifyGraphqlClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StorefrontController extends Controller
{
    public function home(Request $request, ShopifyGraphqlClient $shopify): View
    {
        try {
            Reward::syncCatalog();
        } catch (\Throwable) {
            // Catalog sync needs the slug column; EnsureAssessmentSchema adds it on boot.
        }

        $email = strtolower((string) $request->session()->get('storefront_email', ''));
        $customer = $email !== ''
            ? Customer::query()->where('email', $email)->first()
            : null;

        $settings = ProgramSetting::current();
        $eligible = [];
        if ($settings['free_product_collection_id'] !== '') {
            try {
                $eligible = $shopify->collectionProducts($settings['free_product_collection_id']);
            } catch (\Throwable) {
                $eligible = [];
            }
        }

        return view('storefront.home', [
            'customer' => $customer,
            'issuedCodes' => $customer
                ? $customer->redemptions()->with('reward')->latest()->get()
                : collect(),
            'rewards' => Reward::query()->where('active', true)->orderBy('points_cost')->orderBy('name')->get(),
            'settings' => $settings,
            'eligibleProducts' => $eligible,
            'shop' => config('shopify.store_domain'),
            'mocked' => $shopify->shouldMock(),
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
            'status',
            $exists
                ? 'Rewards card loaded.'
                : 'No ledger yet for this email. Points appear after a real Shopify webhook (paid order, account create, newsletter opt-in, or birthday).'
        );
    }

    public function redeem(Request $request, RewardsService $rewards): RedirectResponse
    {
        $data = $request->validate([
            'reward_id' => ['required', 'integer', 'exists:rewards,id'],
            'product_id' => ['nullable', 'string', 'max:120'],
        ]);

        $email = strtolower((string) $request->session()->get('storefront_email', ''));
        $customer = Customer::query()->where('email', $email)->first();

        if (! $customer) {
            return back()->withErrors(['email' => 'Look up a customer email first.']);
        }

        $reward = Reward::query()->findOrFail($data['reward_id']);

        try {
            $redemption = $rewards->redeem($customer, $reward, $data['product_id'] ?? null);
        } catch (InsufficientPointsException|IneligibleProductException $e) {
            return back()->withErrors(['reward_id' => $e->getMessage()]);
        }

        $kind = $redemption->shopify_object_type === 'gift_card' ? 'gift card' : 'discount code';

        return back()->with([
            'status' => 'Your '.$kind.' is ready. Use it at Shopify checkout.',
            'discount_code' => $redemption->discount_code,
            'discount_reward' => $reward->name,
        ]);
    }
}
