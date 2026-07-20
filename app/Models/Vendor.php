<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who you buy from — Adobe, Microsoft, Autodesk.
 *
 * NOT what you buy: that's a Product. Filing a product name here ("Adobe Creative Suite"
 * next to "Adobe") splits one vendor's seats across rows that can never be summed.
 */
class Vendor extends Model
{
    use BelongsToCompany;   // one company per vendor — vendors are NOT shared

    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public function companies(): BelongsToMany { return $this->belongsToMany(Company::class); }
    public function logins(): HasMany { return $this->hasMany(Login::class); }
    public function products(): HasMany { return $this->hasMany(Product::class); }
    public function licenses(): HasMany { return $this->hasMany(License::class); }
}
