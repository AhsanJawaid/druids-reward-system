@extends('layouts.admin')

@section('title', 'Connect Shopify')

@section('content')
<p class="kicker">Setup</p>
<h1>Connect Shopify</h1>
<p class="muted">No GraphQL account is required. GraphQL is Shopify’s Admin API. Your custom app token is the login.</p>

@if (! $schemaReady)
<div class="help" style="border:1px solid #fecaca;background:#fef2f2">
    <strong>Other pages show 500 after this update</strong>
    <p class="tiny" style="margin:8px 0">The new code needs extra database columns (hold dates, reward slugs, gift cards). Uploading PHP files does not run that update by itself.</p>
    <form method="post" action="{{ route('admin.settings.repair') }}">
        @csrf
        <button type="submit">Update database</button>
    </form>
    <p class="tiny" style="margin-top:10px">Then open Portal, Reports, and Rewards. If this button fails, run the SQL in <code>docs/fix-database.sql</code> in phpMyAdmin.</p>
</div>
@endif

<div class="help">
    <strong>Create these Shopify webhooks (all of them)</strong>
    <p class="tiny" style="margin:8px 0">Same URL and JSON format for each. Custom apps usually cannot register these via the API — add them in the app’s Configuration / Webhooks screen, or Settings → Notifications.</p>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Shopify event name</th>
                <th>Topic</th>
                <th>Awards / action</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td><strong>Order payment</strong></td>
                <td><code>orders/paid</code></td>
                <td>2 points per £1 after discounts, 14-day hold. Mark the order as paid.</td>
            </tr>
            <tr>
                <td>2</td>
                <td><strong>Refund create</strong></td>
                <td><code>refunds/create</code></td>
                <td>Reverses purchase points for the refunded amount.</td>
            </tr>
            <tr>
                <td>3</td>
                <td><strong>Customer creation</strong></td>
                <td><code>customers/create</code></td>
                <td>200 account points, once. Also 100 if they genuinely subscribe at signup.</td>
            </tr>
            <tr>
                <td>4</td>
                <td><strong>Customer update</strong></td>
                <td><code>customers/update</code></td>
                <td>100 newsletter points on footer/theme signup (tag <code>newsletter</code>) and on marketing consent. 250 birthday points once per year.</td>
            </tr>
            <tr>
                <td>5</td>
                <td><strong>Customer email marketing consent update</strong></td>
                <td><code>customers_email_marketing_consent/update</code></td>
                <td>100 newsletter points when Shopify records a real subscribe (including Shopify Forms / Email).</td>
            </tr>
        </tbody>
    </table>
    <p class="tiny" style="margin:10px 0 6px">Callback URL (copy exactly):</p>
    <p><code>{{ $webhookUrl }}</code></p>
    <p class="tiny">The store footer form must be Shopify’s customer/newsletter form (or Shopify Forms), not Mailchimp/Klaviyo. Look up the same email on the Portal after subscribe. Points are not emailed and no coupon is created until they redeem.</p>
</div>

<div class="help">
    <strong>In Shopify Admin</strong>
    <ol>
        <li>Settings → Apps → Develop apps → Create an app</li>
        <li>Allow: <code>read_orders</code>, <code>read_customers</code>, <code>read_products</code>, <code>write_discounts</code>, <code>write_gift_cards</code></li>
        <li>Install the app and copy the Admin API access token (<code>shpat_…</code>)</li>
        <li>Copy the API secret key (used to verify webhooks)</li>
        <li>Use the <code>*.myshopify.com</code> hostname from Settings → Domains, not meridianwellnesshub.com</li>
        <li>Public HTTPS URL must be this app’s address, including <code>/public</code> if that is in the browser bar</li>
    </ol>
</div>

<div class="help">
    <strong>If “Turn on order webhooks” says 401</strong>
    <p class="tiny" style="margin:8px 0 0">That is normal for custom apps. Create the four webhooks in the table above by hand. Then mark a test order as <strong>paid</strong>.</p>
</div>

