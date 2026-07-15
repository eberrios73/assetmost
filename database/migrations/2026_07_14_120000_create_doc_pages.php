<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Docs: a lightweight wiki (Docmost analog). Nested pages, per-company, markdown body.
 * Shares the same company tenant as assets/people so docs sit next to what they describe.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('doc_pages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('doc_pages')->nullOnDelete();
            $t->string('title')->default('Untitled');
            $t->longText('body')->nullable();          // markdown
            $t->string('icon', 16)->nullable();         // optional emoji
            $t->integer('position')->default(0);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_pages');
    }
};
