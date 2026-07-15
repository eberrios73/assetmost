<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Owner;
use App\Models\User;
use App\Support\Contracts\TenantResolver;

/**
 * Single-tenant edition (open core, $99): one Owner, exactly ONE company.
 * No switcher, no cross-company. The company is Owner.settings['company_id']
 * (or the only company). Many-company / switching is the multi-tenant module.
 */
class SingleTenantResolver implements TenantResolver
{
    protected ?int $companyId = null;

    public function user(): ?User
    {
        return auth()->user();
    }

    protected function companyId(): ?int
    {
        if ($this->companyId !== null) {
            return $this->companyId;
        }
        $fromSettings = Owner::current()->setting('company_id');
        return $this->companyId = (int) ($fromSettings ?: Company::query()->withoutGlobalScopes()->min('id')) ?: null;
    }

    public function allowedIds(): array
    {
        return array_filter([$this->companyId()]);
    }

    public function scopeIds(): ?array
    {
        return $this->allowedIds();
    }

    public function activeId(): ?int
    {
        return $this->companyId();
    }

    public function id(): ?int
    {
        return $this->companyId();
    }

    public function setActive(?int $companyId): bool
    {
        return false; // single-tenant: nothing to switch
    }

    public function options()
    {
        return Company::query()->withoutGlobalScopes()
            ->whereKey($this->companyId())->get(['id', 'name']);
    }
}
