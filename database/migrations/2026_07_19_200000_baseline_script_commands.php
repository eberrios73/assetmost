<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The bootstrap script's formerly built-in sections become EXPLICIT registry
 * commands — the SOP states everything the script does, in the SOP's order:
 *   /hostname     rename the machine to its asset tag
 *   /encryptdisk  FileVault / BitLocker on, recovery key escrowed to the registry
 *   /osupdate     apply OS updates
 * ($A/$T — report URL and token — come from the script preamble.)
 */
return new class extends Migration {
    private const COMMANDS = [
        [
            'command' => 'hostname',
            'label' => 'Rename the machine to its asset tag',
            'mac_script' => "# Names = asset tag\n"
                . "scutil --set ComputerName '{ASSET_TAG}'; scutil --set HostName '{ASSET_TAG}'; scutil --set LocalHostName '{ASSET_TAG}'\n"
                . "report 'hostname' true 'Renamed to {ASSET_TAG}'",
            'windows_script' => "# Hostname = asset tag\n"
                . "if (\$env:COMPUTERNAME -ne '{ASSET_TAG}') {\n"
                . "    Rename-Computer -NewName '{ASSET_TAG}' -Force\n"
                . "    Report 'hostname' \$true 'Renamed to {ASSET_TAG} - takes effect after restart'\n"
                . "}",
            'linux_script' => "# Hostname = asset tag\n"
                . "hostnamectl set-hostname '{ASSET_TAG}' 2>/dev/null || hostname '{ASSET_TAG}'\n"
                . "report 'hostname' true 'Renamed to {ASSET_TAG}'",
        ],
        [
            'command' => 'encryptdisk',
            'label' => 'Encrypt the disk; recovery key escrowed to the registry (no paper, no txt)',
            'mac_script' => "# FileVault on, recovery key escrowed to the registry\n"
                . "if fdesetup status | grep -q 'Off'; then\n"
                . "  KEY=\$(fdesetup enable -user \"\$SUDO_USER\" | grep -oE \"([A-Z0-9]{4}-){5}[A-Z0-9]{4}\")\n"
                . "  if [ -n \"\$KEY\" ]; then\n"
                . "    curl -sk -X POST \"\$A/onboard/key?t=\$T\" -H 'Content-Type: application/json' -d \"{\\\"key\\\":\\\"\$KEY\\\"}\" >/dev/null 2>&1 \\\n"
                . "      && report 'encryptdisk' true 'FileVault on - key escrowed to the registry' \\\n"
                . "      || report 'encryptdisk' false 'Key capture failed - escrow manually'\n"
                . "  else\n"
                . "    report 'encryptdisk' false 'fdesetup gave no key - enable manually and escrow'\n"
                . "  fi\n"
                . "else\n"
                . "  report 'encryptdisk' true 'FileVault already on'\n"
                . "fi",
            'windows_script' => "# BitLocker on, recovery key escrowed to the registry\n"
                . "\$blv = Get-BitLockerVolume -MountPoint C: -ErrorAction SilentlyContinue\n"
                . "if (\$blv -and \$blv.ProtectionStatus -ne 'On') {\n"
                . "    Enable-BitLocker -MountPoint C: -RecoveryPasswordProtector -SkipHardwareTest\n"
                . "    \$blv = Get-BitLockerVolume -MountPoint C:\n"
                . "}\n"
                . "\$key = (\$blv.KeyProtector | Where-Object KeyProtectorType -eq 'RecoveryPassword' | Select-Object -First 1).RecoveryPassword\n"
                . "if (\$key) {\n"
                . "    try {\n"
                . "        Invoke-RestMethod \"\$A/onboard/key?t=\$T\" -Method POST -ContentType 'application/json' -Body (@{ key = \$key } | ConvertTo-Json) | Out-Null\n"
                . "        Report 'encryptdisk' \$true 'BitLocker on - key escrowed to the registry'\n"
                . "    } catch { Report 'encryptdisk' \$false \$_.Exception.Message }\n"
                . "}",
            'linux_script' => "# LUKS is an install-time choice - nothing to enable after the fact\n"
                . "lsblk -o NAME,TYPE | grep -q crypt \\\n"
                . "  && report 'encryptdisk' true 'LUKS volume present' \\\n"
                . "  || report 'encryptdisk' false 'No encrypted volume - reinstall with LUKS if required'",
        ],
        [
            'command' => 'osupdate',
            'label' => 'Apply OS updates (best effort)',
            'mac_script' => "# Updates\n"
                . "softwareupdate --install --all 2>/dev/null\n"
                . "report 'osupdate' true 'softwareupdate pass attempted'",
            'windows_script' => "# Updates (best effort; finish in Settings if needed)\n"
                . "try { Install-Module PSWindowsUpdate -Force -Scope CurrentUser -ErrorAction Stop; Get-WindowsUpdate -AcceptAll -Install -IgnoreReboot } catch {}\n"
                . "Report 'osupdate' \$true 'Windows Update pass attempted'",
            'linux_script' => "# Updates (Debian/Ubuntu or RHEL family)\n"
                . "if command -v apt-get >/dev/null; then apt-get update -qq && apt-get upgrade -y -qq; else yum update -y -q; fi\n"
                . "report 'osupdate' true 'Update pass attempted'",
        ],
    ];

    public function up(): void
    {
        foreach (self::COMMANDS as $c) {
            DB::table('script_snippets')->insert($c + [
                'company_id' => null, 'params' => null, 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('script_snippets')
            ->whereIn('command', array_column(self::COMMANDS, 'command'))
            ->whereNull('company_id')->delete();
    }
};
