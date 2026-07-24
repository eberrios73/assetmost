<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One enrolled passkey. See the webauthn_credentials migration. */
class WebauthnCredential extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['last_used_at' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
