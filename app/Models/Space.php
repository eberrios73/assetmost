<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Space extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    public function pages(): HasMany
    {
        return $this->hasMany(DocPage::class);
    }
}
