<?php

namespace App\Http\Middleware;

use App\Models\ShopifyEvent;
use App\Services\Shopify\ShopifyConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VerifyShopifyWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $hmac = (string) $request->header('X-Shopify-Hmac-Sha256', '');
        $raw = $request->getContent();
        $secrets = ShopifyConfig::webhookSigningSecrets();
        if ($secrets === []) {
            $fallback = (string) config('shopify.webhook_secret');
            if ($fallback !== '') {
                $secrets[] = $fallback;
            }
        }

        $mustVerify = ShopifyConfig::isLive() || app()->environment('production');

        if ($secrets === []) {
            if ($mustVerify) {
                $this->recordRejection($request, $raw, 'Webhook secret is required in live mode');

                return response()->json(['error' => 'Webhook secret is required in live mode'], 401);
            }

            $request->attributes->set('shopify_hmac_verified', false);

            return $next($request);
        }

        foreach ($secrets as $secret) {
            $calculated = base64_encode(hash_hmac('sha256', $raw, $secret, true));
            if ($hmac !== '' && hash_equals($calculated, $hmac)) {
                $request->attributes->set('shopify_hmac_verified', true);

                return $next($request);
            }
        }

        $this->recordRejection($request, $raw, 'Invalid Shopify HMAC — check API secret key vs Client secret, and that the webhook URL is exactly the one on Settings.');

        return response()->json(['error' => 'Invalid Shopify HMAC'], 401);
    }

    private function recordRejection(Request $request, string $raw, string $message): void
    {
        try {
            $payload = json_decode($raw, true);
            ShopifyEvent::query()->create([
                'topic' => (string) $request->header('X-Shopify-Topic', 'unknown'),
                'shop_domain' => (string) $request->header('X-Shopify-Shop-Domain', ''),
                'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
                'hmac_verified' => false,
                'status' => 'rejected',
                'message' => $message,
                'payload' => is_array($payload) ? $payload : ['raw' => substr($raw, 0, 2000)],
            ]);
        } catch (Throwable) {
            // Never block Shopify's retry loop with a logging failure.
        }
    }
}
