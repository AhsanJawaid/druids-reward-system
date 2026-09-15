@extends('layouts.admin')

@section('title', 'Rewards')

@section('content')
<p class="kicker">Rewards System</p>
<h1>Rewards</h1>
<p class="muted">Points-based loyalty for the Shopify store. Spend rate: <strong>100 points = £1</strong>. Every redemption creates a real Shopify discount code or gift card through the Admin API.</p>

<section class="panel" style="margin-bottom:16px">
    <h2>Section 1 — Earning points</h2>
    <p class="muted">Points are awarded from real Shopify webhooks only. There are no manual earn buttons.</p>
    <table>
        <thead>
            <tr>
                <th>Action</th>
                <th>Points awarded</th>
                <th>Conditions</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($earn as $row)
            <tr>
                <td><strong>{{ $row['action'] }}</strong></td>
                <td>{{ $row['points'] }}</td>
                <td>{{ $row['conditions'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</section>

<section class="panel">
    <h2>Section 2 — Spending points</h2>
    <p class="muted">The conversion rate for spending is 100 points to £1. Customers redeem accumulated points for these rewards.</p>
    <table>
        <thead>
            <tr>
                <th>Reward</th>
                <th>Points required</th>
                <th>Description</th>
                <th>Shopify object</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($rewards as $reward)
            <tr>
                <td><strong>{{ $reward->name }}</strong></td>
                <td>{{ number_format($reward->points_cost) }} points</td>
                <td>{{ $reward->description }}</td>
                <td class="tiny">
                    @if ($reward->slug === 'gift_card')
                        giftCardCreate (£25)
                    @elseif ($reward->slug === 'free_shipping')
                        discountCodeFreeShippingCreate
                    @elseif ($reward->slug === 'free_product')
                        discountCodeBasicCreate (100% off verified collection item)
                    @else
                        discountCodeBasicCreate (£5)
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</section>
@endsection
