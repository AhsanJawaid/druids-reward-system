<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopifyEvent;
use App\Services\Rewards\RewardsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebhookSimulatorController extends Controller
{
    public function __invoke(Request $request, RewardsService $rewards): RedirectResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'in:orders/paid,refunds/create'],
            'email' => ['required', 'email'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);

        $shop = (string) config('shopify.store_domain');
        $email = strtolower($data['email']);
        $amount = number_format((float) $data['amount'], 2, '.', '');

        if ($data['topic'] === 'orders/paid') {
            $orderId = 'gid://shopify/Order/sim'.now()->timestamp;
            $payload = [
                'id' => $orderId,
                'admin_graphql_api_id' => $orderId,
                'name' => '#SIM-'.now()->format('His'),
                'email' => $email,
                'financial_status' => 'paid',
                'subtotal_price' => $amount,
                'total_price' => $amount,
                'currency' => 'USD',
                'customer' => [
                    'id' => 'gid://shopify/Customer/sim',
                    'email' => $email,
                    'first_name' => $data['name'] ?: strstr($email, '@', true),
                ],
            ];
            $tx = $rewards->earnFromOrder($payload, $shop);
            $message = $tx ? "Awarded {$tx->points} points (idempotent key {$tx->idempotency_key})." : 'No points awarded.';
        } else {
            $refundId = 'gid://shopify/Refund/sim'.now()->timestamp;
            $payload = [
                'id' => $refundId,
                'order_id' => 'sim-order',
                'email' => $email,
                'transactions' => [['amount' => $amount]],
                'customer' => ['email' => $email],
            ];
            $tx = $rewards->refundOrder($payload, $shop);
            $message = $tx ? "Reversed {$tx->points} points." : 'No points reversed.';
        }

        ShopifyEvent::query()->create([
            'topic' => $data['topic'],
            'shop_domain' => $shop,
            'webhook_id' => 'admin-sim-'.uniqid(),
            'hmac_verified' => false,
            'status' => 'processed',
            'message' => $message,
            'payload' => $payload,
        ]);

        return back()->with('status', $message);
    }
}
