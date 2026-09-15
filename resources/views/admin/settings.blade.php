@extends('layouts.admin')

@section('title', 'Connect Shopify')

@section('content')
<p class="kicker">Setup</p>
<h1>Connect Shopify</h1>
<p class="muted">No GraphQL account is required. GraphQL is Shopify’s Admin API. Your custom app token is the login.</p>

<div class="help">
    <strong>In Shopify Admin</strong>
    <ol>
        <li>Settings → Apps → Develop apps → Create an app</li>
        <li>Allow: <code>read_orders</code>, <code>read_customers</code>, <code>write_discounts</code></li>
        <li>Install the app and copy the Admin API access token (<code>shpat_…</code>)</li>
        <li>Copy the API secret key (used to verify webhooks)</li>
        <li>Use <code>your-store.myshopify.com</code>, not your custom domain</li>
        <li>On a live server, the public HTTPS URL is your site, for example <code>https://rewards.yourdomain.com</code></li>
    </ol>
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
            <input name="shopify_callback_url" type="url" value="{{ $callbackBase }}" placeholder="https://your-tunnel.ngrok-free.app">
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
        <h2>How points are earned</h2>
        <form method="post" action="{{ route('admin.settings.update') }}">
            @csrf
            @method('PUT')
            <label>Program name</label>
            <input name="program_name" value="{{ $settings['program_name'] }}" required>
            <label>Points per $1</label>
            <input name="points_per_dollar" type="number" step="0.01" min="0.01" value="{{ $settings['points_per_dollar'] }}" required>
            <label>Minimum order ($)</label>
            <input name="min_order_amount" type="number" step="0.01" min="0" value="{{ $settings['min_order_amount'] }}" required>
            <label>Code expires after (days)</label>
            <input name="discount_expiry_days" type="number" min="1" value="{{ $settings['discount_expiry_days'] }}" required>
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