<section class="split">
    <div class="panel">
        <h2>Store details</h2>
        <form method="post" action="{{ route('admin.settings.connect') }}">
            @csrf
            <label>Shop domain</label>
            <input name="shopify_store_domain" value="{{ $shop }}" required placeholder="your-store.myshopify.com">
            <label>Access token {{ $hasToken ? '(saved '.$tokenHint.')' : '' }}</label>
            <input name="shopify_access_token" type="password" autocomplete="off" placeholder="{{ $hasToken ? 'Leave blank to keep the saved token' : 'shpat_…' }}">
            <label>Webhook secret</label>
            <input name="shopify_webhook_secret" type="password" autocomplete="off" placeholder="Leave blank to keep the saved secret">
            <label>Public HTTPS URL</label>
            <input name="shopify_callback_url" type="url" required value="{{ (str_contains($callbackBase, 'ngrok') || str_contains($callbackBase, 'localhost') || str_contains($callbackBase, '127.0.0.1')) ? rtrim(preg_replace('#^http://#', 'https://', url('/')), '/') : $callbackBase }}" placeholder="https://yourdomain.com/druids-reward-hub/public">
            <p class="tiny">Webhooks will go to <code>{{ $webhookUrl }}</code></p>
            <label><input type="checkbox" name="shopify_live" value="1" @checked($live) style="width:auto"> Use the live Shopify API</label>
            <button type="submit">Save</button>
        </form>
        <div class="row-actions">
            <form method="post" action="{{ route('admin.settings.test') }}">
                @csrf
                <button class="secondary" type="submit">Test connection</button>
            </form>
            <form method="post" action="{{ route('admin.settings.webhooks') }}">
                @csrf
                <button class="secondary" type="submit">Turn on order webhooks</button>
            </form>
        </div>
        <p class="tiny" style="margin-top:12px"><span class="pill {{ $mocked ? 'warn' : 'good' }}">{{ $mocked ? 'Demo mode' : 'Live' }}</span> API {{ $apiVersion }}</p>
    </div>
    <div class="panel">
        <h2>Program rules (assessment spec)</h2>
        <form method="post" action="{{ route('admin.settings.update') }}">
            @csrf
            @method('PUT')
            <label>Program name</label>
            <input name="program_name" value="{{ $settings['program_name'] }}" required>
            <label>Points per £1 (after discounts)</label>
            <input name="points_per_pound" type="number" step="0.01" min="0.01" value="{{ $settings['points_per_pound'] }}" required>
            <label>Purchase-point hold (days)</label>
            <input name="hold_days" type="number" min="0" value="{{ $settings['hold_days'] }}" required>
            <label>Minimum order (£)</label>
            <input name="min_order_amount" type="number" step="0.01" min="0" value="{{ $settings['min_order_amount'] }}" required>
            <label>Code / gift card expiry (days)</label>
            <input name="discount_expiry_days" type="number" min="1" value="{{ $settings['discount_expiry_days'] }}" required>
            <label>Eligible free-product collection ID</label>
            <input name="free_product_collection_id" value="{{ $settings['free_product_collection_id'] }}" placeholder="gid://shopify/Collection/123 or numeric id">
            <p class="tiny">Server-side redemption checks that the chosen product belongs to this collection before issuing the code.</p>
            <button type="submit">Save rules</button>
        </form>
    </div>
</section>

<section class="split" style="margin-top:16px">
    <div class="panel">
        <h2>Webhook log</h2>
        <table>
            <thead><tr><th>Event</th><th>Verified</th><th>Result</th></tr></thead>
            <tbody>
            @forelse ($events as $event)
                <tr>
                    <td>{{ $event->topic }}<div class="tiny">{{ $event->created_at }}</div></td>
                    <td>{{ $event->hmac_verified ? 'Yes' : 'No' }}</td>
                    <td>{{ $event->message }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">No webhooks yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="panel">
        <h2>API log</h2>
        @forelse ($logs as $log)
            <details style="margin-bottom:10px">
                <summary><span class="pill {{ $log->mocked ? 'warn' : 'good' }}">{{ $log->mocked ? 'demo' : 'live' }}</span> {{ $log->operation }} · {{ $log->created_at->format('H:i:s') }}</summary>
                <pre>{{ json_encode(['variables' => $log->variables, 'response' => $log->response], JSON_PRETTY_PRINT) }}</pre>
            </details>
        @empty
            <p class="muted">Nothing logged yet.</p>
        @endforelse
    </div>
</section>
@endsection
