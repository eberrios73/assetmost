<?php

namespace App\Support;

use App\Models\Company;

/**
 * Licence tier. AssetMost is self-hosted, so this is a guardrail that tells an install it
 * has outgrown its tier — not enforcement. Anyone can edit the config; the point is to be
 * honest about what was bought, not to police it.
 */
class Plan
{
    public static function maxTenants(): int
    {
        return (int) config('assetmost.max_tenants', 20);
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
