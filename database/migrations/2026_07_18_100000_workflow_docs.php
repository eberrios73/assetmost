<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Docs become the workflow engine. A page with workflow_type set IS a workflow —
 * the onboarding tabs are just filtered views of these pages, and everything the
 * engine runs (task projects, bootstrap scripts, /refs) reads workflow_steps.
 *
 * The structured steps are the SOURCE; the page body is the human description,
 * regenerated from the steps. Parsing a doc/paste is an import, not a live sync.
 *
 * Shipped baselines (installed with the product, editable, deactivatable,
 * duplicatable) carry workflow_shipped=1. Additive only — River is shared.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('doc_pages', function (Blueprint $t) {
            $t->string('workflow_type', 10)->nullable()->after('category');   // people | device
            $t->string('workflow_slug', 60)->nullable()->after('workflow_type'); // canonical /ref slug
            $t->string('form_factor', 40)->nullable()->after('workflow_slug'); // device: Windows Workstation…
            $t->boolean('workflow_active')->default(true)->after('form_factor');
            $t->boolean('workflow_shipped')->default(false)->after('workflow_active');
            $t->boolean('workflow_wizard')->default(false)->after('workflow_shipped'); // Info tab shows the run wizard
            $t->longText('workflow_steps')->nullable()->after('workflow_wizard');     // {version, steps:[…]} — the engine's input
            $t->index(['company_id', 'workflow_type']);
        });
    }

    public function down(): void
    {
        Schema::table('doc_pages', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'workflow_type']);
            $t->dropColumn(['workflow_type', 'workflow_slug', 'form_factor', 'workflow_active', 'workflow_shipped', 'workflow_wizard', 'workflow_steps']);
        });
    }
};
