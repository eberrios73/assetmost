<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * /chrome — install Google Chrome straight from Google's STABLE download URLs
 * (they've been unchanged for a decade; no repo file to maintain, no MDM
 * needed). The approved third-party browser, one command.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('script_snippets')->insert([
            'company_id' => null, 'command' => 'chrome', 'params' => null,
            'label' => 'Install Google Chrome (direct from Google, stable URL)',
            'mac_script' => "# Google Chrome - straight from Google's stable universal DMG\n"
                . "CT=/tmp/googlechrome.dmg; COK=0\n"
                . "if curl -fsSL 'https://dl.google.com/chrome/mac/universal/stable/GGRO/googlechrome.dmg' -o \"\$CT\"; then\n"
                . "  M=\$(hdiutil attach \"\$CT\" -nobrowse | grep -o '/Volumes/.*' | head -1)\n"
                . "  cp -R \"\$M/Google Chrome.app\" /Applications/ 2>/dev/null && COK=1\n"
                . "  hdiutil detach \"\$M\" >/dev/null 2>&1\n"
                . "fi\n"
                . "[ \"\$COK\" = 1 ] && report 'chrome' true 'Chrome installed' || report 'chrome' false 'Install Chrome manually'\n"
                . "rm -f \"\$CT\"",
            'windows_script' => "# Google Chrome - enterprise MSI from Google's stable URL\n"
                . "\$CT = \"\$env:TEMP\\chrome.msi\"; \$COK = \$false\n"
                . "try {\n"
                . "  Invoke-WebRequest 'https://dl.google.com/dl/chrome/install/googlechromestandaloneenterprise64.msi' -OutFile \$CT -UseBasicParsing\n"
                . "  Start-Process msiexec -ArgumentList '/i',\"\$CT\",'/qn' -Wait; \$COK = \$true\n"
                . "} catch {}\n"
                . "if (\$COK) { Report 'chrome' \$true 'Chrome installed' } else { Report 'chrome' \$false 'Install Chrome manually' }\n"
                . "Remove-Item \$CT -ErrorAction SilentlyContinue",
            'linux_script' => "# Google Chrome - direct .deb from Google's stable URL (Debian/Ubuntu)\n"
                . "if command -v apt-get >/dev/null; then\n"
                . "  curl -fsSL https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb -o /tmp/chrome.deb \\\n"
                . "    && apt-get install -y /tmp/chrome.deb >/dev/null 2>&1 \\\n"
                . "    && report 'chrome' true 'Chrome installed' || report 'chrome' false 'Install Chrome manually'\n"
                . "  rm -f /tmp/chrome.deb\n"
                . "else\n"
                . "  report 'chrome' false 'Install Chrome from the Google repo manually (rpm distro)'\n"
                . "fi",
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('script_snippets')->where('command', 'chrome')->whereNull('company_id')->delete();
    }
};
