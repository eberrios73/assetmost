<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The install owner (Main Tenant). Singleton. All non-row tenancy config is in `settings` JSON.
 */
class Owner extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['settings' => 'array'];

    /** The single owner record (created on first access). */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['name' => config('app.name', 'Owner'), 'settings' => []]);
    }

    public function setting(string $key, $default = null)
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public function putSetting(string $key, $value): void
    {
        $s = $this->settings ?? [];
        data_set($s, $key, $value);
        $this->settings = $s;
        $this->save();
    }
}
