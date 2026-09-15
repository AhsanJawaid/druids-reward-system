<?php

use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\RewardController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\WebhookSimulatorController;
use App\Http\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StorefrontController::class, 'home'])->name('storefront.home');
Route::post('/identify', [StorefrontController::class, 'identify'])->name('storefront.identify');
Route::post('/checkout', [StorefrontController::class, 'checkout'])->name('storefront.checkout');
Route::post('/redeem', [StorefrontController::class, 'redeem'])->name('storefront.redeem');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/rewards', [RewardController::class, 'index'])->name('rewards.index');
    Route::post('/rewards', [RewardController::class, 'store'])->name('rewards.store');
    Route::put('/rewards/{reward}', [RewardController::class, 'update'])->name('rewards.update');
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::post('/customers/{customer}/adjust', [CustomerController::class, 'adjust'])->name('customers.adjust');
    Route::get('/settings', [SettingController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');
    Route::post('/settings/shopify', [SettingController::class, 'connect'])->name('settings.connect');
    Route::post('/settings/shopify/test', [SettingController::class, 'test'])->name('settings.test');
    Route::post('/settings/shopify/webhooks', [SettingController::class, 'registerWebhooks'])->name('settings.webhooks');
    Route::post('/simulate-webhook', WebhookSimulatorController::class)->name('webhooks.simulate');
});
