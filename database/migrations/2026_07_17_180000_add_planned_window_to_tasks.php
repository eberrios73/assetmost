<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The planned window: where the work is SCHEDULED, dragged around on the
 * timeline. Distinct from `origin`, which records when the task was born and
 * never moves — you can replan the future, not the past. Subtasks need no
 * schema at all: parent_id already accepts any task as a parent.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            $t->date('planned_start')->nullable()->after('depends_on_id');
            $t->date('due_date')->nullable()->after('planned_start');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn(['planned_start', 'due_date']));
    }
};
