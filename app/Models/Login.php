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

    // River schema: PK loginID, FKs userID/vendorID, plaintext login_pass (matches ITer).
    protected $primaryKey = 'loginID';
    protected $guarded = ['loginID'];
    protected $appends = ['id'];
    public function getIdAttribute() { return $this->getKey(); }   // expose River PK as `id`
    protected $casts = [
        'is_active' => 'boolean',
        'is_restricted' => 'boolean',
    ];

    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class, 'vendorID', 'vendorID'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'userID', 'id'); }
    public function subscriptions(): HasMany { return $this->hasMany(Subscription::class, 'login_id', 'loginID'); }
}
