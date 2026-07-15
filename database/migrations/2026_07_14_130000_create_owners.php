<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Owner (Main Tenant) — the account that owns this install.
 * Everything tenancy-related that isn't row data lives in `settings` (JSON):
 * default company, edition config, and whatever the private multi-tenant module needs.
 * Keeping it JSON is what lets the multi-tenant data + logic stay a swappable module.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('owners', function (Blueprint $t) {
            $t->id();
            $t->string('name')->default('Owner');
            $t->json('settings')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owners');
    }
};
