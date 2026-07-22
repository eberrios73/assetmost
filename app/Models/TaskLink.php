<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pasted URL on a task — a PR, a ticket, a doc. Linking without an integration. */
class TaskLink extends Model
{
    protected $guarded = ['id'];

    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
}
