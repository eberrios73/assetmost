<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Stored credential. Secret is encrypted at rest (cast), not plaintext.
 *
 * Held by MANY people (login_access), because one-person-per-login can't express the
 * cases that actually exist: a pooled seat handed between designers, a shared mailbox
 * ten people check, an admin account used across six servers.
 *
 * Answers "what is this for?" exactly one way — device, product, vendor, or nothing.
 * Forcing every credential to have a vendor is what turns "Servers/Switches" and "Wifi"
 * into fake vendor rows.
 */
class Login extends Model
{
    use BelongsToCompany;

    public const SHARING = ['personal', 'pooled', 'shared'];

    protected $guarded = ['id'];
    protected $casts = [
        'login_pass' => 'encrypted',
        'is_active' => 'boolean',
        'is_restricted' => 'boolean',
    ];

    // --- what it's for (at most one) ---

    /** Service account with no seats (Godaddy, CloudFlare). */
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }

    /** Infrastructure credential for an asset (ITAdmin @ Mail_Arch_Srv). */
    public function device(): BelongsTo { return $this->belongsTo(Device::class); }

    /** Software account consuming a license seat (ppdesigner1 @ Adobe CC). */
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    // --- who holds it ---

    /** Everyone who can use this credential. Empty = nobody (an available pooled seat). */
    public function holders(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'login_access')->withTimestamps();
    }

    /** Seats this account consumes. One account can carry several licenses. */
    public function licenses(): BelongsToMany
    {
        return $this->belongsToMany(License::class)->withTimestamps();
    }

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
     * whole point, which is why `sharing` has to be declared rather than inferred.
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
