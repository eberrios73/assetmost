<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-company onboarding steps (JSON). See the migration for the shape. */
class OnboardingTemplate extends Model
{
    protected $guarded = ['id'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
