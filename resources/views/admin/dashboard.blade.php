@extends('layouts.admin')

@section('title', 'Rewards overview')

@section('content')
<p class="kicker">Admin</p>
<h1>Overview</h1>
<p class="muted">{{ $settings['program_name'] }} · {{ $settings['points_per_dollar'] }} points per $1 · {{ $mocked ? 'Demo mode' : 'Live Shopify' }}</p>

<div class="stats">
    <div class="panel stat"><span class="tiny">Customers</span><b>{{ $customers }}</b></div>
    <div class="panel stat"><span class="tiny">Points given</span><b>{{ number_format($pointsIssued) }}</b></div>
    <div class="panel stat"><span class="tiny">Points spent</span><b>{{ number_format($pointsRedeemed) }}</b></div>
    <div class="panel stat"><span class="tiny">Codes created</span><b>{{ $redemptions }}</b></div>
</div>

<section class="split">
    <div class="panel">
        <h2>Recent activity</h2>
        <table>
            <thead><tr><th>Customer</th><th>What happened</th><th>Points</th><th>Balance</th></tr></thead>
            <tbody>
            @forelse ($recentTx as $tx)
                <tr>
                    <td><a href="{{ route('admin.customers.show', $tx->customer) }}">{{ $tx->customer->email }}</a></td>
                    <td>{{ $tx->description }}</td>
                    <td>{{ $tx->points > 0 ? '+' : '' }}{{ $tx->points }}</td>
                    <td>{{ $tx->balance_after }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Nothing yet.</td></tr>
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
            <input name="email" type="email" required value="maya@atelier.example">
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
