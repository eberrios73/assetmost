<?php

namespace App\Models;

use App\Support\Access;
use Illuminate\Database\Eloquent\Model;

/**
 * An override of a shipped default from App\Support\Access::DEFAULTS.
 *
 * Only differences are stored, so upgrading the defaults moves every install that hasn't
 * deliberately said otherwise.
 */
class RolePermission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['allowed' => 'boolean'];
    }

    protected static function booted(): void
    {
        $flush = fn () => Access::forget();
        static::saved($flush);
        static::deleted($flush);
    }
}
