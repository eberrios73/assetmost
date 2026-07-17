<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a person may sign in — stated, not inferred.
 *
 * The users table is a staff directory: people who own devices and hold credentials.
 * Only IT signs in. Previously "may this person sign in?" was answered by "do they happen
 * to have a password?", which is an accident rather than a decision — a leftover hash from
 * years ago silently means yes. On River that accident is 42 ordinary staff.
 *
 * Defaults to false, and access is granted here only to the roles that administer the app.
 * Legacy passwords stay in the column untouched (ITer still reads them); they just stop
 * being an answer to a question nobody asked.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('can_login')->default(false)->after('role')->index();
        });

        DB::table('users')
            ->whereIn('role', ['IT Admin', 'SuperAdmin'])
            ->whereNotNull('password')->where('password', '<>', '')
            ->where('active', true)
            ->update(['can_login' => true]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('can_login'));
    }
};
