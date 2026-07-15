<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public function rooms(): HasMany { return $this->hasMany(Room::class); }
    public function users(): HasMany { return $this->hasMany(User::class); }
    public function devices(): HasMany { return $this->hasMany(Device::class); }
}
