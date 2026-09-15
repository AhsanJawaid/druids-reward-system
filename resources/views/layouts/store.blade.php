<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Atelier Rewards')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=2">
</head>
<body>
    <div class="wrap">
        <header class="site-header">
            <a class="brand" href="{{ route('storefront.home') }}">
                <strong>Atelier Rewards</strong>
                <span>Earn points. Get a discount code.</span>
            </a>
            <nav class="nav">
                <a class="active" href="{{ route('storefront.home') }}">Shop</a>
                <a href="{{ route('admin.dashboard') }}">Admin</a>
            </nav>
        </header>
        @if (session('status'))
            <div class="flash">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="errors">{{ $errors->first() }}</div>
        @endif
        @yield('content')
        <footer>Try with <strong>maya@atelier.example</strong>. Buying here mimics a paid Shopify order.</footer>
    </div>
</body>
</html>
