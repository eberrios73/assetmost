<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stored credential. Secret is encrypted at rest (cast), not plaintext.
 */
class Login extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
    protected $casts = [
        'login_pass' => 'encrypted',
        'is_active' => 'boolean',
        'is_restricted' => 'boolean',
    ];

    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function subscriptions(): HasMany { return $this->hasMany(Subscription::class); }
}
