<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AD/LAN domain (plutonic.local) is not the email domain
 * (plutonicgames.com) — scripts and SOPs need the local one: domain joins,
 * AD user creation, DOMAIN\username logins. Available as {local_domain} in
 * SOP templates and script generation.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->string('local_domain')->nullable()->after('domain'));
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('local_domain'));
    }
};
