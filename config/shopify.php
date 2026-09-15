<?php

return [
    'store_domain' => env('SHOPIFY_STORE_DOMAIN', 'demo-store.myshopify.com'),
    'access_token' => env('SHOPIFY_ACCESS_TOKEN', ''),
    'api_version' => env('SHOPIFY_API_VERSION', '2025-01'),
    'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET', ''),
    'mock' => env('SHOPIFY_MOCK', true),
];
