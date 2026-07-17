<?php

return [

    /*
     | AssetMost is multi-tenant and self-hosted, always.
     |
     | Self-hosted is a security decision, not a packaging one: this app stores vendor
     | credentials. Running it as a hosted service would put every customer's passwords in
     | one blast radius under someone else's control — one breach exposes all of them.
     | Each install holds only its own secrets.
     |
     | Multi-tenant from the start because the alternative (a single-company mode) forks
     | every scoping decision in the codebase forever, to serve a case that a multi-tenant
     | install already covers with one company.
     */

    /*
     | Companies this install is licensed for. Self-hosted, so this is a licence tier and
     | an honest guardrail — not DRM. It exists so an install can tell you it's outgrown
     | its tier, not to stop anyone.
     */
    'max_tenants' => (int) env('ASSETMOST_MAX_TENANTS', 20),

    // Annual price per company beyond the tier.
    'extra_tenant_price' => (int) env('ASSETMOST_EXTRA_TENANT_PRICE', 30),

];
