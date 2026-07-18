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
            ->get(['variant', 'name'])
            ->map(fn ($t) => ['variant' => $t->variant, 'name' => $t->name]);

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
                foreach (['why' => 'Why', 'instructions' => 'How', 'done_when' => 'Done when', 'record' => 'Record'] as $k => $label) {
                    if (! empty($step[$k])) $card[] = "{$label}: {$step[$k]}";
                }
                $t = Task::create([
                    'title' => $step['title'], 'notes' => implode("\n", $card),
                    'parent_id' => $project->id, 'week' => $monday, 'origin' => Carbon::now()->toDateString(),
                    'planned_start' => Carbon::now()->toDateString(), 'due_date' => Carbon::now()->toDateString(),
                    'assigned_to' => auth()->id(), 'ord' => $ord++,
                ]);
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

        return response()->json([
            'asset_tag' => $device->asset_tag,
            'project_id' => $project->id,
            'script' => $this->script($device, $token, $request->getSchemeAndHttpHost()),
        ], 201);
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

        // 8 groups of 6 digits — refuse anything that isn't a BitLocker key.
        abort_unless(preg_match('/^(\d{6}-){7}\d{6}$/', trim($data['key'])), 422, 'Not a BitLocker recovery key.');

        $device = Device::query()->withoutGlobalScopes()->findOrFail($ctx['device_id']);
        Login::create([
            'company_id' => $device->company_id,
            'login_name' => "BitLocker Recovery — {$device->asset_tag}",
            'login_id' => $device->asset_tag,
            'login_pass' => trim($data['key']),
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

    /** The idempotent bootstrap script — re-run it after the join reboot. */
    private function script(Device $device, string $token, string $base): string
    {
        $tag = $device->asset_tag;
        $domain = $device->company?->local_domain ?: 'your.domain';

        return <<<PS
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

# 4. Updates (best effort; finish in Settings if needed)
try { Install-Module PSWindowsUpdate -Force -Scope CurrentUser -ErrorAction Stop; Get-WindowsUpdate -AcceptAll -Install -IgnoreReboot } catch {}
Report 'Apply all updates' \$true 'Windows Update pass attempted'

Write-Host 'Bootstrap done — finish the remaining checklist in AssetMost.' -ForegroundColor Green
PS;
    }
}
