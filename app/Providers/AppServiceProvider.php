<?php

namespace App\Providers;

use App\Contracts\PaymentGatewayInterface;
use App\Services\PayPalRedirectMockService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, PayPalRedirectMockService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::define('viewApiDocs', fn ($user = null) => app()->environment('local', 'testing'));
    }
}
