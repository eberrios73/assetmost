<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocPage extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    public function parent(): BelongsTo { return $this->belongsTo(DocPage::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(DocPage::class, 'parent_id')->orderBy('position')->orderBy('title'); }
    public function editor(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
}
