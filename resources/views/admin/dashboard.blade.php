@extends('layouts.admin')

@section('title', 'Reports')

@section('content')
<p class="kicker">Dashboard</p>
<h1>Reports</h1>
<p class="muted">{{ $settings['program_name'] }} · 2 points per £1 after discounts · 14-day hold · {{ $mocked ? 'Demo Shopify API' : 'Live Shopify' }}</p>

<div class="stats">
    <div class="panel stat"><span class="tiny">Customers</span><b>{{ $customers }}</b></div>
    <div class="panel stat"><span class="tiny">Points given</span><b>{{ number_format($pointsIssued) }}</b></div>
    <div class="panel stat"><span class="tiny">Points spent</span><b>{{ number_format($pointsRedeemed) }}</b></div>
    <div class="panel stat"><span class="tiny">Shopify objects issued</span><b>{{ $codesCount }}</b></div>
</div>
<p class="tiny">Last 30 days: {{ number_format($pointsThisMonth) }} points earned · {{ $redeemsThisMonth }} redemptions</p>

<section class="panel" style="margin:18px 0">
    <h2>Issued Shopify codes and gift cards</h2>
    <p class="muted">Every redemption calls the Admin API. Codes below are real discount codes or gift cards (or demo GraphQL objects when no token is saved).</p>
    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Customer</th>
                <th>Reward</th>
                <th>Object</th>
                <th>Points</th>
                <th>Issued</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($codes as $code)
            <tr>
                <td><span class="code-chip">{{ $code->discount_code }}</span></td>
                <td><a href="{{ route('admin.customers.show', $code->customer) }}">{{ $code->customer->email }}</a></td>
                <td>{{ $code->reward->name }}</td>
                <td class="tiny">{{ ($code->shopify_object_type ?? 'discount_code') === 'gift_card' ? 'Gift card' : 'Discount' }}</td>
                <td>{{ $code->points_spent }}</td>
                <td class="tiny">{{ $code->created_at->format('M j, g:ia') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No redemptions yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</section>

<section class="split">
    <div class="panel">
        <h2>Top balances</h2>
        <table>
            <thead><tr><th>Customer</th><th>Ledger</th><th>Spendable</th></tr></thead>
            <tbody>
            @forelse ($topCustomers as $member)
                <tr>
                    <td><a href="{{ route('admin.customers.show', $member) }}">{{ $member->email }}</a><div class="tiny">{{ $member->name }}</div></td>
                    <td>{{ number_format($member->points_balance) }}</td>
                    <td>{{ number_format($member->spendablePoints()) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">No customers yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="panel">
        <h2>Recent ledger</h2>
        <table>
            <thead><tr><th>Customer</th><th>Event</th><th>Pts</th></tr></thead>
            <tbody>
            @forelse ($recentTx as $tx)
                <tr>
                    <td><a href="{{ route('admin.customers.show', $tx->customer) }}">{{ $tx->customer->email }}</a></td>
                    <td>{{ $tx->description }}</td>
                    <td>{{ $tx->points > 0 ? '+' : '' }}{{ $tx->points }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">Nothing yet. Place a paid Shopify order to earn.</td></tr>
            @endforelse
            </tbody>
        </table>
        <p class="tiny" style="margin-top:12px"><a href="{{ route('admin.settings.edit') }}">Connect your Shopify store →</a></p>
    </div>
</section>
@endsection
