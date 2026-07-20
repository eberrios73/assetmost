<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Templates come in KINDS — onboarding, offboarding, workstation imaging — and a
 * company can keep department VARIANTS of each (Design's onboarding differs from
 * Accounting's). The shipped starters mean nobody HAS to paste an SOP to begin;
 * pasting stays as the bring-your-own path. variant '' = the company default.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('onboarding_templates', function (Blueprint $t) {
            $t->string('kind', 20)->default('onboarding')->after('company_id');
            $t->string('variant', 100)->default('')->after('kind');
            $t->dropUnique(['company_id']);
            $t->unique(['company_id', 'kind', 'variant'], 'onboarding_templates_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_templates', function (Blueprint $t) {
            $t->dropUnique('onboarding_templates_scope_unique');
            $t->unique('company_id');
            $t->dropColumn(['kind', 'variant']);
        });
    }
};
