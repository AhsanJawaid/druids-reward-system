<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientPointsException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ProgramSetting;
use App\Models\Reward;
use App\Services\Rewards\RewardsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerRewardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $customer = Customer::query()
            ->where('email', strtolower($data['email']))
            ->with(['transactions' => fn ($q) => $q->latest()->limit(20), 'redemptions.reward'])
            ->first();

        if (! $customer) {
            return response()->json([
                'customer' => null,
                'settings' => ProgramSetting::current(),
                'rewards' => Reward::query()->where('active', true)->orderBy('points_cost')->get(),
            ], 404);
        }

        return response()->json([
            'customer' => $customer,
            'settings' => ProgramSetting::current(),
            'rewards' => Reward::query()->where('active', true)->orderBy('points_cost')->get(),
        ]);
    }

    public function redeem(Request $request, RewardsService $rewards): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'reward_id' => ['required', 'integer', 'exists:rewards,id'],
        ]);

        $customer = Customer::query()->where('email', strtolower($data['email']))->first();
        if (! $customer) {
            return response()->json(['error' => 'Customer not found'], 404);
        }

        try {
            $redemption = $rewards->redeem($customer, Reward::query()->findOrFail($data['reward_id']));
        } catch (InsufficientPointsException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'discount_code' => $redemption->discount_code,
            'shopify_discount_id' => $redemption->shopify_discount_id,
            'expires_at' => $redemption->expires_at,
            'balance' => $customer->fresh()->points_balance,
            'graphql' => $redemption->graphql_result,
        ]);
    }
}
