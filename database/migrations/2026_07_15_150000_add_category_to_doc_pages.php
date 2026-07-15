<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Doc category/type (Incident, SOP, Troubleshooting, …) — set by the template, filterable. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('doc_pages', function (Blueprint $t) {
            $t->string('category')->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('doc_pages', function (Blueprint $t) {
            $t->dropColumn('category');
        });
    }
};
