<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopifyEvent;
use App\Services\Rewards\RewardsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopifyWebhookController extends Controller
{
    public function __invoke(Request $request, RewardsService $rewards): JsonResponse
    {
        $topic = (string) $request->header('X-Shopify-Topic', $request->input('topic', ''));
        $shop = (string) $request->header('X-Shopify-Shop-Domain', config('shopify.store_domain'));
        $payload = $request->json()->all() ?: $request->all();
        $verified = (bool) $request->attributes->get('shopify_hmac_verified', false);

        $message = 'ignored';
        $status = 'ignored';

        try {
            if (in_array($topic, ['orders/paid', 'orders/create'], true)) {
                $tx = $rewards->earnFromOrder($payload, $shop);
                $message = $tx ? "earn:{$tx->points}:{$tx->idempotency_key}" : 'no-earn';
                $status = 'processed';
            } elseif ($topic === 'refunds/create') {
                $tx = $rewards->refundOrder($payload, $shop);
                $message = $tx ? "refund:{$tx->points}:{$tx->idempotency_key}" : 'no-refund';
                $status = 'processed';
            }
        } catch (\Throwable $e) {
            $status = 'failed';
            $message = $e->getMessage();
        }

        ShopifyEvent::query()->create([
            'topic' => $topic !== '' ? $topic : 'unknown',
            'shop_domain' => $shop,
            'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
            'hmac_verified' => $verified,
            'status' => $status,
            'message' => $message,
            'payload' => $payload,
        ]);

        return response()->json([
            'ok' => $status !== 'failed',
            'topic' => $topic,
            'message' => $message,
        ], $status === 'failed' ? 500 : 200);
    }
}
