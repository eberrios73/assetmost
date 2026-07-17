<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Multi-tenant, always. The app depends on the TenantResolver contract rather
        // than this class directly, so a deployment can bind a richer resolver
        // (subdomain, SSO-driven) without touching scoping anywhere else.
        $this->app->scoped(\App\Support\Contracts\TenantResolver::class, \App\Support\CurrentCompany::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
