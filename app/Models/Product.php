<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What a vendor sells: Adobe ▸ "Creative Cloud All Apps". River FK -> vendors.vendorID. */
class Product extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class, 'vendor_id', 'vendorID'); }
    public function licenses(): HasMany { return $this->hasMany(License::class, 'product_id', 'id'); }
    public function logins(): HasMany { return $this->hasMany(Login::class, 'product_id', 'id'); }
}
