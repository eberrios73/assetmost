<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A device type and its 2-letter code (Workstation = WS).
 *
 * The code is a label that feeds the asset tag. Nothing parses it back out — filtering
 * and reporting use device_type_id.
 */
class DeviceType extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('position')->orderBy('name');
    }
}
