<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A company's purchase of a product: "25 seats of Creative Cloud, renews January".
 *
 * Seats are consumed by LOGINS (accounts), not people. People reach a seat by holding the
 * account, which is what lets one account carry two licenses and one mailbox serve ten.
 */
class License extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
    protected $appends = ['seats_used', 'seats_available'];
    protected $casts = [
        'renewal_date' => 'date',
        'is_active' => 'boolean',
        'amount' => 'decimal:2',
        'seats_total' => 'integer',
    ];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }

    /** Accounts consuming a seat. */
    public function logins(): BelongsToMany
    {
        return $this->belongsToMany(Login::class)->withTimestamps();
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
