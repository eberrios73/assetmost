<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a vendor sells: Adobe ▸ "Creative Cloud All Apps".
 *
 * Catalog-level and NOT company-scoped — two companies buying the same product is
 * normal. The per-company purchase is a License.
 *
 * Without this layer, product names end up filed as vendors ("Adobe Creative Suite"
 * sitting next to "Adobe"), and a vendor's seats fragment across rows that can't be summed.
 */
class Product extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Per-company purchases of this product. */
    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    /** Accounts tied directly to this product. */
    public function logins(): HasMany
    {
        return $this->hasMany(Login::class);
    }
}
