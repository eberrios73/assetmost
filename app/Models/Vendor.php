<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    // River schema: PK vendorID; company link is the vendor_client pivot.
    protected $primaryKey = 'vendorID';
    protected $guarded = ['vendorID'];
    protected $appends = ['id'];
    public function getIdAttribute() { return $this->getKey(); }   // expose River PK as `id`
    protected $casts = ['active' => 'boolean'];

    public function companies(): BelongsToMany { return $this->belongsToMany(Company::class, 'vendor_client', 'vendorID', 'client_id'); }
    public function logins(): HasMany { return $this->hasMany(Login::class, 'vendorID', 'vendorID'); }
    public function products(): HasMany { return $this->hasMany(Product::class, 'vendor_id', 'vendorID'); }
    public function licenses(): HasMany { return $this->hasMany(License::class, 'vendor_id', 'vendorID'); }
}
