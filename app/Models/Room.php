<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean', 'capacity' => 'integer'];

    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function devices(): HasMany { return $this->hasMany(Device::class, 'room_id', 'id'); }
}
