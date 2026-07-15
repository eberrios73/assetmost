<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Device extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
    protected $casts = [
        'active' => 'boolean', 'restricted' => 'boolean', 'ewaste' => 'boolean',
        'inv_date' => 'date',
    ];

    // Placement
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function room(): BelongsTo { return $this->belongsTo(Room::class); }

    // Assignment (users) — separate from placement
    public function users(): BelongsToMany { return $this->belongsToMany(User::class); }
}
