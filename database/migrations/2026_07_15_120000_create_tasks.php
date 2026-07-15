<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks + projects (ported from the standalone IT-tasks app), tenant-scoped and
 * assignable to a person. Tasks are grouped by week; "projects" carry the richer
 * status fields (details/impact/needs/challenges/workarounds).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->string('title');
            $t->date('week');                       // week-start (Monday) for grouping
            $t->boolean('done')->default(false);
            $t->unsignedTinyInteger('pct')->default(0);   // 0-100
            $t->unsignedTinyInteger('pri')->default(0);   // 0 none, 1 low, 2 med, 3 high
            $t->boolean('is_project')->default(false);
            $t->integer('ord')->default(0);
            $t->string('status')->default('');       // project status
            $t->text('details')->nullable();
            $t->text('impact')->nullable();
            $t->text('needs')->nullable();
            $t->text('challenges')->nullable();
            $t->text('workarounds')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
