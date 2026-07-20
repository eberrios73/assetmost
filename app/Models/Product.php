<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What a vendor sells: Adobe ▸ "Creative Cloud All Apps". */
class Product extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function licenses(): HasMany { return $this->hasMany(License::class); }
    public function logins(): HasMany { return $this->hasMany(Login::class); }
}
