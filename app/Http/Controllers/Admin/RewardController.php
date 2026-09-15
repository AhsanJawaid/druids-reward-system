<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use Illuminate\View\View;

class RewardController extends Controller
{
    public function index(): View
    {
        try {
            Reward::syncCatalog();
        } catch (\Throwable) {
            //
        }

        return view('admin.rewards', [
            'rewards' => Reward::query()->where('active', true)->orderBy('points_cost')->orderBy('name')->get(),
            'earn' => [
                ['action' => 'Make a purchase', 'points' => '2 points per £1 spent', 'conditions' => 'Calculated on the amount actually paid after discounts. Points are subject to a 14-day hold before they become spendable.'],
                ['action' => 'Create an account', 'points' => '200 points', 'conditions' => 'One-time only, per customer.'],
                ['action' => 'Newsletter signup', 'points' => '100 points', 'conditions' => 'One-time only. Awarded only on a genuine opt-in, and not on every profile save.'],
                ['action' => 'Birthday', 'points' => '250 points', 'conditions' => 'Once per calendar year, based on a date the customer provides.'],
            ],
        ]);
    }
}
