<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A floating account is ONE credential identity used across many services.
 *
 * itmgr@plutonicgames.com is one account; its Adobe, Zoom and Ableton logins are
 * uses of it, not more accounts. Listing login rows as "accounts" showed the
 * same credential 29 times. So the identity becomes a first-class row:
 * assignment (who holds the credential) lives HERE, and service logins point at
 * it. Identity logins (a person's own email) never get an account row.
 *
 * Additive only — River/ITer read `logins` untouched; account_id is a new
 * nullable column they ignore.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->string('identifier');                 // the credential: email or username
            $t->string('sharing', 12)->default('shared')->index();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique('identifier');
        });

        Schema::create('account_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['account_id', 'user_id']);
        });

        Schema::table('logins', function (Blueprint $t) {
            $t->foreignId('account_id')->nullable()->after('userID')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('logins', fn (Blueprint $t) => $t->dropConstrainedForeignId('account_id'));
        Schema::dropIfExists('account_user');
        Schema::dropIfExists('accounts');
    }
};
