@extends('layouts.admin')

@section('title', 'Rewards')

@section('content')
<p class="kicker">Catalog</p>
<h1>Rewards</h1>
<p class="muted">Each reward becomes a one-time Shopify discount code when a customer redeems it.</p>

<section class="split">
    <div class="panel">
        <h2>Current rewards</h2>
        @foreach ($rewards as $reward)
            <form method="post" action="{{ route('admin.rewards.update', $reward) }}" style="margin-bottom:18px;border-bottom:1px solid var(--line);padding-bottom:12px">
                @csrf
                @method('PUT')
                <label>Name</label>
                <input name="name" value="{{ $reward->name }}" required>
                <label>Description</label>
                <input name="description" value="{{ $reward->description }}">
                <div class="split" style="grid-template-columns:1fr 1fr 1fr">
                    <div>
                        <label>Points needed</label>
                        <input name="points_cost" type="number" min="1" value="{{ $reward->points_cost }}">
                    </div>
                    <div>
                        <label>Discount type</label>
                        <select name="discount_type">
                            <option value="fixed_amount" @selected($reward->discount_type === 'fixed_amount')>Dollars off</option>
                            <option value="percentage" @selected($reward->discount_type === 'percentage')>Percent off</option>
                        </select>
                    </div>
                    <div>
                        <label>Amount</label>
                        <input name="discount_value" type="number" step="0.01" value="{{ $reward->discount_value }}">
                    </div>
                </div>
                <label><input type="checkbox" name="active" value="1" @checked($reward->active) style="width:auto"> Show this reward</label>
                <button type="submit">Save</button>
            </form>
        @endforeach
    </div>
    <div class="panel">
        <h2>Add a reward</h2>
        <form method="post" action="{{ route('admin.rewards.store') }}">
            @csrf
            <label>Name</label>
            <input name="name" required placeholder="$10 off">
            <label>Description</label>
            <textarea name="description" rows="3" placeholder="Optional"></textarea>
            <label>Points needed</label>
            <input name="points_cost" type="number" min="1" value="150" required>
            <label>Discount type</label>
            <select name="discount_type">
                <option value="fixed_amount">Dollars off</option>
                <option value="percentage">Percent off</option>
            </select>
            <label>Amount</label>
            <input name="discount_value" type="number" step="0.01" value="10" required>
            <button type="submit">Add reward</button>
        </form>
    </div>
</section>
@endsection
