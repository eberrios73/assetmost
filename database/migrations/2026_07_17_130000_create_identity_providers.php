<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a company's people come from — one row per company per provider.
 *
 * This replaces the Directory (Samba/AD) and Microsoft 365 settings sections. Both were
 * app-wide, which was wrong twice over: identity is a per-company fact (two companies in
 * one install do not share a tenant), and AD needs software reachable on the company's
 * own LAN, which a self-hosted box sitting somewhere else is not. So AD is out and the
 * providers that answer over the public internet are in: Google, Okta, Microsoft.
 *
 * Sync only — the provider says who exists, the app decides what they may do. A person
 * arriving from a sync is a directory record; can_login and role stay local decisions.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('identity_providers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('provider', 32);              // google | okta | microsoft
            $t->boolean('enabled')->default(false);
            $t->string('domain')->nullable();        // verified domain / Okta org URL
            $t->string('tenant_id')->nullable();     // Microsoft directory (tenant) id
            $t->string('client_id')->nullable();
            $t->text('client_secret')->nullable();   // encrypted cast — never rendered back
            $t->boolean('sync_on_login')->default(false);
            $t->timestamp('last_sync_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_providers');
    }
};
