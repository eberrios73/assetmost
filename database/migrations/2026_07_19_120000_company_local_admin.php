<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company's standard local-admin credential — a company preference, not a
 * person. SOPs write /localadmin; the credential is inserted at GENERATION
 * time into the machine script ({LOCAL_ADMIN_USER}/{LOCAL_ADMIN_PASS} context
 * vars), so it never lives in a document. Ships with a /localadmin command
 * that creates the account on all three platforms.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('local_admin_user')->nullable()->after('local_domain');
            $t->string('local_admin_pass')->nullable()->after('local_admin_user');
        });

        DB::table('script_snippets')->insert([
            'company_id' => null, 'command' => 'localadmin',
            'label' => 'Create the standard local admin account (set in the company settings)',
            'params' => null,
            'mac_script' => "# Standard local admin - credential from the company settings, inserted at generation\n"
                . "sysadminctl -addUser \"{LOCAL_ADMIN_USER}\" -password \"{LOCAL_ADMIN_PASS}\" -admin 2>/dev/null \\\n"
                . "  && report 'localadmin' true 'Local admin {LOCAL_ADMIN_USER} created' \\\n"
                . "  || report 'localadmin' false 'Create {LOCAL_ADMIN_USER} manually (may already exist)'",
            'windows_script' => "# Standard local admin - credential from the company settings, inserted at generation\n"
                . "net user \"{LOCAL_ADMIN_USER}\" \"{LOCAL_ADMIN_PASS}\" /add /y\n"
                . "net localgroup Administrators \"{LOCAL_ADMIN_USER}\" /add\n"
                . "Report 'localadmin' \$true 'Local admin {LOCAL_ADMIN_USER} created'",
            'linux_script' => "# Standard local admin - credential from the company settings, inserted at generation\n"
                . "useradd -m \"{LOCAL_ADMIN_USER}\" 2>/dev/null; echo \"{LOCAL_ADMIN_USER}:{LOCAL_ADMIN_PASS}\" | chpasswd\n"
                . "usermod -aG sudo \"{LOCAL_ADMIN_USER}\" 2>/dev/null || usermod -aG wheel \"{LOCAL_ADMIN_USER}\"\n"
                . "report 'localadmin' true 'Local admin {LOCAL_ADMIN_USER} created'",
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['local_admin_user', 'local_admin_pass']));
        DB::table('script_snippets')->where('command', 'localadmin')->whereNull('company_id')->delete();
    }
};
