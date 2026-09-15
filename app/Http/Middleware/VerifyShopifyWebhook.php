<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = \App\Services\Shopify\ShopifyConfig::webhookSecret();
        if ($secret === '') {
            $secret = (string) config('shopify.webhook_secret');
        }
        $hmac = (string) $request->header('X-Shopify-Hmac-Sha256', '');
        $raw = $request->getContent();

        $mustVerify = \App\Services\Shopify\ShopifyConfig::isLive()
            || app()->environment('production');

        if ($secret === '') {
            if ($mustVerify) {
                return response()->json(['error' => 'Webhook secret is required in live mode'], 401);
            }

            $request->attributes->set('shopify_hmac_verified', false);

            return $next($request);
        }

        $calculated = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        if ($hmac === '' || ! hash_equals($calculated, $hmac)) {
            return response()->json(['error' => 'Invalid Shopify HMAC'], 401);
        }

        $request->attributes->set('shopify_hmac_verified', true);

        return $next($request);
    }
}
