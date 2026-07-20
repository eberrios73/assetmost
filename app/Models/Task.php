<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
    protected $casts = [
        'done' => 'boolean',
        'is_project' => 'boolean',
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
}
