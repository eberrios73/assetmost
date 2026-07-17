<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A staff record is a person, not an account.
 *
 * Most people in a directory never sign in — they're there to own devices and hold
 * credentials. Requiring a password to create one forces every intake to mint a login
 * nobody asked for, which is both a security smell (accounts that exist for no reason)
 * and how role identities like itmgr@ end up with no record at all: there was nowhere to
 * put a person who isn't a user.
 *
 * Null password = cannot authenticate. Give someone a password deliberately, later.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows with a null password can't satisfy NOT NULL; park an unusable hash so the
        // rollback can't fail (an empty string matches no bcrypt input).
        \Illuminate\Support\Facades\DB::table('users')->whereNull('password')->update(['password' => '']);

        Schema::table('users', function (Blueprint $t) {
            $t->string('password')->nullable(false)->change();
        });
    }
};
