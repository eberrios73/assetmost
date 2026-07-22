<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The hierarchy gets names: Project → Subproject → Milestone → Task → Subtask.
     * One `kind` column replaces the is_project boolean (a subproject is just a
     * project whose parent is a project — no extra concept). `state` gives tasks
     * the two words between "current" and "completed" that small teams actually
     * use (doing, blocked). `labels` is a comma list — filtering without a JQL.
     * task_links holds pasted URLs (a PR, a ticket, a doc) — linking without an
     * integration.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            $t->string('kind', 12)->default('task')->index()->after('pri');
            $t->string('state', 12)->default('todo')->index()->after('done');
            $t->string('labels')->nullable()->after('notes');
        });

        DB::table('tasks')->where('is_project', true)->update(['kind' => 'project']);
        DB::table('tasks')->where('done', true)->update(['state' => 'done']);

        Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn('is_project'));

        Schema::create('task_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id')->constrained()->cascadeOnDelete();
            $t->string('url', 500);
            $t->string('label', 120)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_links');
        Schema::table('tasks', function (Blueprint $t) {
            $t->boolean('is_project')->default(false);
        });
        DB::table('tasks')->where('kind', 'project')->update(['is_project' => true]);
        Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn(['kind', 'state', 'labels']));
    }
};
