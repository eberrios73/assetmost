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

];
