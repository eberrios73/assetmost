<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task can belong to a project (both live in it_tasks; projects are rows with
 * is_project=1). The link is what makes the timeline view mean something — a
 * project's bar spans its tasks. ON DELETE SET NULL: removing a project frees
 * its tasks rather than deleting the work log.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('it_tasks', function (Blueprint $t) {
            $t->foreignId('parent_id')->nullable()->after('is_project')
                ->constrained('it_tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('it_tasks', fn (Blueprint $t) => $t->dropConstrainedForeignId('parent_id'));
    }
};
