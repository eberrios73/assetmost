<?php

namespace App\Support\Contracts;

use App\Models\User;

/**
 * The tenancy "brain". CurrentCompany (session switcher) is the default; a deployment can
 * bind a richer one — subdomain, SSO-driven, provisioning — from one service-provider line.
 *
 * The whole app (global scopes, controllers, Inertia props) depends only on this contract,
 * never on a concrete resolver. That indirection is why "which company am I looking at?"
 * is answered in exactly one place.
 */
interface TenantResolver
{
    public function user(): ?User;

    /** Company ids the current user may access. */
    public function allowedIds(): array;

    /** Ids to filter queries by, or null for no filter (see-all). */
    public function scopeIds(): ?array;

    /** The single focused company id, if any. */
    public function activeId(): ?int;

    /** Default company id to stamp on newly created records. */
    public function id(): ?int;

    /** Focus a company (validated); false if not permitted. */
    public function setActive(?int $companyId): bool;

    /** Companies to offer in a switcher (empty in single-tenant). */
    public function options();
}
