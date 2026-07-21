<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

class Company extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean', 'is_landlord' => 'boolean', 'tag_next' => 'integer'];

    /** The platform operator's own company — every install has exactly one. */
    public static function landlord(): ?self
    {
        return static::query()->withoutGlobalScopes()->where('is_landlord', true)->first();
    }

    public function locations(): HasMany { return $this->hasMany(Location::class); }
    public function users(): HasMany { return $this->hasMany(User::class); }
    public function devices(): HasMany { return $this->hasMany(Device::class); }
    public function logins(): HasMany { return $this->hasMany(Login::class); }
    public function licenses(): HasMany { return $this->hasMany(License::class, 'company_id', 'id'); }
    public function vendors(): BelongsToMany { return $this->belongsToMany(Vendor::class); }

    /**
     * Issue the next asset tag: PG-WS-1001.
     *
     * One number line per company, shared across types — matching how the legacy numbers
     * actually ran (642=Laptop, 643=Laptop, 644=Workstation), so a reclassified box keeps
     * its number and only needs a new type code.
     *
     * Locks the counter row so two concurrent intakes can't take the same number, and only
     * ever increments. Not MAX(n)+1: retire the highest device and MAX+1 re-issues its
     * number onto a sticker that still exists.
     */
    public function nextAssetTag(string $typeCode): string
    {
        if (! $this->tag_prefix) {
            throw new \RuntimeException("Company {$this->id} has no tag_prefix set.");
        }

        $n = DB::transaction(function () {
            $row = static::query()->whereKey($this->getKey())->lockForUpdate()->first();
            $next = (int) $row->tag_next;
            $row->forceFill(['tag_next' => $next + 1])->save();
            return $next;
        });

        $this->refresh();

        return sprintf('%s-%s-%04d', strtoupper($this->tag_prefix), strtoupper($typeCode), $n);
    }
}
