<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Company extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public function locations(): HasMany { return $this->hasMany(Location::class); }
    public function users(): HasMany { return $this->hasMany(User::class); }
    public function devices(): HasMany { return $this->hasMany(Device::class); }
    public function logins(): HasMany { return $this->hasMany(Login::class); }
    public function subscriptions(): HasMany { return $this->hasMany(Subscription::class); }
    public function vendors(): BelongsToMany { return $this->belongsToMany(Vendor::class); }
}
