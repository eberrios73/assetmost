<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityProvider extends Model
{
    use BelongsToCompany;

    public const PROVIDERS = [
        'google' => 'Google Workspace',
        'okta' => 'Okta',
        'microsoft' => 'Microsoft Entra ID',
        'zoom' => 'Zoom (account provisioning)',
    ];

    protected $guarded = ['id'];

    /** Never send the secret to the client; the form shows whether one is set, not what. */
    protected $hidden = ['client_secret'];

    protected $appends = ['has_secret'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sync_on_login' => 'boolean',
            'client_secret' => 'encrypted',
            'last_sync_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    public function getHasSecretAttribute(): bool
    {
        return filled($this->attributes['client_secret'] ?? null);
    }

    public function getLabelAttribute(): string
    {
        return self::PROVIDERS[$this->provider] ?? $this->provider;
    }
}
