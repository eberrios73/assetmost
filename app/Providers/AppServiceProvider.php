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
        // Tenancy brain, chosen by edition:
        //   'single' (open core, $99): one Owner, one company. SingleTenantResolver.
        //   'multi'  ($199, hosted):   one Owner, many companies + switcher. CurrentCompany.
        // The whole app depends only on the TenantResolver contract, so this one
        // value swaps the entire tenancy behaviour with no other changes.
        $this->app->scoped(\App\Support\Contracts\TenantResolver::class, function () {
            return config('assetmost.edition') === 'single'
                ? new \App\Support\SingleTenantResolver()
                : new \App\Support\CurrentCompany();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
