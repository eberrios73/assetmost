<?php

namespace App\Support\Provisioning;

use App\Models\IdentityProvider;
use App\Models\Vendor;

/**
 * The plugin registry. Built-ins live in $provisioners; a self-hosted install
 * adds its own by appending a class implementing Provisioner. A plugin only
 * fires when the company has configured AND enabled its integration.
 */
class ProvisionerRegistry
{
    /** @var class-string<Provisioner>[] */
    private static array $provisioners = [
        ZoomProvisioner::class,
        // MicrosoftGraphProvisioner::class — same socket, the day it's approved.
    ];

    /** The enabled, configured plugin for this vendor in this company — or null. */
    public static function for(Vendor $vendor, int $companyId): ?array
    {
        // Declarative JSON plugins first — readable, shareable, single-request.
        $candidates = [];
        foreach (\App\Models\ProvisionerDefinition::query()->where('enabled', true)->get() as $def) {
            $decoded = json_decode($def->definition, true);
            if (is_array($decoded)) {
                $decoded['plugin_key'] = $def->plugin_key;
                $candidates[] = new JsonProvisioner($decoded);
            }
        }
        foreach (self::$provisioners as $class) {
            $candidates[] = new $class();
        }

        foreach ($candidates as $p) {
            if (! $p->supports($vendor)) {
                continue;
            }
            $config = IdentityProvider::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('provider', $p->configKey())
                ->where('enabled', true)
                ->first();
            if ($config && $config->client_id && $config->client_secret) {
                return [$p, $config];
            }
        }
        return null;
    }
}
