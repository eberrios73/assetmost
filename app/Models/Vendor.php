<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public function companies(): BelongsToMany { return $this->belongsToMany(Company::class); }
    public function logins(): HasMany { return $this->hasMany(Login::class); }
    public function subscriptions(): HasMany { return $this->hasMany(Subscription::class); }
}
