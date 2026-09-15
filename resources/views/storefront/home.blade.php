@extends('layouts.store')

@section('title', $settings['program_name'])

@section('content')
<section class="hero">
    <div>
        <p class="kicker">{{ $settings['program_name'] }}</p>
        <h1>Shop, earn points, redeem a code</h1>
        <p class="lede">You get {{ rtrim(rtrim(number_format((float) $settings['points_per_dollar'], 2), '0'), '.') }} point{{ (float) $settings['points_per_dollar'] == 1 ? '' : 's' }} for every $1 spent. Redeem points for a Shopify discount code.</p>
        <div class="steps">
            <span class="step-chip">1. Enter your email</span>
            <span class="step-chip">2. Buy an item</span>
            <span class="step-chip">3. Redeem a reward</span>
        </div>
        <p class="tiny" style="margin-top:12px">{{ $mocked ? 'Demo mode (no live Shopify token yet)' : 'Live Shopify store: '.$shop }}</p>
    </div>
    <aside class="stamp">
        <h2>Your points</h2>
        <form method="post" action="{{ route('storefront.identify') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required placeholder="you@email.com" value="{{ $customer?->email ?? session('storefront_email', 'maya@atelier.example') }}">
            <button type="submit">Show my points</button>
        </form>
        @if ($customer)
            <p class="points" style="margin-top:16px">{{ number_format($customer->points_balance) }} <small>{{ $customer->name ?: $customer->email }}</small></p>
        @else
            <p class="muted" style="margin-top:12px">Try <strong>maya@atelier.example</strong> to see a sample balance.</p>
        @endif
    </aside>
</section>

<h2>Buy to earn</h2>
<p class="muted">Each purchase adds points using the same rules as a paid Shopify order.</p>
<div class="grid products">
    @foreach ($products as $product)
        <article class="card">
            <h3>{{ $product['name'] }}</h3>
            <p class="muted">{{ $product['blurb'] }}</p>
            <p class="price">${{ number_format($product['price'], 2) }}</p>
            <form method="post" action="{{ route('storefront.checkout') }}">
                @csrf
                <input type="hidden" name="sku" value="{{ $product['sku'] }}">
                <label>Email for this order</label>
                <input type="email" name="email" required value="{{ $customer?->email ?? session('storefront_email', 'maya@atelier.example') }}">
                <button type="submit">Buy and earn</button>
            </form>
        </article>
    @endforeach
</div>

<section class="split">
    <div class="panel">
        <h2>Redeem a reward</h2>
        <p class="muted">Spend points. We create a one-time discount code for your Shopify checkout.</p>
        <div class="rewards-list">
            @forelse ($rewards as $reward)
                <div class="reward-row">
                    <div>
                        <strong>{{ $reward->name }}</strong>
                        <div class="tiny">{{ $reward->label() }} · {{ $reward->points_cost }} points</div>
                    </div>
                    <form method="post" action="{{ route('storefront.redeem') }}">
                        @csrf
                        <input type="hidden" name="reward_id" value="{{ $reward->id }}">
                        <button type="submit" {{ ! $customer || $customer->points_balance < $reward->points_cost ? 'disabled' : '' }}>
                            {{ ! $customer ? 'Sign in first' : ($customer->points_balance < $reward->points_cost ? 'Need more points' : 'Redeem') }}
                        </button>
                    </form>
                </div>
            @empty
                <p class="muted">No rewards yet. Add them in Admin → Rewards.</p>
            @endforelse
        </div>
    </div>
    <div class="panel">
        <h2>History</h2>
        @if ($customer)
            <table>
                <thead><tr><th>When</th><th>What happened</th><th>Points</th></tr></thead>
                <tbody>
                @forelse ($customer->transactions()->latest()->limit(8)->get() as $tx)
                    <tr>
                        <td class="tiny">{{ $tx->created_at->format('M j, g:ia') }}</td>
                        <td>{{ $tx->description }}</td>
                        <td>{{ $tx->points > 0 ? '+' : '' }}{{ $tx->points }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">No activity yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        @else
            <p class="muted">Enter your email above to see history.</p>
        @endif
    </div>
</section>
@endsection
