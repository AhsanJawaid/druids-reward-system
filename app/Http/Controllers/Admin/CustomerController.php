<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Rewards\RewardsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(): View
    {
        return view('admin.customers', [
            'customers' => Customer::query()->orderByDesc('points_balance')->get(),
        ]);
    }

    public function show(Customer $customer): View
    {
        $customer->load(['transactions' => fn ($q) => $q->latest(), 'redemptions.reward']);

        return view('admin.customer', ['customer' => $customer]);
    }

    public function adjust(Request $request, Customer $customer, RewardsService $rewards): RedirectResponse
    {
        $data = $request->validate([
            'points' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $rewards->adjust($customer, (int) $data['points'], $data['reason']);

        return back()->with('status', 'Balance adjusted.');
    }
}
