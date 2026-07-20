<?php

namespace App\Models;

use App\Support\Contracts\TenantResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A FLOATING account: one credential identity (artist001, info@, ITAdmin) that can
 * be assigned to any employee and used across many services. The service logins
 * point at it; assignment lives here. A person's own email never gets one.
 *
 * Floating only, so sharing here has four modes — `personal` belongs to logins.
 */
class Account extends Model
{
    public const SHARING = ['pooled', 'shared', 'service', 'breakglass'];

    protected $guarded = ['id'];
    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        // Shared-aware tenancy (same pattern as Location): company_id NULL means the
        // account isn't company-scoped (infra credentials like ITAdmin).
        static::addGlobalScope('company', function (Builder $q) {
            $ids = app(TenantResolver::class)->scopeIds();
            if ($ids !== null) {
                $q->where(fn ($w) => $w->whereIn('accounts.company_id', $ids)
                                       ->orWhereNull('accounts.company_id'));
            }
        });
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    /** Who currently holds this credential. */
    public function holders(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_user')->withTimestamps();
    }

    /** The services this credential is used in (Adobe, Zoom, a server…). */
    public function logins(): HasMany
    {
        return $this->hasMany(Login::class, 'account_id');
    }
}
