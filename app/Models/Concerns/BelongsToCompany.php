<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\Contracts\TenantResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenancy: every model using this trait is auto-scoped to the current company
 * context (server-resolved, never client-supplied). SuperAdmin bypasses the scope.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            $ids = app(TenantResolver::class)->scopeIds();
            if ($ids !== null) {
                $query->whereIn($query->getModel()->getTable() . '.company_id', $ids);
            }
        });

        static::creating(function ($model) {
            if (empty($model->company_id)) {
                $model->company_id = app(TenantResolver::class)->id();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
