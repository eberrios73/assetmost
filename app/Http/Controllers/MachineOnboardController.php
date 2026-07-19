<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Login;
use App\Models\Task;
use App\Models\User;
use App\Support\Access;
use App\Support\Contracts\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
        // The active device workflows — the machine picks from these runbooks.
        $workflows = \App\Models\DocPage::query()
            ->where('workflow_type', 'device')->where('workflow_active', true)
            ->orderByRaw('form_factor IS NULL')->orderBy('form_factor')
            ->get(['id', 'title', 'form_factor', 'workflow_steps'])
            ->map(function ($p) use ($companyId) {
                $steps = json_decode($p->workflow_steps ?? '', true)['steps'] ?? [];
                return [
                    'id' => $p->id, 'name' => $p->title, 'form_factor' => $p->form_factor,
                    // The runbook IS the recipe: what its /install and /vpn tokens resolve to,
                    // and which MDM its /mdm token enrolls into.
                    'installs' => self::recipe($steps, $companyId),
                    'mdm' => self::mdm($steps),
                ];
            });

        return Inertia::render('Onboard/Machine', [
            'workflows' => $workflows,
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
            'workflow_id' => 'required|integer|exists:doc_pages,id',
            'device_type_id' => 'required|integer|exists:device_types,id',
            'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'serial_num' => 'nullable|string|max:255',
            // Files from the installers share to pull + set up on this machine
            // (software installers and VPN configs).
            'installers' => 'nullable|array',
            'installers.*' => 'string|max:500',
        ]);

        $template = \App\Models\DocPage::query()
            ->where('workflow_type', 'device')->where('workflow_active', true)
            ->find($data['workflow_id']);
        abort_if(! $template, 422, 'No such device runbook.');
        $decoded = json_decode($template->workflow_steps ?? '', true) ?: [];
        $steps = $decoded['steps'] ?? [];
        $stepsMeta = $decoded['meta'] ?? [];
        // The SOP header's OS decides the script platform — never guessed. Mobile
        // form factors are checklist-only, so they pass without an OS row.
        $os = trim((string) ($stepsMeta['os'] ?? ''));
        abort_if($os === '' && ! preg_match('/mobile|other/i', (string) $template->form_factor), 422,
            'This SOP has no OS in its header — open it, type /sop, and pick one.');
        $data['variant'] = self::osVariant($os !== '' ? $os : null, $template->form_factor);

        [$device, $project] = DB::transaction(function () use ($data, $companyId, $steps, $stepsMeta, $template) {
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
                'notes' => trim("{$template->title} · " . implode(' ', array_filter([$data['brand'] ?? '', $data['model'] ?? '', $data['serial_num'] ?? '']))),
                'assigned_to' => auth()->id(), 'ord' => $ord++,
            ]);

            // The SOP header's Tools and Safety rows run ahead of the procedure.
            if (! empty($stepsMeta['tools'])) {
                Task::create([
                    'title' => 'Gather tools and materials', 'notes' => $stepsMeta['tools'],
                    'parent_id' => $project->id, 'week' => $monday, 'origin' => Carbon::now()->toDateString(),
                    'planned_start' => Carbon::now()->toDateString(), 'due_date' => Carbon::now()->toDateString(),
                    'assigned_to' => auth()->id(), 'ord' => $ord++,
                ]);
            }
            if (! empty($stepsMeta['safety'])) {
                $safety = Task::create([
                    'title' => 'Safety precautions', 'notes' => 'From the SOP header — complete before the procedure.',
                    'parent_id' => $project->id, 'week' => $monday, 'origin' => Carbon::now()->toDateString(),
                    'planned_start' => Carbon::now()->toDateString(), 'due_date' => Carbon::now()->toDateString(),
                    'assigned_to' => auth()->id(), 'ord' => $ord++,
                ]);
                foreach (preg_split('/\n+/', $stepsMeta['safety']) as $line) {
                    if (trim($line) === '') continue;
                    Task::create([
                        'title' => trim($line),
                        'parent_id' => $safety->id, 'week' => $monday, 'origin' => Carbon::now()->toDateString(),
                        'planned_start' => Carbon::now()->toDateString(), 'due_date' => Carbon::now()->toDateString(),
                        'assigned_to' => auth()->id(), 'ord' => $ord++,
                    ]);
                }
            }

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
                // /form tokens stamp the task; the Tasks screen renders the add form from it.
                if ($fk = WorkflowController::formKind($step)) {
                    $card[] = "Form: {$fk} · co:{$companyId}";
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

        // Audit trail: tell the system admins a device was just onboarded. Every legit
        // enrollment leaves this record, so a device in the MDM with no matching email
        // is suspect. Best-effort — a mail problem must never fail the onboarding.
        self::notifyAdmins($device, $data, $companyId, self::mdm($steps));

        // The runbook is the recipe — shown to the wizard; the script itself is
        // assembled per-step by sopBody (every block from an explicit token).
        $recipe = self::recipe($steps, $companyId);

        $base = $request->getSchemeAndHttpHost();
        $platform = strtolower(explode(' ', $data['variant'])[0]);   // mac | windows | linux | mobile
        $body = in_array($platform, ['mac', 'windows', 'linux'], true)
            ? self::sopBody($steps, $platform, $companyId, [
                'ASSET_TAG' => $device->asset_tag, 'BASE_URL' => $base, 'TOKEN' => $token,
                'REPO' => rtrim($device->company?->installers_url ?? '', '/'),
                'DOMAIN' => $device->company?->domain, 'LOCAL_DOMAIN' => $device->company?->local_domain,
                // Company credentials — inserted at generation, never in docs. The
                // domain-join credential is a LOGIN in the registry the company
                // points at; rotate it there and the next script picks it up.
                'LOCAL_ADMIN_USER' => $device->company?->local_admin_user,
                'LOCAL_ADMIN_PASS' => $device->company?->local_admin_pass,
                'DOMAIN_JOIN_USER' => ($dj = \App\Models\Login::withoutGlobalScopes()->find($device->company?->domain_join_login_id))?->login_id,
                'DOMAIN_JOIN_PASS' => $dj?->login_pass,
            ])
            : '';

        return response()->json([
            'asset_tag' => $device->asset_tag,
            'project_id' => $project->id,
            'recipe' => $recipe,
            'script' => $this->script($device, $token, $base, $data['variant'], $body),
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
    /**
     * Registry commands used by the SOP: scan the steps for /command tokens from
     * the script_snippets registry, map the words after each command onto its
     * declared params ({name}, {1}.., {*}), substitute the context vars, and
     * return the assembled per-platform blocks in SOP order.
     *
     * @return array{mac: string, windows: string, linux: string}
     */
    public static function snippetBlocks(array $steps, int $companyId, array $ctx = []): array
    {
        $blob = self::stepsText($steps);
        $out = ['mac' => [], 'windows' => [], 'linux' => []];
        $found = [];
        foreach (self::registry($companyId) as $s) {
            if (! preg_match_all('~/' . preg_quote($s->command, '~') . '\b([^\n/]*)~i', $blob, $m, PREG_OFFSET_CAPTURE)) continue;
            foreach ($m[1] as $hit) {
                $found[] = ['snippet' => $s, 'args' => trim($hit[0]), 'at' => $hit[1]];
            }
        }
        usort($found, fn ($a, $b) => $a['at'] <=> $b['at']);
        foreach ($found as $f) {
            foreach (['mac', 'windows', 'linux'] as $p) {
                $b = self::renderSnippet($f['snippet'], $f['args'], $ctx, $p);
                if ($b !== null) $out[$p][] = $b;
            }
        }
        return array_map(fn ($blocks) => implode("\n\n", $blocks), $out);
    }

    /** Active registry commands for a company. Same-named commands: the company
     *  row OVERRIDES the shipped one, blank fields falling back to it — an empty
     *  stub saved under Commands must not shadow a working shipped command. */
    private static function registry(int $companyId)
    {
        $snippets = \App\Models\ScriptSnippet::query()->where('active', true)
            ->where(fn ($w) => $w->whereNull('company_id')->orWhere('company_id', $companyId))
            ->get();
        $byCmd = [];
        foreach ($snippets as $s) {
            $k = strtolower($s->command);
            $prev = $byCmd[$k] ?? null;
            if (! $prev) { $byCmd[$k] = $s; continue; }
            [$company, $global] = $s->company_id ? [$s, $prev] : [$prev, $s];
            foreach (['label', 'params', 'mac_script', 'windows_script', 'linux_script'] as $f) {
                if (blank($company->$f)) $company->$f = $global->$f;
            }
            $byCmd[$k] = $company;
        }
        return collect(array_values($byCmd));
    }

    /** One registry hit → its block for the platform. Params supplied in the SOP
     *  bake in; params left out become RUNTIME prompts (secret-ish names prompt
     *  silently). Null when the command has no script for this platform. */
    private static function renderSnippet(object $s, string $argsStr, array $ctx, string $platform): ?string
    {
        $col = ['mac' => 'mac_script', 'windows' => 'windows_script', 'linux' => 'linux_script'][$platform] ?? null;
        if (! $col || ! filled($s->{$col})) return null;
        $args = $argsStr === '' ? [] : preg_split('/\s+/', $argsStr);
        $names = array_values(array_filter(array_map('trim', explode(',', $s->params ?? ''))));
        $vars = ['{*}' => $argsStr];
        $prompts = ['sh' => '', 'windows' => ''];
        foreach ($names as $i => $name) {
            $val = $args[$i] ?? null;
            if ($val !== null && $val !== '') {
                $vars['{' . $name . '}'] = $val;
                continue;
            }
            $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
            $secret = (bool) preg_match('/psk|pass|secret|key|token|pin/i', $name);
            $prompts['sh'] .= $secret
                ? "printf '{$name}: '; read -s SNIP_{$safe}; echo\n"
                : "printf '{$name}: '; read SNIP_{$safe}\n";
            $prompts['windows'] .= "\$SNIP_{$safe} = Read-Host '{$name}'\n";
            $vars['{' . $name . '}'] = "\$SNIP_{$safe}";   // valid in sh, zsh and PowerShell
        }
        foreach ($args as $i => $a) $vars['{' . ($i + 1) . '}'] = $a;
        foreach ($ctx as $k => $v) $vars['{' . $k . '}'] = $v ?? '';
        $ask = $prompts[$platform === 'windows' ? 'windows' : 'sh'];
        return ($ask !== '' ? $ask : '') . strtr($s->{$col}, $vars);
    }

    /**
     * The script body IS the SOP: walk the steps in order and emit a block for
     * every explicit token — registry /commands, /install, /vpn, /mdm — grouped
     * under the step's own numbered title. Nothing implicit: a step with no
     * tokens contributes nothing, and there are no built-in sections.
     */
    public static function sopBody(array $steps, string $platform, int $companyId, array $ctx = []): string
    {
        $snippets = self::registry($companyId);
        $repo = rtrim($ctx['REPO'] ?? '', '/');
        $sections = [];

        foreach (array_values($steps) as $i => $step) {
            $texts = [];
            $collect = function ($node) use (&$collect, &$texts) {
                foreach (['title', 'why', 'instructions', 'done_when', 'record'] as $k) {
                    if (! empty($node[$k])) $texts[] = $node[$k];
                }
                foreach ($node['subtasks'] ?? [] as $sub) $collect($sub);
            };
            $collect($step);

            $blocks = [];
            foreach ($texts as $text) {
                $hits = [];
                foreach ($snippets as $s) {
                    if (! preg_match_all('~/' . preg_quote($s->command, '~') . '\b([^\n/]*)~i', $text, $m, PREG_OFFSET_CAPTURE)) continue;
                    foreach ($m[1] as $hit) $hits[] = ['at' => $hit[1], 'kind' => 'snippet', 'snippet' => $s, 'args' => trim($hit[0])];
                }
                if (preg_match_all('~/(install|vpn)\s+[^\n/]+~i', $text, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[0] as $hit) $hits[] = ['at' => $hit[1], 'kind' => 'fetch', 'text' => $hit[0]];
                }
                if (preg_match_all('~/mdm\s+(\w+)(?:\s+(auto|manual)\b)?~i', $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                    foreach ($m as $hit) $hits[] = ['at' => $hit[0][1], 'kind' => 'mdm', 'text' => $hit[0][0],
                        'name' => strtolower($hit[1][0]), 'mode' => strtolower($hit[2][0] ?? '')];
                }
                usort($hits, fn ($a, $b) => $a['at'] <=> $b['at']);

                foreach ($hits as $h) {
                    if ($h['kind'] === 'snippet') {
                        $b = self::renderSnippet($h['snippet'], $h['args'], $ctx, $platform);
                        if ($b !== null) $blocks[] = rtrim($b);
                    } elseif ($h['kind'] === 'fetch') {
                        foreach (self::recipe([['title' => $h['text']]], $companyId) as $r) {
                            $rel = ltrim($r['relative_path'], '/');
                            if (! $repo || $rel === '') {
                                $blocks[] = "# {$h['text']}: set the company's Installers URL first";
                                continue;
                            }
                            $url = $repo . '/' . str_replace('%2F', '/', rawurlencode($rel));
                            $file = basename($rel);
                            $blocks[] = match ($platform) {
                                'mac' => rtrim(self::macInstall($url, $file)),
                                'windows' => rtrim(self::winInstall($url, $file)),
                                default => "# {$h['text']}: fetch {$url} and install manually (no Linux installer helper yet)",
                            };
                        }
                    } else {
                        $profile = self::mdmProfile([['title' => $h['text']]], $companyId);
                        $profileUrl = '';
                        $profileFile = '';
                        if ($profile && $repo) {
                            $rel = ltrim($profile, '/');
                            $profileUrl = $repo . '/' . str_replace('%2F', '/', rawurlencode($rel));
                            $profileFile = basename($rel);
                        }
                        $blocks[] = match ($platform) {
                            'mac' => rtrim(self::macMdm($h['name'], $profileUrl, $profileFile, $h['mode'] ?? '')),
                            'windows' => rtrim(self::winMdm($h['name'], $h['mode'] ?? '')),
                            default => "# /mdm {$h['name']}: enroll from the device's own console (no Linux MDM helper)",
                        };
                    }
                }
            }
            if ($blocks) {
                $n = $i + 1;
                $sections[] = "# --- {$n}. {$step['title']}\n" . implode("\n\n", $blocks);
            }
        }
        return implode("\n\n", $sections);
    }

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

    /**
     * The script platform comes from the SOP header's OS row; the form factor is
     * only the fallback. One SOP, one OS, one script.
     */
    public static function osVariant(?string $os, ?string $fallback): string
    {
        $os = strtolower($os ?? '');
        return match (true) {
            str_contains($os, 'mac') => 'Mac',
            str_contains($os, 'windows') => 'Windows',
            str_contains($os, 'linux') => 'Linux',
            str_contains($os, 'ios'), str_contains($os, 'android') => 'Mobile - ' . $os,
            default => $fallback ?? '',
        };
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

    /**
     * Email the company's system administrators that a device was onboarded — the audit
     * baseline for spotting an enrollment that didn't go through AssetMost. SuperAdmins
     * (all companies) plus this company's IT Admins. Never throws: a mail failure or an
     * unconfigured mailer must not fail the onboarding (delivery just needs SMTP set).
     */
    private static function notifyAdmins(Device $device, array $data, int $companyId, string $mdm): void
    {
        try {
            $recipients = User::query()
                ->whereIn('role', [Access::SUPER_ADMIN, Access::IT_ADMIN])
                ->where(fn ($q) => $q->where('role', Access::SUPER_ADMIN)->orWhere('company_id', $companyId))
                ->where('active', true)->whereNotNull('email')
                ->pluck('email')->unique()->values()->all();
            if (! $recipients) return;

            $who = auth()->user()?->name ?: 'someone';
            $model = trim(($data['brand'] ?? '') . ' ' . ($data['model'] ?? ''));
            $body = "A machine was onboarded through AssetMost.\n\n"
                . "Asset tag: {$device->asset_tag}\n"
                . 'Runbook:   ' . ($data['variant'] ?: '(default)') . "\n"
                . ($mdm ? 'MDM:       ' . ucwords($mdm) . "\n" : '')
                . 'Model:     ' . ($model ?: '-') . "\n"
                . 'Serial:    ' . ($data['serial_num'] ?? '-') . "\n"
                . "By:        {$who}\n"
                . 'When:      ' . Carbon::now()->toDayDateTimeString() . "\n\n"
                . 'If you did not expect this enrollment, investigate it.';

            Mail::raw($body, function ($m) use ($recipients, $device) {
                $m->to($recipients)->subject("AssetMost: {$device->asset_tag} onboarded");
            });
        } catch (\Throwable $e) {
            Log::warning('machine.onboard.notify_failed', ['device' => $device->id, 'error' => $e->getMessage()]);
        }
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

    /**
     * Produce the bootstrap script a device workflow would generate, without a real
     * machine — device-specifics ({ASSET_TAG} etc.) stay as placeholders, but the
     * SOP's own /install, /vpn and /mdm all resolve for real. Powers the onboarding
     * screen's Script tab so you can see (and, later, edit) what a machine will run.
     */
    public function previewScript(Request $request): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $companyId = app(TenantResolver::class)->id();
        abort_if(! $companyId, 422, 'Pick a company first.');

        $template = \App\Models\DocPage::query()
            ->where('workflow_type', 'device')
            ->find((int) $request->query('workflow'));
        abort_if(! $template, 404, 'No such device runbook.');
        $steps = json_decode($template->workflow_steps ?? '', true)['steps'] ?? [];

        $device = new Device();
        $device->asset_tag = '{ASSET_TAG}';
        $device->setRelation('company', \App\Models\Company::find($companyId));

        // One SOP, one OS: the header's OS row decides the platform — never
        // guessed. No OS = no script; the preview says exactly what to do.
        $meta = json_decode($template->workflow_steps ?? '', true)['meta'] ?? [];
        $os = trim((string) ($meta['os'] ?? ''));
        if ($os === '' && ! preg_match('/mobile|other/i', (string) $template->form_factor)) {
            return response()->json(['script' =>
                "# No OS in the SOP header — the platform comes from the SOP, never guessed.\n"
                . "# Open the SOP tab, type /sop to refresh the header, pick the OS, and regenerate."]);
        }
        $variant = self::osVariant($os !== '' ? $os : null, $template->form_factor);

        $company = \App\Models\Company::find($companyId);
        $platform = strtolower(explode(' ', $variant)[0]);
        $body = in_array($platform, ['mac', 'windows', 'linux'], true)
            ? self::sopBody($steps, $platform, $companyId, [
                'ASSET_TAG' => '{ASSET_TAG}', 'BASE_URL' => '{BASE_URL}', 'TOKEN' => '{TOKEN}',
                'REPO' => rtrim($company?->installers_url ?? '', '/'),
                'DOMAIN' => $company?->domain, 'LOCAL_DOMAIN' => $company?->local_domain,
                // The PREVIEW keeps credentials as placeholders; the real values are
                // inserted only when a machine script is generated at /onboard.
                'LOCAL_ADMIN_USER' => '{LOCAL_ADMIN_USER}',
                'LOCAL_ADMIN_PASS' => '{LOCAL_ADMIN_PASS}',
                'DOMAIN_JOIN_USER' => '{DOMAIN_JOIN_USER}',
                'DOMAIN_JOIN_PASS' => '{DOMAIN_JOIN_PASS}',
            ])
            : '';

        return response()->json(['script' => $this->script($device, '{TOKEN}', '{BASE_URL}', $variant, $body)]);
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

    /**
     * The bootstrap script: a platform preamble (report plumbing), then the SOP
     * body — every block generated from an explicit token in the steps, in SOP
     * order — then a closing line. NOTHING is baked in: renaming is /hostname,
     * disk encryption is /encryptdisk, updates are /osupdate, domain join is
     * /domainjoin. If the SOP doesn't say it, the script doesn't do it.
     */
    private function script(Device $device, string $token, string $base, string $variant, string $body): string
    {
        $tag = $device->asset_tag;

        // Mobile and generic devices have no shell to script — their runbook is the
        // checklist (MDM enrollment, web UIs); say so instead of emitting a wrong script.
        if (preg_match('/mobile|other/i', $variant)) {
            return "# {$variant} — this runbook doesn't produce a machine script.\n"
                . "# The checklist project carries the procedure: enrollment happens in the MDM, configuration in the device's own console.";
        }

        if ($body === '') {
            $body = "# This SOP has no script-producing commands yet.\n"
                . "# Type / in a substep — /hostname, /encryptdisk, /localadmin, /domainjoin, /install, /mdm, /osupdate … — and regenerate.";
        }

        if (preg_match('/linux/i', $variant)) {
            return "#!/bin/bash\n"
                . "# AssetMost bootstrap — {$tag} — generated from the SOP; every block below is one\n"
                . "# of its /commands, in SOP order. Run with: sudo bash ./bootstrap.sh  (safe to re-run)\n"
                . "T='{$token}'\n"
                . "A='{$base}'\n"
                . "report() { curl -sk -X POST \"\$A/onboard/report?t=\$T\" -H 'Content-Type: application/json' -d \"{\\\"step\\\":\\\"\$1\\\",\\\"ok\\\":\$2,\\\"note\\\":\\\"\$3\\\"}\" >/dev/null 2>&1; }\n\n"
                . $body . "\n\n"
                . "echo 'Bootstrap done — the SOP checklist in AssetMost carries the manual steps.'";
        }

        if (preg_match('/mac/i', $variant)) {
            return "#!/bin/zsh\n"
                . "# AssetMost bootstrap — {$tag} — generated from the SOP; every block below is one\n"
                . "# of its /commands, in SOP order. Run with: sudo zsh ./bootstrap.sh  (safe to re-run)\n"
                . "T='{$token}'\n"
                . "A='{$base}'\n"
                . "report() { curl -sk -X POST \"\$A/onboard/report?t=\$T\" -H 'Content-Type: application/json' -d \"{\\\"step\\\":\\\"\$1\\\",\\\"ok\\\":\$2,\\\"note\\\":\\\"\$3\\\"}\" >/dev/null 2>&1; }\n\n"
                . $body . "\n\n"
                . "echo 'Bootstrap done — the SOP checklist in AssetMost carries the manual steps.'";
        }

        return "# AssetMost bootstrap — {$tag} — generated from the SOP; every block below is one\n"
            . "# of its /commands, in SOP order. Run in an elevated PowerShell (5.1 compatible); safe to re-run.\n"
            . "\$T = '{$token}'\n"
            . "\$A = '{$base}'\n"
            . "[System.Net.ServicePointManager]::ServerCertificateValidationCallback = { \$true }\n"
            . "function Report(\$s, \$ok, \$n = '') {\n"
            . "    try { Invoke-RestMethod \"\$A/onboard/report?t=\$T\" -Method POST -ContentType 'application/json' -Body (@{ step = \$s; ok = \$ok; note = \$n } | ConvertTo-Json) | Out-Null } catch {}\n"
            . "}\n\n"
            . $body . "\n\n"
            . "Write-Host 'Bootstrap done — the SOP checklist in AssetMost carries the manual steps.' -ForegroundColor Green";
    }

    /**
     * macOS MDM enrollment from the /mdm token. The mode is the SOP's purchase
     * story: 'auto' = bought on the business account, sits in Apple Business
     * Manager, Automated Device Enrollment (zero-touch); 'manual' = retail-
     * bought, no ABM record — the enrollment profile is staged from the share
     * and approved by hand. No mode: profile when one is on the share, else ADE.
     */
    private static function macMdm(string $mdm, string $profileUrl = '', string $profileFile = '', string $mode = ''): string
    {
        if ($mdm === '') return '# (no /mdm in the runbook)';
        $name = ucwords($mdm);

        if ($mode === 'auto') {
            return <<<SH
# Enroll into {$name} - Automated Device Enrollment (the Mac is in Apple Business Manager)
if profiles renew -type enrollment 2>/dev/null; then
  report 'MDM enrollment' true 'ADE enrollment renewed ({$name})'
else
  report 'MDM enrollment' false 'ADE failed - is this Mac in ABM? Retail-bought Macs need /mdm {$mdm} manual'
fi
SH;
        }

        if ($mode === 'manual' && $profileUrl === '') {
            return <<<SH
# Enroll into {$name} MANUALLY - retail-bought Mac, no ABM record
report 'MDM enrollment' false 'Drop the {$name} enrollment profile on the share (Mac/... .mobileconfig, zipped) or enroll via the {$name} enrollment URL - then approve it in System Settings > Profiles'
SH;
        }

        if ($profileUrl !== '') {
            $why = $mode === 'manual' ? 'retail-bought Mac, no ABM record - profile staged for hand approval' : 'fetch the enrollment profile from the share and open it for approval';
            return <<<SH
# Enroll into {$name} - {$why}
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
     * Windows MDM enrollment from the /mdm token. Intune uses Azure AD auto-
     * enrollment (deviceenroller); 'manual' = retail/BYO device enrolled by hand
     * through the Company Portal; other MDMs need their own agent/URL.
     */
    private static function winMdm(string $mdm, string $mode = ''): string
    {
        if ($mdm === '') return '# (no /mdm in the runbook)';
        if ($mode === 'manual') {
            $name = ucwords($mdm);
            return "# Enroll into {$name} MANUALLY - retail/BYO device, no autopilot record\n"
                . "Report 'MDM enrollment' \$false 'Enroll by hand: {$name} portal/agent (Intune: Company Portal > Settings > Accounts > Access work or school)'";
        }
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
