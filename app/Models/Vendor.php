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

    // River schema: PK vendorID. vendors.company_id is the authority now;
    // the legacy vendor_client pivot stays for ITer but the app doesn't read it.
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
