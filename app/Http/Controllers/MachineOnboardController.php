<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Login;
use App\Models\OnboardingTemplate;
use App\Models\Task;
use App\Support\Contracts\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * https://…/onboard — opened ON the machine being set up.
 *
 * The normal login page is the gate (only can_login people pass). Generating a
 * script issues the asset tag, creates the device + its runbook task project,
 * and embeds a device-scoped token so the script can report back: step ticks
 * and the BitLocker recovery key, straight into the registry. The script is
 * idempotent — run it again after the join-reboot and it continues.
 */
class MachineOnboardController extends Controller
{
    public function page(): \Inertia\Response
    {
        $companyId = app(TenantResolver::class)->id();
        $variants = OnboardingTemplate::query()
            ->where('company_id', $companyId)->where('kind', 'imaging')
            ->get(['variant', 'name', 'steps'])
            ->map(fn ($t) => [
                'variant' => $t->variant, 'name' => $t->name,
                // The runbook IS the recipe: what its /install and /vpn tokens resolve to,
                // and which MDM its /mdm token enrolls into.
                'installs' => self::recipe(json_decode($t->steps, true)['steps'] ?? [], $companyId),
                'mdm' => self::mdm(json_decode($t->steps, true)['steps'] ?? []),
            ]);

        return Inertia::render('Onboard/Machine', [
            'variants' => $variants,
            'types' => DeviceType::query()->where('active', true)->ordered()->get(['id', 'code', 'name']),
        ]);
    }

    /** Issue tag, create device + runbook project, return the bootstrap script. */
    public function generate(Request $request): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $companyId = app(TenantResolver::class)->id();
        abort_if(! $companyId, 422, 'Pick a company first.');

        $data = $request->validate([
            'variant' => 'required|string|max:100',
            'device_type_id' => 'required|integer|exists:device_types,id',
            'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'serial_num' => 'nullable|string|max:255',
            // Files from the installers share to pull + set up on this machine
            // (software installers and VPN configs).
            'installers' => 'nullable|array',
            'installers.*' => 'string|max:500',
        ]);

        $template = OnboardingTemplate::query()->where('company_id', $companyId)
            ->where('kind', 'imaging')->where('variant', $data['variant'])->first();
        abort_if(! $template, 422, 'No runbook for that variant.');
        $steps = json_decode($template->steps, true)['steps'] ?? [];

        [$device, $project] = DB::transaction(function () use ($data, $companyId, $steps, $template) {
            $device = Device::create([
                'company_id' => $companyId,
                'device_type_id' => $data['device_type_id'],
                'brand' => $data['brand'] ?? null, 'model' => $data['model'] ?? null,
                'serial_num' => $data['serial_num'] ?? null,
                'active' => true,
            ]);   // tag issued in Device::booted

            $monday = Carbon::now()->startOfWeek(Carbon::MONDAY)->toDateString();
            $ord = (int) (Task::query()->max('ord') + 1);
            $project = Task::create([
                'title' => "Set up {$device->asset_tag}",
                'is_project' => true, 'status' => 'In progress',
                'week' => $monday, 'origin' => Carbon::now()->toDateString(),
                'notes' => trim("{$template->name} · " . implode(' ', array_filter([$data['brand'] ?? '', $data['model'] ?? '', $data['serial_num'] ?? '']))),
                'assigned_to' => auth()->id(), 'ord' => $ord++,
            ]);

            foreach ($steps as $step) {
                $card = [];
                $refSteps = [];
                foreach (['why' => 'Why', 'instructions' => 'How', 'done_when' => 'Done when', 'record' => 'Record'] as $k => $label) {
                    if (! empty($step[$k])) {
                        // /references resolve NOW — the referenced runbook's current
                        // steps expand as subtasks, so stale copies can't exist.
                        [$resolved, $extra] = \App\Support\RunbookRefs::resolve($step[$k], $companyId, request()->getSchemeAndHttpHost());
                        $card[] = "{$label}: {$resolved}";
                        $refSteps = array_merge($refSteps, $extra);
                    }
                }
                $t = Task::create([
                    'title' => $step['title'], 'notes' => implode("\n", $card),
                    'parent_id' => $project->id, 'week' => $monday, 'origin' => Carbon::now()->toDateString(),
                    'planned_start' => Carbon::now()->toDateString(), 'due_date' => Carbon::now()->toDateString(),
                    'assigned_to' => auth()->id(), 'ord' => $ord++,
                ]);
                foreach ($refSteps as $sub) {
                    $cardR = [];
                    foreach (['why' => 'Why', 'instructions' => 'How', 'done_when' => 'Done when', 'record' => 'Record'] as $k => $label) {
                        if (! empty($sub[$k])) $cardR[] = "{$label}: {$sub[$k]}";
                    }
                    Task::create([
                        'title' => $sub['title'], 'notes' => implode("\n", $cardR),
                        'parent_id' => $t->id, 'week' => $monday, 'origin' => Carbon::now()->toDateString(),
                        'planned_start' => Carbon::now()->toDateString(), 'due_date' => Carbon::now()->toDateString(),
                        'assigned_to' => auth()->id(), 'ord' => $ord++,
                    ]);
                }
                foreach ($step['subtasks'] ?? [] as $sub) {
                    $cardS = [];
                    foreach (['why' => 'Why', 'instructions' => 'How', 'done_when' => 'Done when', 'record' => 'Record'] as $k => $label) {
                        if (! empty($sub[$k])) $cardS[] = "{$label}: {$sub[$k]}";
                    }
                    Task::create([
                        'title' => $sub['title'], 'notes' => implode("\n", $cardS),
                        'parent_id' => $t->id, 'week' => $monday, 'origin' => Carbon::now()->toDateString(),
                        'planned_start' => Carbon::now()->toDateString(), 'due_date' => Carbon::now()->toDateString(),
                        'assigned_to' => auth()->id(), 'ord' => $ord++,
                    ]);
                }
            }

            return [$device, $project];
        });

