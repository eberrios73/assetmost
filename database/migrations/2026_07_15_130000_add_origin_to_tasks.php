<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `origin` = the week a task was first created. Weekly rollover moves `week`
 * forward for unfinished tasks but leaves `origin` alone, so origin < week
 * means "carried over from an earlier week" (the ↻ indicator).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            $t->date('origin')->nullable()->after('week');
        });
        // backfill any existing rows
        \DB::table('tasks')->whereNull('origin')->update(['origin' => \DB::raw('week')]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            $t->dropColumn('origin');
        });
    }
};
