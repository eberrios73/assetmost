<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
    protected $casts = [
        'active' => 'boolean', 'restricted' => 'boolean', 'ewaste' => 'boolean',
        'inv_date' => 'date',
    ];

    /**
     * Issue an asset tag on intake when one wasn't supplied.
     *
     * Legacy tags are never touched — a device that arrives with a tag keeps it, which is
     * how an old scheme and a new one coexist without a flag day.
     */
    protected static function booted(): void
    {
        static::creating(function (Device $device) {
            if (! $device->asset_tag && $device->company_id && $device->device_type_id) {
                $company = $device->relationLoaded('company') ? $device->company : Company::find($device->company_id);
                $type = $device->relationLoaded('deviceType') ? $device->deviceType : DeviceType::find($device->device_type_id);
                if ($company?->tag_prefix && $type?->code) {
                    $device->asset_tag = $company->nextAssetTag($type->code);
                    // The tag doubles as the hostname for anything on the network; gear
                    // with nothing to name (monitors, drives) just leaves it null.
                    $device->computer_name ??= $device->asset_tag;
                }
            }
        });
    }

    public function deviceType(): BelongsTo { return $this->belongsTo(DeviceType::class); }

    // Placement
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function room(): BelongsTo { return $this->belongsTo(Room::class); }

    // Assignment (users) — separate from placement
    public function users(): BelongsToMany { return $this->belongsToMany(User::class); }

    /** Infrastructure credentials for this asset (ITAdmin @ Mail_Arch_Srv). */
    public function logins(): HasMany { return $this->hasMany(Login::class); }

    public function scopeInService($q) { return $q->where('active', true); }
}
