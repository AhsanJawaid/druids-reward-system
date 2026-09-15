<?php

use App\Http\Controllers\Api\CustomerRewardController;
use App\Http\Controllers\Api\ShopifyWebhookController;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/shopify', ShopifyWebhookController::class)
    ->middleware(VerifyShopifyWebhook::class)
    ->name('api.webhooks.shopify');

Route::get('/rewards/customer', [CustomerRewardController::class, 'show'])->name('api.rewards.customer');
Route::post('/rewards/redeem', [CustomerRewardController::class, 'redeem'])->name('api.rewards.redeem');
