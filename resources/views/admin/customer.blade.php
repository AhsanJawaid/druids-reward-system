@extends('layouts.admin')

@section('title', $customer->email)

@section('content')
<p class="kicker">Customer</p>
<h1>{{ $customer->name ?: $customer->email }}</h1>
<p class="muted">{{ $customer->email }} · ledger {{ number_format($customer->points_balance) }} · spendable {{ number_format($customer->spendablePoints()) }} · held {{ number_format($customer->pendingPoints()) }}</p>
@if ($customer->birthday)
    <p class="tiny">Birthday on file: {{ $customer->birthday->format('j F') }} · last birthday reward year: {{ $customer->last_birthday_reward_year ?: '—' }}</p>
@endif

<section class="split">
    <div class="panel">
        <h2>Ledger (auditable)</h2>
        <table>
            <thead><tr><th>When</th><th>Type</th><th>Detail</th><th>Points</th><th>Balance</th><th>Available</th></tr></thead>
            <tbody>
            @foreach ($customer->transactions as $tx)
                <tr>
                    <td class="tiny">{{ $tx->created_at->format('M j, g:ia') }}</td>
                    <td><span class="pill">{{ $tx->type }}</span></td>
                    <td>{{ $tx->description }}</td>
                    <td>{{ $tx->points }}</td>
                    <td>{{ $tx->balance_after }}</td>
                    <td class="tiny">{{ $tx->available_at ? $tx->available_at->format('M j, Y') : 'Immediate' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <h2 style="margin-top:22px">Shopify objects issued</h2>
        @forelse ($customer->redemptions as $redemption)
            <p style="margin:10px 0"><span class="code-chip">{{ $redemption->discount_code }}</span> · {{ $redemption->reward->name }} · {{ $redemption->shopify_object_type }}</p>
        @empty
            <p class="muted">No codes yet.</p>
        @endforelse
    </div>
    <div class="panel">
        <h2>Birthday</h2>
        <p class="tiny">Shopify does not send metafields in webhooks. Save the date here (or the customer saves it on the Portal). If today matches, 250 points post once this year.</p>
        <form method="post" action="{{ route('admin.customers.birthday', $customer) }}">
            @csrf
            <label>Birthday</label>
            <input name="birthday" type="date" required value="{{ $customer->birthday?->format('Y-m-d') }}">
            <button type="submit">Save birthday</button>
        </form>
        <p class="tiny" style="margin-top:8px">Last birthday reward year: {{ $customer->last_birthday_reward_year ?: '—' }}</p>
        <h2 style="margin-top:22px">Correction</h2>
        <p class="tiny">Use only to fix a ledger error. Earning still comes from Shopify webhooks.</p>
        <form method="post" action="{{ route('admin.customers.adjust', $customer) }}">
            @csrf
            <label>Points (negative to remove)</label>
            <input name="points" type="number" required value="0">
            <label>Reason</label>
            <input name="reason" required placeholder="Ledger correction">
            <button type="submit">Post correction</button>
        </form>
    </div>
</section>
@endsection
