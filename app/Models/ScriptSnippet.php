<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A /slash command an SOP can use, with per-platform script bodies. Global rows
 * (company_id NULL) ship with the product and are visible to every company;
 * company rows are that company's own commands. Deliberately no tenancy global
 * scope — visibility is the union, handled in the controller.
 */
class ScriptSnippet extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean'];
}
