<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ShopifyGraphQLException;
use App\Http\Controllers\Controller;
use App\Models\GraphqlLog;
use App\Models\ProgramSetting;
use App\Models\ShopifyEvent;
use App\Services\Rewards\RewardsService;
use App\Services\Shopify\ShopifyConfig;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Support\EnsureAssessmentSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function edit(): View
    {
        return view('admin.settings', [
            'settings' => ProgramSetting::current(),
            'events' => ShopifyEvent::query()->latest()->limit(20)->get(),
            'logs' => GraphqlLog::query()->latest()->limit(20)->get(),
            'shop' => ShopifyConfig::storeDomain(),
            'apiVersion' => ShopifyConfig::apiVersion(),
            'mocked' => app(ShopifyGraphqlClient::class)->shouldMock(),
            'tokenHint' => ShopifyConfig::tokenHint(),
            'hasToken' => ShopifyConfig::hasToken(),
            'clientId' => ShopifyConfig::clientId(),
            'hasClientSecret' => ShopifyConfig::clientSecret() !== '',
            'callbackBase' => ShopifyConfig::callbackBase(),
            'webhookUrl' => ShopifyConfig::callbackUrl(),
            'live' => ShopifyConfig::isLive(),
            'schemaReady' => Schema::hasTable('rewards') && Schema::hasColumn('rewards', 'slug'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'program_name' => ['required', 'string', 'max:80'],
            'points_per_pound' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'hold_days' => ['required', 'integer', 'min:0', 'max:90'],
            'min_order_amount' => ['required', 'numeric', 'min:0'],
            'discount_expiry_days' => ['required', 'integer', 'min:1', 'max:365'],
            'free_product_collection_id' => ['nullable', 'string', 'max:120'],
        ]);

        foreach ($data as $key => $value) {
            ProgramSetting::putValue($key, (string) ($value ?? ''));
        }

        return back()->with('status', 'Program rules saved.');
    }

    public function connect(Request $request): RedirectResponse
    {
        $request->merge([
            'shopify_callback_url' => $request->input('shopify_callback_url') ?: null,
            'shopify_access_token' => $request->input('shopify_access_token') ?: null,
            'shopify_webhook_secret' => $request->input('shopify_webhook_secret') ?: null,
            'shopify_client_id' => $request->input('shopify_client_id') ?: null,
            'shopify_client_secret' => $request->input('shopify_client_secret') ?: null,
        ]);
        $data = $request->validate([
            'shopify_store_domain' => ['required', 'string', 'max:120'],
            'shopify_access_token' => ['nullable', 'string', 'max:2048'],
            'shopify_webhook_secret' => ['nullable', 'string', 'max:255'],
            'shopify_client_id' => ['nullable', 'string', 'max:120'],
            'shopify_client_secret' => ['nullable', 'string', 'max:255'],
            'shopify_callback_url' => ['nullable', 'url', 'max:255'],
            'shopify_live' => ['nullable', 'boolean'],
        ]);

        $data['shopify_access_token'] = filled($data['shopify_access_token'] ?? null)
            ? ShopifyConfig::sanitizeToken((string) $data['shopify_access_token'])
            : null;
        $data['shopify_webhook_secret'] = filled($data['shopify_webhook_secret'] ?? null)
            ? trim((string) $data['shopify_webhook_secret'])
            : null;
        $data['shopify_client_id'] = filled($data['shopify_client_id'] ?? null)
            ? trim((string) $data['shopify_client_id'])
            : null;
        $data['shopify_client_secret'] = filled($data['shopify_client_secret'] ?? null)
            ? trim((string) $data['shopify_client_secret'])
            : null;

        if (filled($data['shopify_access_token'])) {
            $token = $data['shopify_access_token'];
            if (str_starts_with($token, 'shpss_') || str_starts_with($token, 'shpca_')) {
                return back()->withErrors([
                    'shopify_access_token' => 'Put the API secret in Client secret / Webhook secret, not in Access token. Access token must start with shpat_ — or leave it blank and use Client ID + Client secret.',
                ]);
            }
            if (! str_starts_with($token, 'shpat_')) {
                return back()->withErrors([
                    'shopify_access_token' => 'Leave Access token blank if you are using Client ID + secret. A static token must start with shpat_.',
                ]);
            }
        }

        $domain = ShopifyConfig::normalizeDomain($data['shopify_store_domain']);
        if (! str_ends_with($domain, '.myshopify.com') || in_array($domain, [
            'your-store.myshopify.com',
            'your-shop.myshopify.com',
            'demo-store.myshopify.com',
        ], true)) {
            return back()->withErrors([
                'shopify_store_domain' => 'Enter your real *.myshopify.com domain from Shopify Admin → Settings → Domains. Do not use the store’s custom .com address.',
            ]);
        }

        $willHaveToken = filled($data['shopify_access_token']) || ShopifyConfig::hasToken();
        $willHaveClient = filled($data['shopify_client_id']) && (filled($data['shopify_client_secret']) || filled($data['shopify_webhook_secret']) || ShopifyConfig::hasClientCredentials());
        if (! $willHaveToken && ! $willHaveClient && ! filled($data['shopify_client_id'])) {
            return back()->withErrors(['shopify_access_token' => 'Paste a shpat_ Admin API token, or Client ID + Client secret from the Dev Dashboard.']);
        }

        if ($request->boolean('shopify_live') && blank($data['shopify_webhook_secret'] ?? null) && blank(ShopifyConfig::webhookSecret()) && blank($data['shopify_client_secret'])) {
            return back()->withErrors(['shopify_webhook_secret' => 'Live mode needs the API secret key (webhook HMAC) or a Dev Dashboard client secret.']);
        }

        $webhookSecret = $data['shopify_webhook_secret'] ?: $data['shopify_client_secret'];

        ShopifyConfig::saveConnection(
            $domain,
            $data['shopify_access_token'] ?? null,
            $webhookSecret,
            $data['shopify_callback_url'] ?: ShopifyConfig::publicBase(),
            $request->boolean('shopify_live'),
            $data['shopify_client_id'] ?? null,
            $data['shopify_client_secret'] ?? null,
        );

        return back()->with('status', 'Shopify connection saved. Next: Test connection.');
    }

    public function test(ShopifyGraphqlClient $shopify): RedirectResponse
    {
        try {
            $shop = $shopify->pingShop();
        } catch (ShopifyGraphQLException $e) {
            return back()->withErrors(['shopify_access_token' => $e->getMessage()]);
        }

        return back()->with('status', 'Connected to '.$shop['name'].' ('.$shop['domain'].') via Admin GraphQL.');
    }

    public function registerWebhooks(ShopifyGraphqlClient $shopify): RedirectResponse
    {
        $url = ShopifyConfig::callbackUrl();
        if (str_contains($url, '127.0.0.1') || str_contains($url, 'localhost') || str_starts_with($url, 'http://')) {
            return back()->withErrors([
                'shopify_callback_url' => 'Shopify will only send webhooks to a public HTTPS URL. Put an ngrok (or similar) HTTPS origin in “Public app URL”, save, then register again. Current: '.$url,
            ]);
        }

        try {
            $created = $shopify->registerOrderWebhooks($url);
        } catch (ShopifyGraphQLException $e) {
            return back()->withErrors(['shopify_callback_url' => $e->getMessage()]);
        }

        $topics = collect($created)->pluck('topic')->implode(', ');

        return back()->with('status', 'Registered GraphQL webhooks for '.$topics.' → '.$url);
    }

    public function sync(ShopifyGraphqlClient $shopify, RewardsService $rewards): RedirectResponse
    {
        if ($shopify->shouldMock()) {
            return back()->withErrors([
                'shopify_access_token' => 'Turn on live mode, save, and Test connection first. Then pull customers and orders from Shopify.',
            ]);
        }

        try {
            $result = $rewards->syncLiveShopify(ShopifyConfig::storeDomain());
        } catch (ShopifyGraphQLException $e) {
            return back()->withErrors(['shopify_access_token' => $e->getMessage()]);
        }

        return back()->with(
            'status',
            'Pulled from Shopify: '.$result['customers'].' customer bonus(es), '.$result['orders'].' paid order(s). Look up the customer email on the Portal.'
        );
    }

    public function repairDatabase(): RedirectResponse
    {
        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (\Throwable $e) {
            // Hostinger often cannot run artisan migrate; column-by-column apply still works.
        }

        try {
            EnsureAssessmentSchema::apply();
        } catch (\Throwable $e) {
            return back()->withErrors(['program_name' => 'Database update failed: '.$e->getMessage()]);
        }

        return back()->with('status', 'Database updated for the new rewards spec. Open Portal, Reports, and Rewards — the 500 error should be gone.');
    }
}
