<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Rewards Portal')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=3">
</head>
<body>
    <div class="wrap">
        <header class="site-header">
            <a class="brand" href="{{ route('storefront.home') }}">
                <strong>Rewards Portal</strong>
                <span>Earn points. Use your discount code at checkout.</span>
            </a>
            <nav class="nav">
                <a class="active" href="{{ route('storefront.home') }}">Portal</a>
                <a href="{{ route('admin.dashboard') }}">Reports</a>
            </nav>
        </header>
        @if (session('discount_code'))
            <div class="code-banner">
                <p class="tiny" style="margin:0 0 6px">Your redeem code (paste this in Shopify checkout)</p>
                <p class="code-xl">{{ session('discount_code') }}</p>
                <p class="tiny" style="margin:8px 0 0">{{ session('discount_reward') }} · Copy the code exactly. One use only.</p>
            </div>
        @elseif (session('status'))
            <div class="flash">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="errors">{{ $errors->first() }}</div>
        @endif
        @yield('content')
        <footer>Points are awarded only from Shopify webhooks. Redeem on this portal to create a real checkout code or gift card.</footer>
    </div>
</body>
</html>
