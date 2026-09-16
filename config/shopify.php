<?php

return [
    'store_domain' => env('SHOPIFY_STORE_DOMAIN', 'demo-store.myshopify.com'),
    'access_token' => env('SHOPIFY_ACCESS_TOKEN', ''),
    'client_id' => env('SHOPIFY_CLIENT_ID', ''),
    'client_secret' => env('SHOPIFY_CLIENT_SECRET', ''),
    'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),
    'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET', ''),
    'mock' => env('SHOPIFY_MOCK', true),
];
