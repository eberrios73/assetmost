<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc versioning as self-related records: a superseded page points at the page
 * that replaced it. Lists and search filter to CURRENT (superseded_by_id IS
 * NULL); the current page shows its lineage at the bottom. Additive only.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('doc_pages', function (Blueprint $t) {
            $t->unsignedBigInteger('superseded_by_id')->nullable()->after('workflow_steps');
            $t->index('superseded_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('doc_pages', function (Blueprint $t) {
            $t->dropIndex(['superseded_by_id']);
            $t->dropColumn('superseded_by_id');
        });
    }
};
