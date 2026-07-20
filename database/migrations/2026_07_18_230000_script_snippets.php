<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The commands registry: every row is a /slash command an SOP can use, carrying
 * its Mac / Windows / Linux script bodies. The command name comes FIRST in the
 * SOP (/banner install, /wifi HCGuest secret); everything after it maps
 * positionally onto the declared params, referenced as {name} in the scripts —
 * plus the ambient context vars ({ASSET_TAG} {BASE_URL} {TOKEN} {REPO}
 * {DOMAIN} {LOCAL_DOMAIN}). company_id NULL = shipped/global.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('script_snippets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('command', 40);
            $t->string('label')->nullable();
            $t->string('params')->nullable();          // comma list of ordered param names
            $t->text('mac_script')->nullable();
            $t->text('windows_script')->nullable();
            $t->text('linux_script')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'command']);
        });

        $now = now();
        DB::table('script_snippets')->insert([
            [
                'company_id' => null, 'command' => 'banner',
                'label' => 'Install the login policy banner', 'params' => null,
                'mac_script' => "# Login policy banner - the rtfd bundle ships as a zip on the share\n"
                    . "curl -fsSL \"{REPO}/Mac/PolicyBanner.rtfd.zip\" -o /tmp/PolicyBanner.rtfd.zip 2>/dev/null \\\n"
                    . "  && sudo unzip -oq /tmp/PolicyBanner.rtfd.zip -d /Library/Security \\\n"
                    . "  && report 'banner' true 'Policy banner installed' \\\n"
                    . "  || report 'banner' false 'Place PolicyBanner in /Library/Security manually'",
                'windows_script' => "# Logon legal notice (the Windows banner)\n"
                    . "reg add \"HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System\" /v legalnoticecaption /t REG_SZ /d \"Company Policy\" /f\n"
                    . "reg add \"HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System\" /v legalnoticetext /t REG_SZ /d \"Authorized use only.\" /f\n"
                    . "Report 'banner' \$true 'Legal notice set'",
                'linux_script' => "# Login banner\necho \"Authorized use only.\" > /etc/issue\nreport 'banner' true 'Banner set'",
                'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'company_id' => null, 'command' => 'wifi',
                'label' => 'Join a wifi network', 'params' => 'ssid, psk',
                'mac_script' => "# Join wifi {ssid}\nnetworksetup -setairportnetwork en0 \"{ssid}\" \"{psk}\" \\\n"
                    . "  && report 'wifi' true 'Joined {ssid}' || report 'wifi' false 'Could not join {ssid}'",
                'windows_script' => "# Join wifi {ssid} (adds a profile, then connects)\n"
                    . "netsh wlan add profile filename=\"{ssid}.xml\" 2>\$null\n"
                    . "netsh wlan connect name=\"{ssid}\"\nReport 'wifi' \$true 'Joined {ssid}'",
                'linux_script' => "# Join wifi {ssid}\nnmcli device wifi connect \"{ssid}\" password \"{psk}\" \\\n"
                    . "  && report 'wifi' true 'Joined {ssid}' || report 'wifi' false 'Could not join {ssid}'",
                'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('script_snippets');
    }
};
