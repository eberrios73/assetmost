<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The domain-join credential IS a login — a record in the registry, not two
 * text fields on the company. The company points at it; generation resolves
 * login_id/login_pass into {DOMAIN_JOIN_USER}/{DOMAIN_JOIN_PASS}. Rotate it
 * in the registry and every future script picks it up. (Plain indexed column,
 * no FK constraint — logins is River's table; stay additive there.)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn(['domain_join_user', 'domain_join_pass']);
            $t->unsignedBigInteger('domain_join_login_id')->nullable()->after('local_admin_pass')->index();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('domain_join_login_id');
            $t->string('domain_join_user')->nullable()->after('local_admin_pass');
            $t->string('domain_join_pass')->nullable()->after('domain_join_user');
        });
    }
};
