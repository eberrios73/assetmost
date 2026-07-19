<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The domain-join credential is a COMPANY preference, like the local admin:
 * set once on the company, inserted into the machine script at GENERATION
 * time ({DOMAIN_JOIN_USER}/{DOMAIN_JOIN_PASS}), never prompted and never in
 * a document. The shipped /domainjoin drops its runtime params accordingly.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('domain_join_user')->nullable()->after('local_admin_pass');
            $t->string('domain_join_pass')->nullable()->after('domain_join_user');
        });

        DB::table('script_snippets')->where('command', 'domainjoin')->whereNull('company_id')->update([
            'label' => 'Join the company domain (credential from the company settings)',
            'params' => null,
            'mac_script' => "# Bind to AD - domain and join credential from the company settings, inserted at generation\n"
                . "dsconfigad -add \"{LOCAL_DOMAIN}\" -username \"{DOMAIN_JOIN_USER}\" -password \"{DOMAIN_JOIN_PASS}\" -force \\\n"
                . "  && report 'domainjoin' true 'Joined {LOCAL_DOMAIN}' \\\n"
                . "  || report 'domainjoin' false 'Join {LOCAL_DOMAIN} manually (check DNS and the join credential in the company settings)'",
            'windows_script' => "# Join AD - domain and join credential from the company settings, inserted at generation\n"
                . "\$DjSec = ConvertTo-SecureString \"{DOMAIN_JOIN_PASS}\" -AsPlainText -Force\n"
                . "\$DjCred = New-Object System.Management.Automation.PSCredential(\"{LOCAL_DOMAIN}\\{DOMAIN_JOIN_USER}\", \$DjSec)\n"
                . "try {\n"
                . "  Add-Computer -DomainName \"{LOCAL_DOMAIN}\" -Credential \$DjCred -Force -ErrorAction Stop\n"
                . "  Report 'domainjoin' \$true 'Joined {LOCAL_DOMAIN} - restart to finish'\n"
                . "} catch {\n"
                . "  Report 'domainjoin' \$false \"Join failed: \$(\$_.Exception.Message)\"\n"
                . "}",
            'linux_script' => "# Join AD via realmd - domain and join credential from the company settings, inserted at generation\n"
                . "if command -v realm >/dev/null 2>&1; then\n"
                . "  echo \"{DOMAIN_JOIN_PASS}\" | realm join --user=\"{DOMAIN_JOIN_USER}\" \"{LOCAL_DOMAIN}\" \\\n"
                . "    && report 'domainjoin' true 'Joined {LOCAL_DOMAIN} via realmd' \\\n"
                . "    || report 'domainjoin' false 'realm join {LOCAL_DOMAIN} failed (check sssd and DNS)'\n"
                . "else\n"
                . "  report 'domainjoin' false 'Install realmd first (apt install realmd sssd sssd-tools adcli)'\n"
                . "fi",
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['domain_join_user', 'domain_join_pass']));
    }
};
