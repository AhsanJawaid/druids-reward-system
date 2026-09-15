<?php

namespace App\Providers;

use App\Services\Shopify\ShopifyConfig;
use App\Support\EnsureAssessmentSchema;
use Illuminate\Support\Facades\Log;
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

        if (! $this->app->environment('testing') && ! $this->app->runningInConsole()) {
            try {
                EnsureAssessmentSchema::apply();
            } catch (\Throwable $e) {
                Log::error('Assessment schema update failed: '.$e->getMessage());
            }
        }
    }
}
