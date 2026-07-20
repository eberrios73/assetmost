<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use App\Support\Contracts\TenantResolver;

/**
 * Multi-tenant resolver: the active company is chosen via the header switcher and
 * stored in session — validated here against what the user may see. The old
 * client-supplied company_id is gone; this is the enforced boundary.
 *
 * The default TenantResolver. A deployment can bind a richer one (subdomain, SSO) without
 * touching a single global scope.
 */
class CurrentCompany implements TenantResolver
{
    protected ?array $allowed = null;

    public function user(): ?User
    {
        return auth()->user();
    }

    /** Company ids this user may access. SuperAdmin/IT Admin = all; others = own. */
    public function allowedIds(): array
    {
        if ($this->allowed !== null) {
            return $this->allowed;
        }
        $u = $this->user();
        if (! $u) {
            return [];   // don't memoize: auth may resolve later in this lifecycle
        }
        return $this->allowed = $u->managedCompanyIds();
    }

    /** The focused company from the switcher (session), if allowed. Null = "all". */
    public function activeId(): ?int
    {
        $sel = session('active_company_id');
        if ($sel && in_array((int) $sel, $this->allowedIds(), true)) {
            return (int) $sel;
        }
        return null;
    }

    /**
     * Ids to filter queries by. Null = no filter (SuperAdmin viewing "all").
     * - a company is focused  -> just that one
     * - non-super, no focus   -> everything they may see
     * - super, no focus       -> null (all)
     */
    public function scopeIds(): ?array
    {
        if ($active = $this->activeId()) {
            return [$active];
        }
        $u = $this->user();
        if ($u && $u->isSuperAdmin()) {
            return null;
        }
        return $this->allowedIds();
    }

    /** Default company_id to stamp on newly created records. */
    public function id(): ?int
    {
        return $this->activeId() ?? ($this->allowedIds()[0] ?? null);
    }

    public function setActive(?int $companyId): bool
    {
        if ($companyId === null) {
            session()->forget('active_company_id');
            return true;
        }
        if (in_array($companyId, $this->allowedIds(), true)) {
            session(['active_company_id' => $companyId]);
            return true;
        }
        return false;
    }

    /** Companies the switcher should offer. */
    public function options()
    {
        return Company::query()->withoutGlobalScopes()
            ->whereIn('id', $this->allowedIds())
            ->orderBy('name')->get(['id', 'name']);
    }
}
