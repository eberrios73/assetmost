<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Docs page is the SOP's MASTER — rich text, the thing humans actually
 * edit. The template is a parsed artifact of it; "re-parse" is the sync. This
 * column remembers which page a template was compiled from.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('onboarding_templates', function (Blueprint $t) {
            $t->foreignId('source_page_id')->nullable()->after('steps')
                ->constrained('doc_pages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_templates', fn (Blueprint $t) => $t->dropConstrainedForeignId('source_page_id'));
    }
};
