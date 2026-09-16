@extends('layouts.store')

@section('title', $settings['program_name'])

@section('content')
<section class="hero">
    <div>
        <p class="kicker">{{ $settings['program_name'] }}</p>
        <h1>Earn points from Shopify. Spend them on real rewards.</h1>
        <p class="lede">2 points per £1 paid after discounts (14-day hold). 200 for creating an account, 100 for a genuine newsletter opt-in, 250 on your birthday each year. Spend 100 points = £1.</p>
        <div class="steps">
            <span class="step-chip">1. Look up your email</span>
            <span class="step-chip">2. Earn from real Shopify events</span>
            <span class="step-chip">3. Redeem a Shopify code or gift card</span>
        </div>
        <p class="tiny" style="margin-top:12px">{{ $mocked ? 'Shopify Admin API demo mode (no live token)' : 'Live Shopify store: '.$shop }}</p>
    </div>
    <aside class="stamp">
        <h2>Your points</h2>
        <form method="post" action="{{ route('storefront.identify') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required placeholder="you@email.com" value="{{ $customer?->email ?? session('storefront_email') }}">
            <button type="submit">Show my points</button>
        </form>
        @if ($customer)
            <p class="points" style="margin-top:16px">{{ number_format($customer->spendablePoints()) }} <small>spendable · {{ $customer->name ?: $customer->email }}</small></p>
            <p class="tiny">Ledger {{ number_format($customer->points_balance) }} · held {{ number_format($customer->pendingPoints()) }} (available after 14 days)</p>
        @else
            <p class="muted" style="margin-top:12px">Use the same email as your Shopify customer account.</p>
        @endif
        <form method="post" action="{{ route('storefront.birthday') }}" style="margin-top:16px">
            @csrf
            @if ($customer || session('storefront_email'))
                <input type="hidden" name="email" value="{{ $customer?->email ?? session('storefront_email') }}">
            @else
                <label for="birthday-email">Email</label>
                <input id="birthday-email" name="email" type="email" required placeholder="you@email.com">
            @endif
            <label for="birthday">Your birthday</label>
            <input id="birthday" name="birthday" type="date" required value="{{ $customer?->birthday?->format('Y-m-d') }}">
            <button type="submit">Save birthday · 250 pts if today</button>
        </form>
        <p class="tiny">Shopify does not send the customer-profile metafield in webhooks. Save the date here (the date you provide). If today matches month and day, 250 points post once this year.</p>
    </aside>
</section>

@if ($customer && ($issuedCodes->isNotEmpty() || session('discount_code')))
<section class="panel" style="margin-bottom:24px">
    <h2>Your Shopify codes</h2>
    <p class="muted">Each redemption created a discount code or gift card in Shopify. Paste the code at checkout.</p>
    <table>
        <thead><tr><th>Code</th><th>Reward</th><th>Type</th><th>Issued</th><th>Use by</th></tr></thead>
        <tbody>
        @foreach ($issuedCodes as $code)
            <tr>
                <td><span class="code-chip">{{ $code->discount_code }}</span></td>
                <td>{{ $code->reward->name }}</td>
                <td class="tiny">{{ $code->shopify_object_type === 'gift_card' ? 'Gift card' : 'Discount code' }}</td>
                <td class="tiny">{{ $code->created_at->format('M j, g:ia') }}</td>
                <td class="tiny">{{ $code->expires_at?->format('M j, Y') ?: '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</section>
@endif

<section class="split">
    <div class="panel">
        <h2>Redeem a reward</h2>
        <p class="muted">Spendable points only. Purchase points cannot be spent until the 14-day hold ends.</p>
        <div class="rewards-list">
            @forelse ($rewards as $reward)
                <div class="reward-row" style="align-items:flex-start;flex-wrap:wrap">
                    <div>
                        <strong>{{ $reward->name }}</strong>
                        <div class="tiny">{{ $reward->description }}</div>
                        <div class="tiny">{{ number_format($reward->points_cost) }} points</div>
                    </div>
                    <form method="post" action="{{ route('storefront.redeem') }}">
                        @csrf
                        <input type="hidden" name="reward_id" value="{{ $reward->id }}">
                        @if ($reward->slug === 'free_product')
                            <label class="tiny">Eligible product</label>
                            <select name="product_id" required>
                                <option value="">Select an item</option>
                                @foreach ($eligibleProducts as $product)
                                    <option value="{{ $product['id'] }}">{{ $product['title'] }}</option>
                                @endforeach
                            </select>
                            @if ($eligibleProducts === [])
                                <p class="tiny">Set the eligible collection ID on Shopify settings.</p>
                            @endif
                        @endif
                        <button type="submit" {{ ! $customer || $customer->spendablePoints() < $reward->points_cost ? 'disabled' : '' }}>
                            {{ ! $customer ? 'Look up email first' : ($customer->spendablePoints() < $reward->points_cost ? 'Need spendable points' : 'Redeem') }}
                        </button>
                    </form>
                </div>
            @empty
                <p class="muted">Catalog not loaded. Open Admin → Rewards once.</p>
            @endforelse
        </div>
    </div>
    <div class="panel">
        <h2>Ledger</h2>
        @if ($customer)
            <table>
                <thead><tr><th>When</th><th>What happened</th><th>Points</th><th>Spendable</th></tr></thead>
                <tbody>
                @forelse ($customer->transactions()->latest()->limit(12)->get() as $tx)
                    <tr>
                        <td class="tiny">{{ $tx->created_at->format('M j, g:ia') }}</td>
                        <td>{{ $tx->description }}</td>
                        <td>{{ $tx->points > 0 ? '+' : '' }}{{ $tx->points }}</td>
                        <td class="tiny">
                            @if ($tx->available_at && $tx->available_at->isFuture())
                                Held until {{ $tx->available_at->format('M j') }}
                            @else
                                Now
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No activity yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        @else
            <p class="muted">Enter your email above to see history.</p>
        @endif
    </div>
</section>
@endsection
