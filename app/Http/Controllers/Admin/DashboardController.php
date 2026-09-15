<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\GraphqlLog;
use App\Models\PointTransaction;
use App\Models\ProgramSetting;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\ShopifyEvent;
use App\Services\Shopify\ShopifyGraphqlClient;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(ShopifyGraphqlClient $shopify): View
    {
        return view('admin.dashboard', [
            'settings' => ProgramSetting::current(),
            'customers' => Customer::query()->count(),
            'pointsIssued' => (int) PointTransaction::query()->where('type', 'earn')->sum('points'),
            'pointsRedeemed' => abs((int) PointTransaction::query()->where('type', 'redeem')->sum('points')),
            'redemptions' => Redemption::query()->count(),
            'recentTx' => PointTransaction::query()->with('customer')->latest()->limit(8)->get(),
            'events' => ShopifyEvent::query()->latest()->limit(6)->get(),
            'logs' => GraphqlLog::query()->latest()->limit(5)->get(),
            'rewards' => Reward::query()->orderBy('points_cost')->get(),
            'mocked' => $shopify->shouldMock(),
            'shop' => config('shopify.store_domain'),
        ]);
    }
}
