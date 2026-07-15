<?php

namespace App\Support;

use App\Models\Company;

/**
 * Plan limits. The tenant cap is the one commercial line that matters in code:
 * the hosted multi-tenant plan allows up to `max_tenants` companies; beyond
 * that is Enterprise (raise the cap for that customer). Single edition is 1.
 */
class Plan
{
    public static function maxTenants(): int
    {
        return config('assetmost.edition') === 'single'
            ? 1
            : (int) config('assetmost.max_tenants', 20);
    }

    public static function tenantCount(): int
    {
        return Company::query()->count();
    }

    public static function atTenantCap(): bool
    {
        return self::tenantCount() >= self::maxTenants();
    }

    public static function extraTenantPrice(): int
    {
        return (int) config('assetmost.extra_tenant_price', 30);
    }
}
