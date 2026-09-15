@extends('layouts.admin')

@section('title', 'Reports')

@section('content')
<p class="kicker">Dashboard</p>
<h1>Reports</h1>
<p class="muted">{{ $settings['program_name'] }} · {{ $settings['points_per_dollar'] }} points per $1 · {{ $mocked ? 'Demo mode' : 'Live Shopify' }}</p>

<div class="stats">
    <div class="panel stat"><span class="tiny">Customers</span><b>{{ $customers }}</b></div>
    <div class="panel stat"><span class="tiny">Points given</span><b>{{ number_format($pointsIssued) }}</b></div>
    <div class="panel stat"><span class="tiny">Points spent</span><b>{{ number_format($pointsRedeemed) }}</b></div>
    <div class="panel stat"><span class="tiny">Discount codes</span><b>{{ $codesCount }}</b></div>
</div>
<p class="tiny">Last 30 days: {{ number_format($pointsThisMonth) }} points earned · {{ $redeemsThisMonth }} codes issued</p>

<section class="panel" style="margin:18px 0">
    <h2>Where the redeem codes are</h2>
    <p class="muted">Every time a shopper redeems, Shopify (or demo mode) creates a one-time checkout code. Copy it from this table, or the shopper sees it on the Portal.</p>
    <table>
        <thead>
            <tr>
                <th>Discount code</th>
                <th>Customer</th>
                <th>Reward</th>
                <th>Points</th>
                <th>Issued</th>
                <th>Use by</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($codes as $code)
            <tr>
                <td><span class="code-chip">{{ $code->discount_code }}</span></td>
                <td><a href="{{ route('admin.customers.show', $code->customer) }}">{{ $code->customer->email }}</a></td>
                <td>{{ $code->reward->name }}</td>
                <td>{{ $code->points_spent }}</td>
                <td class="tiny">{{ $code->created_at->format('M j, g:ia') }}</td>
                <td class="tiny">{{ $code->expires_at?->format('M j, Y') ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No codes yet. Redeem a reward on the Portal to create one.</td></tr>
        @endforelse
        </tbody>
    </table>
</section>

<section class="split">
    <div class="panel">
        <h2>Top balances</h2>
        <table>
            <thead><tr><th>Customer</th><th>Points</th></tr></thead>
            <tbody>
            @forelse ($topCustomers as $member)
                <tr>
                    <td><a href="{{ route('admin.customers.show', $member) }}">{{ $member->email }}</a><div class="tiny">{{ $member->name }}</div></td>
                    <td>{{ number_format($member->points_balance) }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="muted">No customers yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        <h2 style="margin-top:20px">Recent points</h2>
        <table>
            <thead><tr><th>Customer</th><th>Event</th><th>Pts</th></tr></thead>
            <tbody></tbody>
            @forelse ($recentTx as $tx)
                <tr>
                    <td><a href="{{ route('admin.customers.show', $tx->customer) }}">{{ $tx->customer->email }}</a></td>
                    <td>{{ $tx->description }}</td>
                    <td>{{ $tx->points > 0 ? '+' : '' }}{{ $tx->points }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">Nothing yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="panel">
        <h2>Add test points</h2>
        <p class="tiny">Pretend a Shopify order was paid, without waiting for a real order.</p>
        <form method="post" action="{{ route('admin.webhooks.simulate') }}">
            @csrf
            <label>Action</label>
            <select name="topic">
                <option value="orders/paid">Customer bought something</option>
                <option value="refunds/create">Customer got a refund</option>
            </select>
            <label>Customer email</label>
            <input name="email" type="email" required value="maya@example.com">
            <label>Name</label>
            <input name="name" value="Maya Chen">
            <label>Order amount ($)</label>
            <input name="amount" type="number" step="0.01" min="0.01" value="48.00">
            <button type="submit">Apply</button>
        </form>
        <p class="tiny" style="margin-top:12px"><a href="{{ route('admin.settings.edit') }}">Connect your Shopify store →</a></p>
    </div>
</section>
@endsection
