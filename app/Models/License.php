<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A company's purchase of a product: "25 seats of Creative Cloud, renews January".
 *
 * River keeps the physical table as `subscriptions` because ITer runs on this same
 * database and reads it — the rename lives in the model, not the schema. `name` maps to
 * the legacy `subscription_name` column for the same reason.
 *
 * Seats are consumed by LOGINS (accounts), not people. People reach a seat by holding the
 * account, which is what lets one account carry two licenses and one mailbox serve ten.
 */
class License extends Model
{
    use BelongsToCompany;

    protected $table = 'subscriptions';         // ITer reads this table; do not rename it
    protected $guarded = ['id'];
    protected $appends = ['name', 'seats_used', 'seats_available'];
    protected $casts = [
        'renewal_date' => 'date',
        'is_active' => 'boolean',
        'amount' => 'decimal:2',
        'seats_total' => 'integer',
    ];

    // `name` is the app-facing field; the column is the legacy subscription_name.
    public function getNameAttribute(): ?string { return $this->attributes['subscription_name'] ?? null; }
    public function setNameAttribute($v): void { $this->attributes['subscription_name'] = $v; }

    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id', 'id'); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class, 'vendor_id', 'vendorID'); }

    /** Accounts consuming a seat. Joins license_login.login_id -> logins.loginID. */
    public function logins(): BelongsToMany
    {
        return $this->belongsToMany(Login::class, 'license_login', 'license_id', 'login_id')->withTimestamps();
    }

    public function getSeatsUsedAttribute(): int { return $this->logins()->count(); }

    /** Null (not 0) when the purchased count is unknown — it gets backfilled over time,
     *  and "unknown" must not render as "none available". */
    public function getSeatsAvailableAttribute(): ?int
    {
        if ($this->seats_total === null) { return null; }
        return max(0, $this->seats_total - $this->seats_used);
    }

    public function isOverAllocated(): bool
    {
        return $this->seats_total !== null && $this->seats_used > $this->seats_total;
    }
}
