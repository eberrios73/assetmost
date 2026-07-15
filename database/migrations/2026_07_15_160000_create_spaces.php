<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spaces — top-level doc containers (Docmost-style). Each doc page belongs to
 * one space; the sidebar shows one space's tree at a time instead of every
 * page jumbled together. Tenant-scoped; membership/visibility can layer on later.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('spaces', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('icon', 16)->nullable();
            $t->string('color', 16)->nullable();     // avatar tint
            $t->string('description')->nullable();
            $t->integer('position')->default(0);
            $t->timestamps();
            $t->index(['company_id', 'position']);
        });

        Schema::table('doc_pages', function (Blueprint $t) {
            $t->foreignId('space_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('doc_pages', function (Blueprint $t) {
            $t->dropConstrainedForeignId('space_id');
        });
        Schema::dropIfExists('spaces');
    }
};