        // Device-scoped token: everything the report endpoints will trust.
        $token = Crypt::encryptString(json_encode([
            'device_id' => $device->id, 'project_id' => $project->id,
            'exp' => Carbon::now()->addDays(3)->timestamp,
        ]));

        Log::info('machine.onboard.generated', ['device' => $device->id, 'tag' => $device->asset_tag, 'by' => auth()->id()]);

        // The runbook is the recipe: the /install and /vpn tokens in its steps ARE
        // the fetch list. Any explicitly passed installers are merged in as extras.
        $recipe = self::recipe($steps, $companyId);
        $files = array_values(array_unique(array_merge(
            array_column($recipe, 'relative_path'), $data['installers'] ?? []
        )));

        return response()->json([
            'asset_tag' => $device->asset_tag,
            'project_id' => $project->id,
            'recipe' => $recipe,
            'script' => $this->script($device, $token, $request->getSchemeAndHttpHost(), $data['variant'], $files, self::mdm($steps), self::mdmProfile($steps, $companyId)),
        ], 201);
    }

    /**
     * The SOP is the recipe. Pull every /install and /vpn token out of the runbook's
     * steps (and subtasks) and resolve each to a real file in the installers catalog —
     * that resolved list is what the bootstrap script fetches and installs. Typing the
     * shorthand in the SOP is how you change what a machine gets; the wizard just reads it.
     *
     * @return array<int, array{name:string, relative_path:string, platform:string, kind:string}>
     */
    /** Flatten every text field of the steps (and subtasks) into one blob for scanning. */
    private static function stepsText(array $steps): string
    {
        $texts = [];
        $walk = function ($list) use (&$walk, &$texts) {
            foreach ($list as $s) {
                foreach (['title', 'why', 'instructions', 'done_when', 'record'] as $k) {
                    if (! empty($s[$k])) $texts[] = $s[$k];
                }
                if (! empty($s['subtasks'])) $walk($s['subtasks']);
            }
        };
        $walk($steps);
        return implode("\n", $texts);
    }

    /** The MDM the runbook enrolls into: the first /mdm token, lowercased ('' if none). */
    public static function mdm(array $steps): string
    {
        return preg_match('~/mdm\s+([a-z0-9 ]+?)\s*(?:$|\n|/)~im', self::stepsText($steps), $m)
            ? strtolower(trim($m[1]))
            : '';
    }

    /**
     * The enrollment profile (.mobileconfig) on the share for the /mdm system, if one is
     * there — keeps the enrollment credential off the script: the script fetches this file
     * and installs it instead of hitting the MDM's URL with a username/password. Matched by
     * the system name, an "enroll" hint, or a JAMF/MDM folder. Returns relative_path or null.
     */
    public static function mdmProfile(array $steps, int $companyId): ?string
    {
        $mdm = self::mdm($steps);
        if ($mdm === '') return null;
        $first = strtolower(preg_split('/\s+/', $mdm)[0] ?? $mdm);
        $file = DB::table('installers')->get()->first(function ($i) use ($first) {
            if (! preg_match('/\.mobileconfig$/i', $i->name)) return false;
            $hay = strtolower($i->name . ' ' . $i->relative_path);
            return str_contains($hay, $first) || str_contains($hay, 'enroll') || str_contains($hay, 'mdm');
        });
        return $file?->relative_path;
    }

    public static function recipe(array $steps, int $companyId): array
    {
        $blob = self::stepsText($steps);

        if (! preg_match_all('~/(install|vpn)\s+([^\n/]+)~i', $blob, $m, PREG_SET_ORDER)) {
            return [];
        }

        $catalog = DB::table('installers')->get();
        $vpnRe = '/\.(ovpn|ovpn12|mobileconfig|tblk|visc|visz|conf|wg)$/i';
        $plat = ['mac' => 'Mac', 'macos' => 'Mac', 'osx' => 'Mac', 'apple' => 'Mac', 'win' => 'Windows', 'windows' => 'Windows', 'pc' => 'Windows'];
        $stripExt = fn ($n) => strtolower(preg_replace('/\.[a-z0-9]+$/i', '', $n));
        $out = [];
        foreach ($m as $tok) {
            $type = strtolower($tok[1]);
            $arg = trim($tok[2]);
            $platform = null;
            if ($type === 'install') {
                $words = preg_split('/\s+/', $arg);
                if (isset($plat[strtolower($words[0])])) { $platform = $plat[strtolower($words[0])]; array_shift($words); }
                $arg = trim(implode(' ', $words));
            }
            if ($arg === '') continue;
            $q = strtolower($arg);
            $firstWord = preg_split('/\s+/', $q)[0] ?? '';

            // Best match: the catalog file whose extension-stripped name overlaps the typed
            // argument — bidirectional (so "Office" shorthand and picker-inserted full names
            // both resolve) and also on the leading word (so a distinctive shorthand like a
            // VPN's "1197" resolves even with trailing prose). Longest overlap wins.
            $best = null; $bestLen = -1;
            foreach ($catalog as $i) {
                $isVpn = (bool) preg_match($vpnRe, $i->name);
                if ($type === 'vpn' ? ! $isVpn : $isVpn) continue;
                if ($type === 'install' && $platform && $i->platform !== $platform) continue;
                $fn = $stripExt($i->name);
                if ($fn === '') continue;
                $hit = str_contains($q, $fn) || str_contains($fn, $q)
                    || (strlen($firstWord) >= 3 && str_contains($fn, $firstWord));
                if ($hit && strlen($fn) > $bestLen) { $best = $i; $bestLen = strlen($fn); }
            }
            if ($best) $out[$best->relative_path] = ['name' => $best->name, 'relative_path' => $best->relative_path,
                'platform' => $best->platform, 'kind' => $type === 'vpn' ? 'vpn' : 'software'];
        }
        return array_values($out);
    }

    /** Step tick from the running script. Token-authenticated, no session. */
    public function report(Request $request): JsonResponse
    {
        $ctx = $this->context($request);
        $data = $request->validate(['step' => 'required|string|max:255', 'ok' => 'required|boolean', 'note' => 'nullable|string|max:2000']);

        $task = Task::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q->where('parent_id', $ctx['project_id'])
                ->orWhereIn('parent_id', Task::query()->withoutGlobalScopes()->where('parent_id', $ctx['project_id'])->pluck('id')))
            ->where('title', 'like', '%' . $data['step'] . '%')->first();

        if ($task) {
            $note = trim(($task->notes ?? '') . "\n\n" . ($data['ok'] ? 'Bootstrap script: done. ' : 'Bootstrap script FAILED: ') . ($data['note'] ?? ''));
            $task->update($data['ok']
                ? ['done' => true, 'pct' => 100, 'completed_at' => Carbon::now(), 'notes' => $note]
                : ['notes' => $note]);
        }
        Log::info('machine.onboard.report', ['device' => $ctx['device_id'], 'step' => $data['step'], 'ok' => $data['ok']]);

        return response()->json(['ok' => true, 'matched' => (bool) $task]);
    }

    /** BitLocker recovery key, straight into the registry against the machine. */
    public function storeKey(Request $request): JsonResponse
    {
        $ctx = $this->context($request);
        $data = $request->validate(['key' => 'required|string|max:255']);

        // BitLocker: 8 groups of 6 digits. FileVault: 6 groups of 4 alphanumerics.
        // Refuse anything that isn't one of the two — no garbage in the registry.
        $key = trim($data['key']);
        $kind = preg_match('/^(\d{6}-){7}\d{6}$/', $key) ? 'BitLocker'
            : (preg_match('/^([A-Z0-9]{4}-){5}[A-Z0-9]{4}$/i', $key) ? 'FileVault' : null);
        abort_unless($kind, 422, 'Not a BitLocker or FileVault recovery key.');

        $device = Device::query()->withoutGlobalScopes()->findOrFail($ctx['device_id']);
        Login::create([
            'company_id' => $device->company_id,
            'login_name' => "{$kind} Recovery — {$device->asset_tag}",
            'login_id' => $device->asset_tag,
            'login_pass' => $key,
            'device_id' => $device->deviceID,
            'sharing' => 'service',
            'is_restricted' => 1, 'is_active' => 1,
            'notes' => 'Escrowed automatically by the bootstrap script.',
        ]);
        Log::info('machine.onboard.key_escrowed', ['device' => $ctx['device_id']]);

        return response()->json(['ok' => true]);
    }

    private function context(Request $request): array
    {
        try {
            $ctx = json_decode(Crypt::decryptString((string) $request->query('t')), true);
        } catch (\Throwable) {
            abort(403);
        }
        abort_if(! is_array($ctx) || ($ctx['exp'] ?? 0) < time(), 403);
        return $ctx;
    }

    /** The idempotent bootstrap script — platform decided by the runbook variant. */
    private function script(Device $device, string $token, string $base, string $variant = '', array $installers = [], string $mdm = '', ?string $mdmProfile = null): string
    {
        $tag = $device->asset_tag;
        $domain = $device->company?->local_domain ?: 'your.domain';
        $repo = rtrim($device->company?->installers_url ?: '', '/');   // Web Station base for curl

        // Build the fetch+install block for the chosen files (software + VPN configs).
        $macFetch = '';
        $winFetch = '';
        foreach ($installers as $rel) {
            $rel = ltrim($rel, '/');
            if ($rel === '' || ! $repo) continue;
            $url = $repo . '/' . str_replace('%2F', '/', rawurlencode($rel));
            $file = basename($rel);
            $macFetch .= self::macInstall($url, $file);
            $winFetch .= self::winInstall($url, $file);
        }

        // MDM enrollment block from the SOP's /mdm token (empty if none). If an enrollment
        // profile is on the share, the script fetches + installs it (no credentials).
        $profileUrl = '';
        $profileFile = '';
        if ($mdmProfile && $repo) {
            $rel = ltrim($mdmProfile, '/');
            $profileUrl = $repo . '/' . str_replace('%2F', '/', rawurlencode($rel));
            $profileFile = basename($rel);
        }
        $macMdm = self::macMdm($mdm, $profileUrl, $profileFile);
        $winMdm = self::winMdm($mdm);

        if (preg_match('/mac/i', $variant)) {
            $sh = <<<'SH'
#!/bin/zsh
# AssetMost bootstrap — __TAG__ — run with: sudo zsh ./bootstrap.sh  (safe to re-run)
T='__TOKEN__'
A='__BASE__'
report() { curl -sk -X POST "$A/onboard/report?t=$T" -H 'Content-Type: application/json' -d "{\"step\":\"$1\",\"ok\":$2,\"note\":\"$3\"}" >/dev/null 2>&1; }

# 1. Names = asset tag
scutil --set ComputerName '__TAG__'; scutil --set HostName '__TAG__'; scutil --set LocalHostName '__TAG__'
report 'inventory' true 'Renamed to __TAG__'

# 2. FileVault on, recovery key escrowed to the registry (no paper, no txt)
if fdesetup status | grep -q 'Off'; then
  KEY=$(fdesetup enable -user "$SUDO_USER" | grep -oE "([A-Z0-9]{4}-){5}[A-Z0-9]{4}")
  if [ -n "$KEY" ]; then
    curl -sk -X POST "$A/onboard/key?t=$T" -H 'Content-Type: application/json' -d "{\"key\":\"$KEY\"}" >/dev/null 2>&1 \
      && report 'FileVault' true 'Key escrowed to the registry' \
      || report 'FileVault' false 'Key capture failed - escrow manually'
  else
    report 'FileVault' false 'fdesetup gave no key - enable manually and escrow'
  fi
else
  report 'FileVault' true 'Already on'
fi

# 3. MDM enrollment (from the runbook's /mdm)
__MDM__

# 4. Pull + set up software and VPN configs from the installers share
__FETCH__

# 5. Updates
softwareupdate --install --all 2>/dev/null
report 'updates' true 'softwareupdate pass attempted'

echo 'Bootstrap done - finish the remaining checklist in AssetMost (hardening).'
SH;
            return str_replace(['__TAG__', '__TOKEN__', '__BASE__', '__MDM__', '__FETCH__'], [$tag, $token, $base, $macMdm, $macFetch ?: '# (no installers selected)'], $sh);
        }

        $ps = <<<PS
# AssetMost bootstrap — {$tag} — safe to re-run; it continues where it left off.
# Run in an elevated PowerShell. Windows PowerShell 5.1 compatible.
\$T = '{$token}'
\$A = '{$base}'
[System.Net.ServicePointManager]::ServerCertificateValidationCallback = { \$true }
function Report(\$s, \$ok, \$n = '') {
    try { Invoke-RestMethod "\$A/onboard/report?t=\$T" -Method POST -ContentType 'application/json' -Body (@{ step = \$s; ok = \$ok; note = \$n } | ConvertTo-Json) | Out-Null } catch {}
}

# 1. Hostname = asset tag
if (\$env:COMPUTERNAME -ne '{$tag}') {
    Rename-Computer -NewName '{$tag}' -Force
    Report 'inventory' \$true 'Renamed to {$tag}'
}

# 2. Join the domain (asks for the join account; reboots when done — run me again after)
if ((Get-WmiObject Win32_ComputerSystem).Domain -notlike '{$domain}*') {
    \$cred = Get-Credential -Message 'Domain join account for {$domain}'
    try {
        Add-Computer -DomainName '{$domain}' -Credential \$cred -Force
        Report 'Join domain' \$true 'Joined {$domain}'
        Write-Host 'Joined. Rebooting — RUN THIS SCRIPT AGAIN afterwards.' -ForegroundColor Yellow
        Restart-Computer -Force; exit
    } catch { Report 'Join domain' \$false \$_.Exception.Message; throw }
}

# 3. BitLocker on, key escrowed to the registry (no txt files, ever)
\$blv = Get-BitLockerVolume -MountPoint C: -ErrorAction SilentlyContinue
if (\$blv -and \$blv.ProtectionStatus -ne 'On') {
    Enable-BitLocker -MountPoint C: -RecoveryPasswordProtector -SkipHardwareTest
    \$blv = Get-BitLockerVolume -MountPoint C:
}
\$key = (\$blv.KeyProtector | Where-Object KeyProtectorType -eq 'RecoveryPassword' | Select-Object -First 1).RecoveryPassword
if (\$key) {
    try {
        Invoke-RestMethod "\$A/onboard/key?t=\$T" -Method POST -ContentType 'application/json' -Body (@{ key = \$key } | ConvertTo-Json) | Out-Null
        Report 'BitLocker' \$true 'Key escrowed to the registry'
    } catch { Report 'BitLocker' \$false \$_.Exception.Message }
}

# 4. MDM enrollment (from the runbook's /mdm)
__MDM__

# 5. Pull + set up software and VPN configs from the installers share
__FETCH__

# 6. Updates (best effort; finish in Settings if needed)
try { Install-Module PSWindowsUpdate -Force -Scope CurrentUser -ErrorAction Stop; Get-WindowsUpdate -AcceptAll -Install -IgnoreReboot } catch {}
Report 'Apply all updates' \$true 'Windows Update pass attempted'

Write-Host 'Bootstrap done — finish the remaining checklist in AssetMost.' -ForegroundColor Green
PS;
        return str_replace(['__MDM__', '__FETCH__'], [$winMdm, $winFetch ?: '# (no installers selected)'], $ps);
    }

    /**
     * macOS MDM enrollment from the /mdm token. Preferred path: fetch the enrollment
     * profile that lives on the share and open it for the user to approve (keeps the
     * enrollment credential out of the script entirely). Fallback when no profile is on
     * the share: ABM/DEP re-enrollment (`profiles renew`), which needs no file.
     */
    private static function macMdm(string $mdm, string $profileUrl = '', string $profileFile = ''): string
    {
        if ($mdm === '') return '# (no /mdm in the runbook)';
        $name = ucwords($mdm);

        if ($profileUrl !== '') {
            return <<<SH
# Enroll into {$name} - fetch the enrollment profile from the share and open it for approval
if curl -fsSL "{$profileUrl}" -o "/tmp/{$profileFile}" 2>/dev/null; then
  open "/tmp/{$profileFile}"
  report 'MDM enrollment' true '{$name} profile staged - approve it in System Settings > Profiles'
else
  report 'MDM enrollment' false 'Could not fetch the {$name} enrollment profile from the share'
fi
SH;
        }

        return <<<SH
# Enroll into {$name} - ABM/DEP-assigned devices renew here; or drop the enrollment .mobileconfig on the share
if profiles renew -type enrollment 2>/dev/null; then
  report 'MDM enrollment' true 'Enrollment renewed ({$name})'
else
  report 'MDM enrollment' false 'Auto-enroll failed - add the {$name} enrollment profile to the share'
fi
SH;
    }

    /**
     * Windows MDM enrollment from the /mdm token. Intune uses Azure AD auto-enrollment
     * (deviceenroller); other MDMs need their own agent/URL, so we emit a clear note.
     */
    private static function winMdm(string $mdm): string
    {
        if ($mdm === '') return '# (no /mdm in the runbook)';
        if (str_contains($mdm, 'intune')) {
            return <<<'PS'
# Enroll into Intune - Azure AD auto-enrollment (the device must be Azure AD joined)
try {
    Start-Process -FilePath "$env:WINDIR\System32\deviceenroller.exe" -ArgumentList '/c','/AutoEnrollMDM' -Wait -NoNewWindow
    Report 'MDM enrollment' $true 'AutoEnrollMDM triggered (Intune)'
} catch { Report 'MDM enrollment' $false $_.Exception.Message }
PS;
        }
        $name = ucwords($mdm);

        return "# Enroll into {$name} - install its agent / enrollment profile from your {$name} console (no standard silent command)\n"
            . "Report 'MDM enrollment' \$false 'Enroll {$name} manually'";
    }

    /**
     * Mac curl+install for one file from the installers share. Handles the types
     * that live there: .pkg (installer), .dmg (mount, copy .app, unmount),
     * .ovpn (import into Viscosity — the fleet's VPN client), .app inside a .zip.
     */
    private static function macInstall(string $url, string $file): string
    {
        $u = addslashes($url);
        $f = addslashes($file);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $body = match ($ext) {
            'pkg' => "sudo installer -pkg \"\$T\" -target / && ok=1",
            'dmg' => "M=\$(hdiutil attach \"\$T\" -nobrowse | grep -o '/Volumes/.*' | head -1); "
                   . "cp -R \"\$M\"/*.app /Applications/ 2>/dev/null && ok=1; hdiutil detach \"\$M\" >/dev/null 2>&1",
            'ovpn' => "mkdir -p \"/Users/\$SUDO_USER/Library/Application Support/Viscosity/OpenVPN\"; "
                    . "open \"\$T\" && ok=1   # Viscosity imports the config on open",
            default => "ok=1   # downloaded to \$T; set up manually",
        };
        return <<<SH

# {$file}
T="/tmp/{$f}"; ok=0
if curl -fsSL "{$u}" -o "\$T"; then
  {$body}
fi
[ "\$ok" = 1 ] && report 'software' true 'Installed {$f}' || report 'software' false 'Failed {$f}'
rm -f "\$T" 2>/dev/null

SH;
    }

    /** Windows curl+install for one file: .exe/.msi silent, .ovpn into OpenVPN config. */
    private static function winInstall(string $url, string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $body = match ($ext) {
            'msi' => "Start-Process msiexec -ArgumentList '/i',\"\$T\",'/qn' -Wait; \$ok=\$true",
            'exe' => "Start-Process \"\$T\" -ArgumentList '/S','/silent','/quiet' -Wait; \$ok=\$true",
            'ovpn' => "Copy-Item \"\$T\" 'C:\\Program Files\\OpenVPN\\config\\' -Force; \$ok=\$true",
            default => "\$ok=\$true   # downloaded; set up manually",
        };
        return <<<PS

# {$file}
\$T = "\$env:TEMP\\{$file}"; \$ok = \$false
try { Invoke-WebRequest "{$url}" -OutFile \$T -UseBasicParsing; {$body} } catch {}
if (\$ok) { Report 'software' \$true 'Installed {$file}' } else { Report 'software' \$false 'Failed {$file}' }
Remove-Item \$T -ErrorAction SilentlyContinue

PS;
    }
}
