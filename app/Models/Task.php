<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One table, five words: Project → Subproject → Milestone → Task → Subtask.
 * `kind` says what a row is; `parent_id` says where it hangs. A subproject is a
 * project under a project; a subtask is a task under a task — neither needs its
 * own kind.
 */
class Task extends Model
{
    use BelongsToCompany;

    public const PROJECT = 'project';
    public const MILESTONE = 'milestone';
    public const TASK = 'task';
    public const KINDS = [self::PROJECT, self::MILESTONE, self::TASK];

    /** The whole workflow. Deliberately not configurable. */
    public const STATES = ['todo', 'doing', 'blocked', 'done'];

    protected $guarded = ['id'];
    protected $casts = [
        'done' => 'boolean',
        'week' => 'date',
        'origin' => 'date',
        'planned_start' => 'date',
        'due_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(TaskLink::class)->orderBy('id');
    }

    public function isProject(): bool { return $this->kind === self::PROJECT; }
    public function isMilestone(): bool { return $this->kind === self::MILESTONE; }
}
