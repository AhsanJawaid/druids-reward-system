@extends('layouts.admin')

@section('title', 'Customers')

@section('content')
<p class="kicker">Members</p>
<h1>Customers</h1>
<p class="muted">Open a customer to see history or add bonus points.</p>
<div class="panel">
    <table>
        <thead><tr><th>Name</th><th>Email</th><th>Shopify ID</th><th>Points</th></tr></thead>
        <tbody>
        @forelse ($customers as $customer)
            <tr>
                <td>{{ $customer->name }}</td>
                <td><a href="{{ route('admin.customers.show', $customer) }}">{{ $customer->email }}</a></td>
                <td class="tiny">{{ $customer->shopify_customer_id ?: '—' }}</td>
                <td>{{ number_format($customer->points_balance) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No customers yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
