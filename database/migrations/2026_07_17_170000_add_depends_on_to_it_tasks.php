<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task can declare ONE predecessor ("after: …"); chains compose A←B←C, which
 * covers how IT work actually sequences. The timeline draws the connectors.
 * Single column, not a pivot — fan-in (multiple predecessors) can upgrade this
 * to a pivot later if real work ever needs it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('it_tasks', function (Blueprint $t) {
            $t->foreignId('depends_on_id')->nullable()->after('parent_id')
                ->constrained('it_tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('it_tasks', fn (Blueprint $t) => $t->dropConstrainedForeignId('depends_on_id'));
    }
};
