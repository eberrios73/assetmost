<?php

namespace App\Support\Provisioning;

use App\Models\IdentityProvider;
use App\Models\Vendor;

/**
 * The plugin contract for account provisioning.
 *
 * A provisioner turns "Create X account" from a manual task into an API call —
 * WHEN the company has configured and enabled that integration. Zoom ships
 * built-in; Microsoft Graph drops into the same socket the day it's approved;
 * self-hosted installs can register their own classes for anything else.
 *
 * Failure is always survivable: the wizard falls back to the manual task with
 * the error attached. Automation is an accelerant here, never a dependency.
 */
interface Provisioner
{
    /** The identity_providers.provider key this plugin reads its config from. */
    public function configKey(): string;

    /** Does this plugin handle accounts for this vendor? (matched by name) */
    public function supports(Vendor $vendor): bool;

    /**
     * Create the account. Returns a short human summary on success
     * ("created, Licensed seat assigned"). Throw on failure — the caller turns
     * that into a manual task note.
     */
    public function provision(IdentityProvider $config, array $person): string;
}
