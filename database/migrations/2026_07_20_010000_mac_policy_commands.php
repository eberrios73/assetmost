<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The HC production-baseline policy items become explicit registry commands:
 *   /firewall    local firewall on
 *   /screenlock  screen saver 5 min + lock + require password + no hints
 *   /remotemgmt  remote management (ARD on Mac, RDP on Windows, sshd on Linux)
 *   /wifioff     disable Wi-Fi (admins can re-enable)
 *   /printer     add a network printer — params: name, ip (driverless IPP)
 * As always: the SOP states it, the script does it, report() ticks it.
 */
return new class extends Migration {
    private const COMMANDS = [
        [
            'command' => 'firewall', 'params' => null,
            'label' => 'Enable the local firewall',
            'mac_script' => "# Local firewall on\n"
                . "/usr/libexec/ApplicationFirewall/socketfilterfw --setglobalstate on >/dev/null 2>&1 \\\n"
                . "  && report 'firewall' true 'Application firewall on' \\\n"
                . "  || report 'firewall' false 'Enable manually: System Settings > Network > Firewall'",
            'windows_script' => "# Local firewall on (all profiles)\n"
                . "Set-NetFirewallProfile -Profile Domain,Private,Public -Enabled True\n"
                . "Report 'firewall' \$true 'Windows Firewall on'",
            'linux_script' => "# Local firewall on\n"
                . "command -v ufw >/dev/null && ufw --force enable && report 'firewall' true 'ufw enabled' \\\n"
                . "  || report 'firewall' false 'Install/enable a firewall manually (ufw or firewalld)'",
        ],
        [
            'command' => 'screenlock', 'params' => null,
            'label' => 'Screen saver 5 min, lock + require password, no hints',
            'mac_script' => "# Screen saver 5 min + lock immediately + no password hints (for the console user)\n"
                . "U=\$(stat -f%Su /dev/console)\n"
                . "sudo -u \"\$U\" defaults -currentHost write com.apple.screensaver idleTime -int 300\n"
                . "sudo -u \"\$U\" defaults write com.apple.screensaver askForPassword -int 1\n"
                . "sudo -u \"\$U\" defaults write com.apple.screensaver askForPasswordDelay -int 0\n"
                . "defaults write /Library/Preferences/com.apple.loginwindow RetriesUntilHint -int 0\n"
                . "report 'screenlock' true 'Screen lock 5 min, password required, no hints'",
            'windows_script' => "# Screen saver 5 min + secure (current user hive - best effort from an admin shell)\n"
                . "reg add \"HKCU\\Control Panel\\Desktop\" /v ScreenSaveTimeOut /t REG_SZ /d 300 /f | Out-Null\n"
                . "reg add \"HKCU\\Control Panel\\Desktop\" /v ScreenSaveActive /t REG_SZ /d 1 /f | Out-Null\n"
                . "reg add \"HKCU\\Control Panel\\Desktop\" /v ScreenSaverIsSecure /t REG_SZ /d 1 /f | Out-Null\n"
                . "Report 'screenlock' \$true 'Screen lock 5 min + password (verify per user profile)'",
            'linux_script' => "# Screen lock policy varies by desktop - record it as done by hand\n"
                . "report 'screenlock' false 'Set 5-minute screen lock manually (desktop-specific)'",
        ],
        [
            'command' => 'remotemgmt', 'params' => null,
            'label' => 'Enable remote management (ARD / RDP / sshd), admins only',
            'mac_script' => "# Remote Management on - full access for the standard admin only\n"
                . "K=/System/Library/CoreServices/RemoteManagement/ARDAgent.app/Contents/Resources/kickstart\n"
                . "\"\$K\" -activate -configure -allowAccessFor -specifiedUsers -restart -agent >/dev/null 2>&1\n"
                . "\"\$K\" -configure -users \"{LOCAL_ADMIN_USER}\" -access -on -privs -all >/dev/null 2>&1 \\\n"
                . "  && report 'remotemgmt' true 'Remote Management on for {LOCAL_ADMIN_USER}' \\\n"
                . "  || report 'remotemgmt' false 'Enable Remote Management manually (Sharing settings)'",
            'windows_script' => "# RDP on + firewall rule\n"
                . "Set-ItemProperty 'HKLM:\\System\\CurrentControlSet\\Control\\Terminal Server' -Name fDenyTSConnections -Value 0\n"
                . "Enable-NetFirewallRule -DisplayGroup 'Remote Desktop'\n"
                . "Report 'remotemgmt' \$true 'RDP enabled'",
            'linux_script' => "# sshd on\n"
                . "(systemctl enable --now ssh 2>/dev/null || systemctl enable --now sshd 2>/dev/null) \\\n"
                . "  && report 'remotemgmt' true 'sshd enabled' \\\n"
                . "  || report 'remotemgmt' false 'Enable sshd manually'",
        ],
        [
            'command' => 'wifioff', 'params' => null,
            'label' => 'Disable Wi-Fi (admins can re-enable when needed)',
            'mac_script' => "# Wi-Fi off - wired production subnet only; admins can re-enable\n"
                . "W=\$(networksetup -listallhardwareports | awk '/Wi-Fi|AirPort/{getline; print \$2; exit}')\n"
                . "if [ -n \"\$W\" ]; then\n"
                . "  networksetup -setairportpower \"\$W\" off 2>/dev/null\n"
                . "  networksetup -setnetworkserviceenabled Wi-Fi off 2>/dev/null\n"
                . "  report 'wifioff' true \"Wi-Fi disabled (\$W)\"\n"
                . "else\n"
                . "  report 'wifioff' true 'No Wi-Fi interface found'\n"
                . "fi",
            'windows_script' => "# Wi-Fi adapter off\n"
                . "try { Disable-NetAdapter -Name 'Wi-Fi' -Confirm:\$false -ErrorAction Stop; Report 'wifioff' \$true 'Wi-Fi disabled' }\n"
                . "catch { Report 'wifioff' \$true 'No Wi-Fi adapter found (or already off)' }",
            'linux_script' => "# Wi-Fi radio off\n"
                . "command -v nmcli >/dev/null && nmcli radio wifi off && report 'wifioff' true 'Wi-Fi radio off' \\\n"
                . "  || report 'wifioff' false 'Disable Wi-Fi manually'",
        ],
        [
            'command' => 'printer', 'params' => 'name, ip',
            'label' => 'Add a network printer (driverless IPP) — /printer <name> <ip>',
            'mac_script' => "# Add printer {name} at {ip} via IPP (driverless); old models may still need the vendor driver\n"
                . "lpadmin -p \"{name}\" -E -v \"ipp://{ip}\" -m everywhere 2>/dev/null \\\n"
                . "  && report 'printer' true 'Printer {name} ({ip}) added' \\\n"
                . "  || report 'printer' false 'Add {name} ({ip}) manually - vendor driver may be required'",
            'windows_script' => "# Add printer {name} at {ip}\n"
                . "try {\n"
                . "  Add-PrinterPort -Name \"IP_{ip}\" -PrinterHostAddress \"{ip}\" -ErrorAction SilentlyContinue\n"
                . "  Add-Printer -Name \"{name}\" -PortName \"IP_{ip}\" -DriverName 'Microsoft IPP Class Driver' -ErrorAction Stop\n"
                . "  Report 'printer' \$true 'Printer {name} ({ip}) added'\n"
                . "} catch { Report 'printer' \$false \"Add {name} manually: \$(\$_.Exception.Message)\" }",
            'linux_script' => "# Add printer {name} at {ip} via IPP (CUPS)\n"
                . "lpadmin -p \"{name}\" -E -v \"ipp://{ip}\" -m everywhere 2>/dev/null \\\n"
                . "  && report 'printer' true 'Printer {name} ({ip}) added' \\\n"
                . "  || report 'printer' false 'Add {name} ({ip}) manually'",
        ],
    ];

    public function up(): void
    {
        foreach (self::COMMANDS as $c) {
            DB::table('script_snippets')->insert($c + [
                'company_id' => null, 'active' => true,
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
