<?php

namespace App\Models;

use App\Support\Access;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'can_login' => 'boolean',
            'restricted' => 'boolean',
            'force_password_change' => 'boolean',
            'samba_synced' => 'boolean',
            'samba_last_sync' => 'datetime',
            'onboarding_data' => 'array',
            'onboarding_complete' => 'boolean',
            'offboarded_at' => 'datetime',
            'offboarding_data' => 'array',
        ];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function devices(): BelongsToMany { return $this->belongsToMany(Device::class); }

    /** Credentials this person can use. Many-to-many: a shared mailbox has many holders. */
    public function logins(): BelongsToMany
    {
        return $this->belongsToMany(Login::class, 'login_access')->withTimestamps();
    }

    /**
     * Licenses this person effectively holds — derived through the accounts they hold,
     * because seats are consumed by accounts, not people. Returns a query, not a
     * relation: there's no single pivot to hang one off.
     */
    public function licenses()
    {
        return License::query()->whereHas(
            'logins',
            fn ($q) => $q->whereHas('holders', fn ($h) => $h->whereKey($this->getKey()))
        );
    }

    public function managedCompanies(): BelongsToMany { return $this->belongsToMany(Company::class, 'admin_company'); }

    public function isSuperAdmin(): bool { return $this->role === Access::SUPER_ADMIN; }
    public function isAdmin(): bool { return in_array($this->role, [Access::SUPER_ADMIN, Access::IT_ADMIN], true); }

    /** Does this person's role carry this permission? See App\Support\Access. */
    public function may(string $permission): bool { return Access::allows($this->role, $permission); }

    /** Company ids this user may access (all for SuperAdmin/IT Admin, own otherwise). */
    public function managedCompanyIds(): array
    {
        if (in_array($this->role, ['SuperAdmin', 'IT Admin'], true)) {
            return Company::query()->withoutGlobalScopes()->pluck('id')->all();
        }
        return array_filter([$this->company_id]);
    }
}
