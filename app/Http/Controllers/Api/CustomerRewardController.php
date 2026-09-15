<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\IneligibleProductException;
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
            ->with(['transactions' => fn ($q) => $q->latest()->limit(50), 'redemptions.reward'])
            ->first();

        $rewards = Reward::query()->where('active', true)->orderBy('points_cost')->get();

        if (! $customer) {
            return response()->json([
                'customer' => null,
                'settings' => ProgramSetting::current(),
                'rewards' => $rewards,
            ], 404);
        }

        return response()->json([
            'customer' => [
                ...$customer->toArray(),
                'spendable_points' => $customer->spendablePoints(),
                'pending_points' => $customer->pendingPoints(),
            ],
            'settings' => ProgramSetting::current(),
            'rewards' => $rewards,
        ]);
    }

    public function redeem(Request $request, RewardsService $rewards): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'reward_id' => ['required', 'integer', 'exists:rewards,id'],
            'product_id' => ['nullable', 'string', 'max:120'],
        ]);

        $customer = Customer::query()->where('email', strtolower($data['email']))->first();
        if (! $customer) {
            return response()->json(['error' => 'Customer not found'], 404);
        }

        try {
            $redemption = $rewards->redeem(
                $customer,
                Reward::query()->findOrFail($data['reward_id']),
                $data['product_id'] ?? null,
            );
        } catch (InsufficientPointsException|IneligibleProductException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $fresh = $customer->fresh();

        return response()->json([
            'code' => $redemption->discount_code,
            'discount_code' => $redemption->discount_code,
            'shopify_id' => $redemption->shopify_discount_id,
            'shopify_object_type' => $redemption->shopify_object_type,
            'expires_at' => $redemption->expires_at,
            'balance' => $fresh->points_balance,
            'spendable_points' => $fresh->spendablePoints(),
            'graphql' => $redemption->graphql_result,
        ]);
    }
}
