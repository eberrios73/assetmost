<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
    protected $casts = [
        'amount' => 'decimal:2',
        'renewal_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function login(): BelongsTo { return $this->belongsTo(Login::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
