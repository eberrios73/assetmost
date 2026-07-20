<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company onboarding steps as JSON — every company's SOP differs (on-prem AD
 * via DC01 vs a cloud console), so the steps are DATA, not code. Companies paste
 * their existing SOP; each line parses into a step, instructions keep the SOP's
 * own wording, and the wizard turns the template into a chained task project.
 * `automatable` on a step is the hook for API automation whenever it's approved.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('onboarding_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('name')->default('Onboarding');
            $t->longText('steps');            // JSON: {version, steps:[{id,title,instructions,category,subtasks,automatable}]}
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_templates');
    }
};
