<?php

return [

    /*
     | AssetMost is self-hosted and multi-tenant, with unlimited companies.
     |
     | Self-hosted is a security decision, not packaging: this app stores vendor
     | credentials. Running it as a service would put every customer's passwords in one
     | blast radius under someone else's control — one breach exposes all of them. Each
     | install holds only its own secrets.
     |
     | There is no tenant cap and no per-tenant billing, so there's nothing to configure
     | here: a flat licence buys the software, and how many companies you manage with it is
     | your business. Metering companies would only have punished the customers doing best
     | with it, in software they run on their own hardware where any limit is honour-system
     | anyway.
     */

];
