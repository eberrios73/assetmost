<?php

namespace App\Models;

use App\Support\Contracts\TenantResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical site.
 *
 * Deliberately NOT BelongsToCompany: a building isn't owned by one company when several
 * operate out of it. Two client companies can both have gear in Los Angeles
 * (907 and 21 devices) and both in Burbank (1 and 99) — one place, two companies.
 * Scoping locations per company forced a duplicate row per company per city, which is
 * exactly how "Los Angeles" ended up existing four times.
 *
 *   company_id = NULL  -> shared site, visible to every company in the install
 *   company_id = X     -> private to that company (an MSP client's own office)
 *
 * Rooms hang off the location and inherit its visibility.
 */
class Location extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $q) {
            $ids = app(TenantResolver::class)->scopeIds();
            if ($ids !== null) {
                $q->where(fn ($w) => $w->whereIn('locations.company_id', $ids)
                                       ->orWhereNull('locations.company_id'));
            }
        });
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function rooms(): HasMany { return $this->hasMany(Room::class); }
    public function users(): HasMany { return $this->hasMany(User::class); }
    public function devices(): HasMany { return $this->hasMany(Device::class); }

    public function isShared(): bool { return $this->company_id === null; }
}
