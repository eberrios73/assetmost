<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line in a task's log. See the task_comments migration for the idea. */
class TaskComment extends Model
{
    protected $guarded = ['id'];

    public function author(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
}
