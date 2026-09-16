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
            if (in_array($topic, [
                'orders/paid',
                'orders/create',
                'orders/updated',
                'orders/fulfilled',
            ], true)) {
                $tx = $rewards->earnFromOrder($payload, $shop);
                $birthday = $rewards->awardBirthdayIfDue($this->customerPayloadFromOrder($payload), $shop);
                $parts = [];
                if ($tx) {
                    $parts[] = "earn:{$tx->points}:{$tx->idempotency_key}";
                }
                if ($birthday && $birthday->wasRecentlyCreated) {
                    $parts[] = "birthday:{$birthday->points}";
                }
                $message = $parts === [] ? 'no-earn' : implode(',', $parts);
                $status = 'processed';
            } elseif ($topic === 'refunds/create') {
                $tx = $rewards->refundOrder($payload, $shop);
                $message = $tx ? "refund:{$tx->points}:{$tx->idempotency_key}" : 'no-refund';
                $status = 'processed';
            } elseif (in_array($topic, [
                'customers/create',
                'customers/update',
                'customers_email_marketing_consent/update',
                'customers/email_marketing_consent_update',
            ], true)) {
                $txs = $rewards->processCustomerWebhook($payload, $shop, $topic);
                $message = $txs === []
                    ? 'no-customer-earn'
                    : collect($txs)->map(fn ($tx) => "{$tx->source}:{$tx->points}")->implode(',');
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

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function customerPayloadFromOrder(array $order): array
    {
        $customer = (array) data_get($order, 'customer', []);
        $customer['email'] = $customer['email'] ?? data_get($order, 'email');
        $customer['note_attributes'] = $customer['note_attributes'] ?? data_get($order, 'note_attributes', []);
        $customer['note'] = $customer['note'] ?? data_get($order, 'note', data_get($order, 'customer.note'));
        $customer['tags'] = $customer['tags'] ?? data_get($order, 'tags', data_get($order, 'customer.tags'));

        return $customer;
    }
}
