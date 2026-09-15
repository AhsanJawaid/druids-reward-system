@extends('layouts.admin')

@section('title', $customer->email)

@section('content')
<p class="kicker">Customer</p>
<h1>{{ $customer->name ?: $customer->email }}</h1>
<p class="muted">{{ $customer->email }} · {{ number_format($customer->points_balance) }} points</p>

<section class="split">
    <div class="panel">
        <h2>History</h2>
        <table>
            <thead><tr><th>When</th><th>Type</th><th>Detail</th><th>Points</th><th>Balance</th></tr></thead>
            <tbody>
            @foreach ($customer->transactions as $tx)
                <tr>
                    <td class="tiny">{{ $tx->created_at->format('M j, g:ia') }}</td>
                    <td><span class="pill">{{ $tx->type }}</span></td>
                    <td>{{ $tx->description }}</td>
                    <td>{{ $tx->points }}</td>
                    <td>{{ $tx->balance_after }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <h2 style="margin-top:22px">Discount codes (use at Shopify checkout)</h2>
        @forelse ($customer->redemptions as $redemption)
            <p style="margin:10px 0"><span class="code-chip">{{ $redemption->discount_code }}</span> · {{ $redemption->reward->name }} · until {{ $redemption->expires_at?->toFormattedDateString() }}</p>        
        @empty
            <p class="muted">No codes yet.</p>
        @endforelse
    </div>
    <div class="panel">
        <h2>Add or remove points</h2>
        <form method="post" action="{{ route('admin.customers.adjust', $customer) }}">
            @csrf
            <label>Points (use a negative number to take points away)</label>
            <input name="points" type="number" required value="25">
            <label>Reason</label>
            <input name="reason" required placeholder="Bonus for a delay">
            <button type="submit">Update points</button>
        </form>
    </div>
</section>
@endsection
