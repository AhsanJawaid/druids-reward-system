<?php

namespace App\Providers;

use App\Services\Shopify\ShopifyConfig;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if (! $this->app->environment(['local', 'testing'])) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
        
        ShopifyConfig::applyToConfig();
    }
}
