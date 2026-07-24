<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Custom doc templates — the commands-registry pattern applied to pages.
     * Shipped templates stay in the app (read-only, versioned with releases);
     * these rows are the company's own additions, editable in Docs > Templates
     * and offered by every + New menu.
     */
    public function up(): void
    {
        Schema::create('doc_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('label', 80);
            $t->string('hint')->nullable();
            $t->string('category', 40)->nullable();
            $t->text('body')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_templates');
    }
};
