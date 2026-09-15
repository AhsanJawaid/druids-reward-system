<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RewardController extends Controller
{
    public function index(): View
    {
        return view('admin.rewards', [
            'rewards' => Reward::query()->orderBy('points_cost')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'points_cost' => ['required', 'integer', 'min:1'],
            'discount_type' => ['required', 'in:fixed_amount,percentage'],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
        ]);

        Reward::query()->create($data + ['active' => true]);

        return back()->with('status', 'Reward added to the catalog.');
    }

    public function update(Request $request, Reward $reward): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'points_cost' => ['required', 'integer', 'min:1'],
            'discount_type' => ['required', 'in:fixed_amount,percentage'],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'active' => ['nullable', 'boolean'],
        ]);

        $reward->update([
            ...$data,
            'active' => $request->boolean('active'),
        ]);

        return back()->with('status', 'Reward updated.');
    }
}
