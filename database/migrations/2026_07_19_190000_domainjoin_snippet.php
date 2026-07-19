<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * /domainjoin — join the machine to the company's AD domain ({LOCAL_DOMAIN}
 * from the company settings). The admin credential is NEVER stored: user/pass
 * are declared params, so leaving them out of the SOP makes the generated
 * script prompt at run time (pass prompts silently).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('script_snippets')->insert([
            'company_id' => null, 'command' => 'domainjoin',
            'label' => 'Join the company domain (asks for the domain admin credential)',
            'params' => 'user, pass',
            'mac_script' => "# Bind to AD - domain from the company settings, credential prompted at run time\n"
                . "dsconfigad -add \"{LOCAL_DOMAIN}\" -username \"{user}\" -password \"{pass}\" -force \\\n"
                . "  && report 'domainjoin' true 'Joined {LOCAL_DOMAIN}' \\\n"
                . "  || report 'domainjoin' false 'Join {LOCAL_DOMAIN} manually (check DNS and the credential)'",
            'windows_script' => "# Join AD - domain from the company settings, credential prompted at run time\n"
                . "\$DjSec = ConvertTo-SecureString \"{pass}\" -AsPlainText -Force\n"
                . "\$DjCred = New-Object System.Management.Automation.PSCredential(\"{LOCAL_DOMAIN}\\{user}\", \$DjSec)\n"
                . "try {\n"
                . "  Add-Computer -DomainName \"{LOCAL_DOMAIN}\" -Credential \$DjCred -Force -ErrorAction Stop\n"
                . "  Report 'domainjoin' \$true 'Joined {LOCAL_DOMAIN} - restart to finish'\n"
                . "} catch {\n"
                . "  Report 'domainjoin' \$false \"Join failed: \$(\$_.Exception.Message)\"\n"
                . "}",
            'linux_script' => "# Join AD via realmd - domain from the company settings, credential prompted at run time\n"
                . "if command -v realm >/dev/null 2>&1; then\n"
                . "  echo \"{pass}\" | realm join --user=\"{user}\" \"{LOCAL_DOMAIN}\" \\\n"
                . "    && report 'domainjoin' true 'Joined {LOCAL_DOMAIN} via realmd' \\\n"
                . "    || report 'domainjoin' false 'realm join {LOCAL_DOMAIN} failed (check sssd and DNS)'\n"
                . "else\n"
                . "  report 'domainjoin' false 'Install realmd first (apt install realmd sssd sssd-tools adcli)'\n"
                . "fi",
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('script_snippets')->where('command', 'domainjoin')->whereNull('company_id')->delete();
    }
};
