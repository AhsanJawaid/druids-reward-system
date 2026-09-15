<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\ProgramSetting;
use App\Models\Redemption;
use App\Services\Shopify\ShopifyGraphqlClient;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(ShopifyGraphqlClient $shopify): View
    {
        $since = now()->subDays(30);

        return view('admin.dashboard', [
            'settings' => ProgramSetting::current(),
            'customers' => Customer::query()->count(),
            'pointsIssued' => (int) PointTransaction::query()->where('type', 'earn')->sum('points'),
            'pointsRedeemed' => abs((int) PointTransaction::query()->where('type', 'redeem')->sum('points')),
            'codesCount' => Redemption::query()->count(),
            'pointsThisMonth' => (int) PointTransaction::query()->where('type', 'earn')->where('created_at', '>=', $since)->sum('points'),
            'redeemsThisMonth' => Redemption::query()->where('created_at', '>=', $since)->count(),
            'codes' => Redemption::query()->with(['customer', 'reward'])->latest()->limit(50)->get(),
            'topCustomers' => Customer::query()->orderByDesc('points_balance')->limit(5)->get(),
            'recentTx' => PointTransaction::query()->with('customer')->latest()->limit(8)->get(),
            'mocked' => $shopify->shouldMock(),
            'shop' => config('shopify.store_domain'),
        ]);
    }
}
