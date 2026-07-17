<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stored credential. River schema: PK loginID, FKs userID/vendorID, plaintext login_pass
 * (matches ITer, which reads this same table).
 *
 * Held by MANY people via login_access — one-person-per-login can't express a pooled seat
 * handed between designers, a shared mailbox ten people check, or an admin account used
 * across six servers (37% of these rows had no owner because of it).
 *
 * `userID` is KEPT: ITer reads it. login_access is the app's source of truth; userID is
 * legacy and left alone.
 */
class Login extends Model
{
    use BelongsToCompany;

    // personal = one human | pooled = one at a time | shared = many at once
    // service  = held by NOBODY on purpose — it runs the system (a Perforce domain
    //            admin, a backup agent). "Unassigned" is its correct state, not a gap.
    // breakglass = sealed emergency access. Nobody uses it day-to-day; restricted
    //            always, and revealing one is the audit event that matters most.
    public const SHARING = ['personal', 'pooled', 'shared', 'service', 'breakglass'];

    protected $primaryKey = 'loginID';
    protected $guarded = ['loginID'];
    protected $appends = ['id'];
    public function getIdAttribute() { return $this->getKey(); }   // expose River PK as `id`
    protected $casts = [
        'is_active' => 'boolean',
        'is_restricted' => 'boolean',
    ];

    // --- what it's for (at most one) ---
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class, 'vendorID', 'vendorID'); }
    public function device(): BelongsTo { return $this->belongsTo(Device::class, 'device_id', 'deviceID'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id', 'id'); }

    /** Legacy single owner. ITer reads userID; the app uses holders(). */
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'userID', 'id'); }

    // --- who holds it ---
    /** Everyone who can use this credential. Empty = nobody (an available pooled seat). */
    public function holders(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'login_access', 'login_id', 'user_id')->withTimestamps();
    }

    /** Seats this account consumes. One account can carry several licenses. */
    public function licenses(): BelongsToMany
    {
        return $this->belongsToMany(License::class, 'license_login', 'login_id', 'license_id')->withTimestamps();
    }

    /** @deprecated use licenses() — kept so existing river call sites keep working. */
    public function subscriptions(): HasMany { return $this->hasMany(License::class, 'login_id', 'loginID'); }

    // --- seat semantics ---
    public function isPooled(): bool { return $this->sharing === 'pooled'; }
    public function isShared(): bool { return $this->sharing === 'shared'; }

    /** A pooled seat with nobody attached is available to hand out. */
    public function isAvailableSeat(): bool
    {
        return $this->isPooled() && $this->holders()->count() === 0;
    }

    /**
     * More than one human on a seat that isn't declared shared. For a licensed product
     * that's a compliance problem (vendors license per-human); for a mailbox it's the
     * point — which is why `sharing` is declared, not inferred.
     */
    public function isOverShared(): bool
    {
        return ! $this->isShared() && $this->holders()->count() > 1;
    }

    /** Reassign a pooled seat: exactly one holder at a time. */
    public function assignSeatTo(?User $user): void
    {
        $this->holders()->sync($user ? [$user->id] : []);
    }
}
