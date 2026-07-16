<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Device extends Model
{
    use BelongsToCompany;

    // River schema: PK deviceID, timestamp column is creation_timestamp.
    protected $primaryKey = 'deviceID';
    const CREATED_AT = 'creation_timestamp';
    protected $guarded = ['deviceID'];
    protected $appends = ['id'];
    public function getIdAttribute() { return $this->getKey(); }   // expose River PK as `id` for the API/frontend
    protected $casts = [
        'active' => 'boolean', 'restricted' => 'boolean', 'ewaste' => 'boolean',
        'inv_date' => 'date',
    ];

    // Placement (location_id/room_id are AssetMost additions to River)
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function room(): BelongsTo { return $this->belongsTo(Room::class); }

    // Assignment (users) via River's device_users pivot
    public function users(): BelongsToMany { return $this->belongsToMany(User::class, 'device_users', 'deviceID', 'user_id'); }
}
