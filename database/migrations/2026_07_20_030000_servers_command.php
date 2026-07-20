<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * /servers smb://host/share [smb://host2/share …] — mount each file server and
 * make it stick: a login item on the Mac (reconnects at login), a persistent
 * mapped drive on Windows. The SOP names the company's servers explicitly.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('script_snippets')->insert([
            'company_id' => null, 'command' => 'servers', 'params' => null,
            'label' => 'Mount file servers and reconnect at login — /servers smb://host/share …',
            'mac_script' => "# Mount each server for the console user and add it as a login item (reconnects at login)\n"
                . "U=\$(stat -f%Su /dev/console)\n"
                . "for SRV in {*}; do\n"
                . "  if sudo -u \"\$U\" osascript -e \"mount volume \\\"\$SRV\\\"\" >/dev/null 2>&1; then\n"
                . "    V=\"/Volumes/\$(basename \"\$SRV\")\"\n"
                . "    sudo -u \"\$U\" osascript -e \"tell application \\\"System Events\\\" to make login item at end with properties {path:\\\"\$V\\\", hidden:false}\" >/dev/null 2>&1\n"
                . "    report 'servers' true \"Mounted \$SRV + login item\"\n"
                . "  else\n"
                . "    report 'servers' false \"Mount \$SRV failed - add it in Finder (Cmd-K) and keep it in the favorites list\"\n"
                . "  fi\n"
                . "done",
            'windows_script' => "# Map each server persistently (reconnects at logon)\n"
                . "foreach (\$srv in '{*}'.Split(' ')) {\n"
                . "  if (-not \$srv) { continue }\n"
                . "  \$unc = \$srv -replace '^smb://', '\\\\' -replace '/', '\\'\n"
                . "  cmd /c \"net use * \$unc /persistent:yes\" | Out-Null\n"
                . "  if (\$LASTEXITCODE -eq 0) { Report 'servers' \$true \"Mapped \$unc\" } else { Report 'servers' \$false \"Map \$unc manually\" }\n"
                . "}",
            'linux_script' => "# Persistent mounts belong in fstab - record it as done by hand\n"
                . "report 'servers' false 'Add the file servers to /etc/fstab (cifs) manually: {*}'",
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('script_snippets')->where('command', 'servers')->whereNull('company_id')->delete();
    }
};
