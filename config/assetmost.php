<?php

return [

    /*
     | Edition — which tenancy brain to load.
     |   'single' : open core. One company, no switching. (SingleTenantResolver)
     |   'multi'  : the private multi-tenant module. (CurrentCompany, or a richer resolver)
     |
     | The whole app depends only on the TenantResolver contract, so this one value
     | swaps the entire tenancy behaviour with no other code changes.
     */
    'edition' => env('ASSETMOST_EDITION', 'multi'),

    /*
     | Tenant cap for the hosted multi-tenant plan. Most small MSPs run well
     | under this. Beyond it is the Enterprise tier (additional tenants billed
     | per year) — raise ASSETMOST_MAX_TENANTS for an Enterprise customer.
     | The single edition is always one company regardless of this value.
     */
    'max_tenants' => (int) env('ASSETMOST_MAX_TENANTS', 20),

    // Per-tenant annual price for tenants beyond the plan cap (Enterprise).
    'extra_tenant_price' => (int) env('ASSETMOST_EXTRA_TENANT_PRICE', 30),

];
