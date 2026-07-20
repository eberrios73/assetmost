<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\DocPage;
use App\Models\Login;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Contracts\TenantResolver;
use App\Support\RunbookRefs;
use App\Support\SopDocParser;
use App\Support\StarterTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * The workflow engine, backed by Docs. A workflow IS a DocPage with workflow_*
 * columns — the People/Assets onboarding tabs are filtered views of these pages.
 *
 * Source of truth: the STRUCTURED STEPS (workflow_steps). The page body is the
 * human description, regenerated from the steps on save; parsing a doc or a
 * paste is an import into the steps, not a live sync. One engine, one artifact.
 */
class WorkflowController extends Controller
{
    /** The workflow docs for a tab's left column. */
    public function index(Request $request): JsonResponse
    {
        $type = $request->string('type')->toString();
        return response()->json(
            DocPage::query()->whereNotNull('workflow_type')
                ->when(in_array($type, ['people', 'device'], true), fn ($q) => $q->where('workflow_type', $type))
                ->with('company:id,name')
                ->orderByRaw('form_factor IS NULL')->orderBy('form_factor')->orderBy('title')
                ->get(['id', 'company_id', 'title', 'workflow_type', 'workflow_slug', 'form_factor', 'workflow_active', 'workflow_shipped', 'workflow_wizard'])
                ->map(fn ($p) => [
                    'id' => $p->id, 'title' => $p->title, 'type' => $p->workflow_type,
                    'slug' => $p->workflow_slug, 'form_factor' => $p->form_factor,
                    'active' => (bool) $p->workflow_active, 'shipped' => (bool) $p->workflow_shipped,
                    'wizard' => (bool) $p->workflow_wizard,
                    // In All-companies mode every company's set shows — the name disambiguates.
                    'company_id' => $p->company_id, 'company' => $p->company?->name,
                ])
        );
    }

    public function show(DocPage $page): JsonResponse
    {
        abort_if(! $page->workflow_type, 404);
        $steps = json_decode($page->workflow_steps ?? '', true);
        return response()->json([
            'id' => $page->id, 'title' => $page->title, 'type' => $page->workflow_type,
            'slug' => $page->workflow_slug, 'form_factor' => $page->form_factor,
            'active' => (bool) $page->workflow_active, 'shipped' => (bool) $page->workflow_shipped,
            'wizard' => (bool) $page->workflow_wizard,
            'company_id' => $page->company_id,
            'steps' => $steps,
            'sop_meta' => $steps['meta'] ?? null,
            // The SOP tab edits the page itself in the same DocEditor as Docs —
            // one renderer everywhere; steps recompile from the body on save.
            'body' => $page->body,
            'rev' => $page->updated_at?->getTimestamp() ?? 0,
        ]);
    }

    /** Save the steps (the source) and regenerate the page body (the description). */
    public function saveSteps(Request $request, DocPage $page): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        abort_if(! $page->workflow_type, 404);
        $data = $request->validate([
            'steps' => 'required|array',
            'steps.version' => 'required|integer',
            'steps.steps' => 'required|array',
        ]);

        // Governance meta rides along — keep whatever the page already had unless replaced.
        $prev = json_decode($page->workflow_steps ?? '', true);
        $steps = $data['steps'] + (isset($prev['meta']) ? ['meta' => $prev['meta']] : []);

        $page->forceFill([
            'workflow_steps' => json_encode($steps),
            'body' => SopDocParser::toHtml($steps['steps'], '', $steps['meta'] ?? []),
            'updated_by' => auth()->id(),
        ])->save();

        return response()->json(['ok' => true]);
    }

    /** Active toggle (and rename for duplicates). */
    public function update(Request $request, DocPage $page): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        abort_if(! $page->workflow_type, 404);
        $data = $request->validate([
            'active' => 'sometimes|boolean',
            'title' => 'sometimes|string|max:255',
        ]);
        if (array_key_exists('active', $data)) $page->workflow_active = $data['active'];
        if (array_key_exists('title', $data)) $page->title = $data['title'];
        $page->save();
        return response()->json(['ok' => true]);
    }

    /** Duplicate a baseline to make a variant (Other Device -> Access Point). */
    public function duplicate(DocPage $page): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        abort_if(! $page->workflow_type, 404);

        $copy = $page->replicate(['workflow_slug']);
        $copy->title = $page->title . ' (copy)';
        $copy->workflow_shipped = false;   // user-owned from birth
        $copy->workflow_slug = null;       // /refs resolve it by title once renamed
        $copy->updated_by = auth()->id();
        $copy->save();

        return response()->json(['ok' => true, 'id' => $copy->id, 'title' => $copy->title], 201);
    }

    /** Reseed the shipped placeholder steps (for a baseline whose steps were emptied). */
    public function adopt(DocPage $page): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        abort_if(! $page->workflow_type, 404);
        $tpl = $page->workflow_slug ? StarterTemplates::workflow($page->workflow_slug) : null;
        abort_if(! $tpl, 404, 'No shipped starter for this workflow.');

        $page->forceFill([
            'workflow_steps' => json_encode($tpl),
            'body' => SopDocParser::toHtml($tpl['steps']),
            'updated_by' => auth()->id(),
        ])->save();

        return response()->json(['ok' => true]);
    }

    /** Import steps from another Docs page (one-time parse, not a live link). */
    public function parseDoc(Request $request, DocPage $page): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        abort_if(! $page->workflow_type, 404);
        $data = $request->validate(['page_id' => 'required|integer|exists:doc_pages,id']);

        $sourcePage = DocPage::query()->findOrFail($data['page_id']);
        $parsed = SopDocParser::parse($sourcePage->body ?? '');
        abort_if(empty($parsed['steps']), 422, 'Nothing parseable on that page.');

        $page->forceFill([
            'workflow_steps' => json_encode($parsed),
            'body' => SopDocParser::toHtml($parsed['steps'], '', $parsed['meta'] ?? []),
            'updated_by' => auth()->id(),
        ])->save();

        return response()->json(['ok' => true, 'steps' => $parsed]);
    }

    /**
     * The /form token in a step: the generated task carries the record form — "new"
     * creates, "edit" picks an existing record and updates it (the Record field made
     * executable). Returns "new device" / "edit person" style, or null. A bare
     * "/form device" reads as new.
     */
    public static function formKind(array $step): ?string
    {
        $text = implode("\n", array_filter([
            $step['title'] ?? '', $step['why'] ?? '', $step['instructions'] ?? '',
            $step['done_when'] ?? '', $step['record'] ?? '',
        ]));
        return preg_match('~/form\s+(?:(new|edit)\s+)?(device|person|account|location)~i', $text, $m)
            ? strtolower($m[1] ?: 'new') . ' ' . strtolower($m[2])
            : null;
    }

    /** The flat task list this workflow generates, /references resolved live. */
    public function preview(Request $request, DocPage $page): JsonResponse
    {
        abort_if(! $page->workflow_type, 404);
        $companyId = $page->company_id;
        $decoded = json_decode($page->workflow_steps ?? '', true) ?: [];
        $steps = $decoded['steps'] ?? [];
        $meta = $decoded['meta'] ?? [];
        $base = $request->getSchemeAndHttpHost();

        $rows = [];
        // The header's Tools and Safety rows run ahead of the procedure.
        if (! empty($meta['tools'])) {
            $rows[] = ['title' => 'Gather tools and materials', 'note' => $meta['tools'], 'depth' => 0, 'ref' => false];
        }
        if (! empty($meta['safety'])) {
            $rows[] = ['title' => 'Safety precautions', 'note' => '', 'depth' => 0, 'ref' => false];
            foreach (preg_split('/\n+/', $meta['safety']) as $line) {
                if (trim($line) !== '') $rows[] = ['title' => trim($line), 'note' => '', 'depth' => 1, 'ref' => false];
            }
        }
        foreach ($steps as $step) {
            $refExtra = [];
            $note = '';
            foreach (['why' => 'Why', 'instructions' => 'How', 'done_when' => 'Done when'] as $k => $label) {
                if (! empty($step[$k])) {
                    [$resolved, $extra] = RunbookRefs::resolve($step[$k], $companyId, $base);
                    if ($k === 'instructions') $note = $resolved;
                    $refExtra = array_merge($refExtra, $extra);
                }
            }
            $rows[] = ['title' => $step['title'], 'note' => $note, 'depth' => 0, 'ref' => false, 'form' => self::formKind($step)];
            foreach ($refExtra as $r) {
                $rows[] = ['title' => $r['title'], 'note' => $r['instructions'] ?? '', 'depth' => 1, 'ref' => true];
            }
            foreach ($step['subtasks'] ?? [] as $sub) {
                $rows[] = ['title' => $sub['title'], 'note' => $sub['instructions'] ?? '', 'depth' => 1, 'ref' => false, 'form' => self::formKind($sub)];
            }
        }
        return response()->json(['rows' => $rows]);
    }

    /** Workflow slugs for the Docs editor's / menu (plus titles for duplicates). */
    public function refOptions(): JsonResponse
    {
        return response()->json(
            DocPage::query()->whereNotNull('workflow_slug')->where('workflow_active', true)
                ->orderBy('title')->get(['workflow_slug', 'title'])
                ->map(fn ($p) => ['slug' => $p->workflow_slug, 'name' => $p->title])
        );
    }

    /** Docs pages as {id,label} for the SOP import picker. */
    public function docOptions(): JsonResponse
    {
        return response()->json(
            DocPage::query()->orderBy('title')->get(['id', 'title'])
                ->map(fn ($p) => ['id' => $p->id, 'label' => $p->title])
        );
    }

    /**
     * The hire wizard, run FROM a people workflow (the one you're on — Employee or
     * Freelancer onboarding). One transaction; everything or nothing: the person,
     * one credential per picked vendor (passwords by the company formula, stored in
     * the registry — never displayed in a task), floating-account assignments, and
     * an "Onboard {name}" task project chained off this workflow's steps.
     */
    public function run(Request $request, DocPage $page): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        abort_if(! $page->workflow_type || ! $page->workflow_wizard, 404);
        $companyId = $page->company_id;

        $data = $request->validate([
            'first' => 'required|string|max:255',
            'last' => 'required|string|max:255',
            'username' => 'nullable|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'title' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'vendor_ids' => 'nullable|array',
            'vendor_ids.*' => 'integer|exists:vendors,vendorID',
            'account_ids' => 'nullable|array',          // floating accounts to assign
            'account_ids.*' => 'integer|exists:accounts,id',
        ]);

        $decoded = json_decode($page->workflow_steps ?? '', true) ?: [];
        $steps = $decoded['steps'] ?? [];
        $stepsMeta = $decoded['meta'] ?? [];
        $doh = Carbon::parse($data['start_date']);
        $company = \App\Models\Company::find($companyId);

        $result = DB::transaction(function () use ($data, $steps, $stepsMeta, $doh, $company, $companyId) {
            // 1. The person — a directory record; sign-in stays a separate, deliberate grant.
            $person = User::create([
                'name' => $data['first'], 'last' => $data['last'],
                'username' => $data['username'] ?? null,
                'email' => $data['email'],
                'title' => $data['title'] ?? null, 'department' => $data['department'] ?? null,
                'company_id' => $companyId,
                'role' => 'User', 'active' => true, 'can_login' => false,
                'password' => Hash::make(str()->random(40)),   // unusable placeholder; can_login gates anyway
            ]);

            // 2. One credential per picked vendor, password by the company formula
            //    (base for Domain/Microsoft, last-2-chars variant for the rest — the
            //    format is deliberate and preserved). Stored ONCE, here, in the registry.
            $base = $this->generatePassword($data['first'], $data['last'], $company?->name);
            $credentials = [];
            foreach (Vendor::query()->whereIn('vendorID', $data['vendor_ids'] ?? [])->get() as $vendor) {
                $isDirectory = (bool) preg_match('/active directory|domain/i', $vendor->name);
                $isBaseHolder = $isDirectory || preg_match('/microsoft/i', $vendor->name);
                $login = Login::create([
                    'company_id' => $companyId,
                    'vendorID' => $vendor->vendorID,
                    'login_name' => $vendor->name,
                    'login_id' => $isDirectory ? ($data['username'] ?: $data['email']) : $data['email'],
                    'login_pass' => $isBaseHolder ? $base : $this->variantPassword($base),
                    'sharing' => 'personal',
                    'notes' => 'Created during onboarding v2',
                    'is_active' => 1,
                    'userID' => $person->id,               // legacy column; ITer reads it
                ]);
                $login->holders()->attach($person->id);
                $credentials[] = ['vendor' => $vendor->name, 'vendor_id' => $vendor->vendorID, 'login_id' => $login->login_id];
            }

            // 3. Floating accounts they'll hold from day one.
            $floating = [];
            foreach (Account::query()->whereIn('id', $data['account_ids'] ?? [])->get() as $account) {
                $account->holders()->syncWithoutDetaching([$person->id]);
                $floating[] = $account->identifier;
            }

            // 4. The task project — the human checklist, chained and planned off the DOH.
            // A task's notes carry the whole playbook card — bench mode reads these.
            $card = function (array $step) use (&$sub) {
                $parts = [];
                foreach (['why' => 'Why', 'instructions' => 'How', 'done_when' => 'Done when', 'record' => 'Record'] as $k => $label) {
                    if (! empty($step[$k])) $parts[] = $label . ': ' . $sub($step[$k]);
                }
                return implode("\n", $parts);
            };
            $sub = fn ($s) => str_replace(
                ['{first}', '{last}', '{username}', '{email}', '{start_date}', '{local_domain}', '{domain}'],
                [$data['first'], $data['last'], $data['username'] ?? '', $data['email'], $doh->toDateString(),
                 $company?->local_domain ?? '', $company?->domain ?? ''],
                $s ?? ''
            );
            $monday = fn (Carbon $d) => max($d->copy()->startOfWeek(Carbon::MONDAY), Carbon::now()->startOfWeek(Carbon::MONDAY))->toDateString();
            $ord = (int) (Task::query()->max('ord') + 1);

            $project = Task::create([
                'title' => "Onboard {$data['first']} {$data['last']}",
                'is_project' => true, 'status' => 'In progress',
                'week' => $monday(Carbon::now()), 'origin' => Carbon::now()->toDateString(),
                'notes' => "DOH {$doh->toDateString()} · {$data['email']}" . ($floating ? ' · floating: ' . implode(', ', $floating) : ''),
                'assigned_to' => auth()->id(), 'ord' => $ord++,
            ]);

            $mkTask = function (array $attrs) use (&$ord, $project) {
                return Task::create($attrs + [
                    'parent_id' => $attrs['parent_id'] ?? $project->id,
                    'assigned_to' => auth()->id(), 'ord' => $ord++,
                ]);
            };

            $taskCount = 0;
            // Vendor-account tasks: parallel, pre-arrival. Credentials are ALREADY in the
            // registry — the task is doing it in the real console, not inventing secrets.
            foreach ($credentials as $c) {
                $when = $doh->copy()->subDays(2);
                $mkTask([
                    'title' => "Create {$c['vendor']} account — {$c['login_id']}",
                    'notes' => 'Credentials are generated and stored in the registry. Create the account in the console using them; never write them anywhere else.',
                    'week' => $monday($when), 'origin' => Carbon::now()->toDateString(),
                    'planned_start' => $when->toDateString(), 'due_date' => $when->toDateString(),
                ]); $taskCount++;
            }
            if ($floating) {
                $mkTask([
                    'title' => 'Hand over floating accounts: ' . implode(', ', $floating),
                    'notes' => 'Assigned in the registry; walk them through retrieval and usage.',
                    'week' => $monday($doh), 'origin' => Carbon::now()->toDateString(),
                    'planned_start' => $doh->toDateString(), 'due_date' => $doh->toDateString(),
                ]); $taskCount++;
            }

            // Workflow steps: chained sequentially; subtasks ride under their step.
            // A /form token stamps the task (Form: kind · co:N) — the Tasks screen
            // renders the add-record form from it, scoped to THIS workflow's company.
            $formLine = fn (array $st) => ($fk = self::formKind($st)) ? "\nForm: {$fk} · co:{$companyId}" : '';
            $prev = null;

            // The SOP header's Tools and Safety rows are ACTIONABLE: they run ahead
            // of the procedure as real tasks (safety lines become subtasks).
            $meta = $stepsMeta ?? [];
            if (! empty($meta['tools'])) {
                $prev = $mkTask([
                    'title' => 'Gather tools and materials',
                    'notes' => $sub($meta['tools']),
                    'week' => $monday($doh->copy()->subDays(2)), 'origin' => Carbon::now()->toDateString(),
                    'planned_start' => $doh->copy()->subDays(2)->toDateString(), 'due_date' => $doh->copy()->subDays(2)->toDateString(),
                ]); $taskCount++;
            }
            if (! empty($meta['safety'])) {
                $safety = $mkTask([
                    'title' => 'Safety precautions',
                    'notes' => 'From the SOP header — complete before the procedure.',
                    'week' => $monday($doh->copy()->subDays(2)), 'origin' => Carbon::now()->toDateString(),
                    'planned_start' => $doh->copy()->subDays(2)->toDateString(), 'due_date' => $doh->copy()->subDays(2)->toDateString(),
                    'depends_on_id' => $prev?->id,
                ]); $taskCount++;
                foreach (preg_split('/\n+/', $meta['safety']) as $line) {
                    if (trim($line) === '') continue;
                    $mkTask([
                        'title' => $sub(trim($line)),
                        'parent_id' => $safety->id,
                        'week' => $monday($doh->copy()->subDays(2)), 'origin' => Carbon::now()->toDateString(),
                        'planned_start' => $doh->copy()->subDays(2)->toDateString(), 'due_date' => $doh->copy()->subDays(2)->toDateString(),
                    ]); $taskCount++;
                }
                $prev = $safety;
            }
            foreach ($steps as $step) {
                $when = $doh->copy()->addDays((int) ($step['offset_days'] ?? 0));
                $t = $mkTask([
                    'title' => $sub($step['title']),
                    'notes' => $card($step) . $formLine($step),
                    'week' => $monday($when), 'origin' => Carbon::now()->toDateString(),
                    'planned_start' => $when->toDateString(), 'due_date' => $when->toDateString(),
                    'depends_on_id' => $prev?->id,
                ]); $taskCount++;
                foreach ($step['subtasks'] ?? [] as $s) {
                    $whenS = $doh->copy()->addDays((int) ($s['offset_days'] ?? ($step['offset_days'] ?? 0)));
                    $mkTask([
                        'title' => $sub($s['title']),
                        'notes' => $card($s) . $formLine($s),
                        'parent_id' => $t->id,
                        'week' => $monday($whenS), 'origin' => Carbon::now()->toDateString(),
                        'planned_start' => $whenS->toDateString(), 'due_date' => $whenS->toDateString(),
                    ]); $taskCount++;
                }
                $prev = $t;
            }

            Log::info('onboarding.run', ['person' => $person->id, 'by' => auth()->id(),
                'credentials' => count($credentials), 'floating' => count($floating), 'tasks' => $taskCount]);

            return [
                'person_id' => $person->id,
                'project_id' => $project->id,
                'credentials' => $credentials,
                'floating' => $floating,
                'tasks' => $taskCount,
            ];
        });

        // Provisioning fires AFTER the transaction: an API outage can never roll back
        // the hire. Success completes the task with a note; failure leaves the manual
        // task with the error attached. Automation accelerates, never gates.
        foreach ($result['credentials'] as $i => $c) {
            $vendor = Vendor::query()->find($c['vendor_id']);
            $plug = $vendor ? \App\Support\Provisioning\ProvisionerRegistry::for($vendor, $companyId) : null;
            if (! $plug) continue;
            [$prov, $config] = $plug;
            $task = Task::query()->where('parent_id', $result['project_id'])
                ->where('title', 'like', "Create {$c['vendor']} account%")->first();
            try {
                $summary = $prov->provision($config, $data);
                $result['credentials'][$i]['provisioned'] = true;
                $task?->update(['done' => true, 'pct' => 100, 'completed_at' => Carbon::now(),
                    'notes' => trim(($task->notes ?? '') . "\n\nAuto-provisioned via API: {$summary}")]);
                Log::info('onboarding.provisioned', ['vendor' => $c['vendor'], 'person' => $result['person_id']]);
            } catch (\Throwable $e) {
                $result['credentials'][$i]['provisioned'] = false;
                $task?->update(['notes' => trim(($task->notes ?? '') . "\n\nAPI attempt failed — create manually. " . $e->getMessage())]);
                Log::warning('onboarding.provision_failed', ['vendor' => $c['vendor'], 'error' => $e->getMessage()]);
            }
        }

        return response()->json($result, 201);
    }

    /**
     * Company password formula — Xy-YYYYMMDD-AB@! — kept EXACTLY as specified in the
     * legacy wizard ("DO NOT CHANGE THIS PASSWORD LOGIC"). Generated once per run,
     * stored in the registry; variants change only the last two special characters.
     */
    private function generatePassword(string $first, string $last, ?string $companyName): string
    {
        $date = now()->format('Ymd');
        $initials = strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
        if ($companyName) {
            $ci = substr($companyName, 0, 2);
            $company = rand(0, 1) ? strtoupper($ci[0]) . strtolower($ci[1]) : strtolower($ci[0]) . strtoupper($ci[1]);
        } else {
            $company = 'Xx';
        }
        return $company . '-' . $date . '-' . $initials . $this->twoSpecials();
    }

    private function variantPassword(string $base): string
    {
        return substr($base, 0, -2) . $this->twoSpecials();
    }

    private function twoSpecials(): string
    {
        $chars = '!@#$%^&()';
        return $chars[rand(0, strlen($chars) - 1)] . $chars[rand(0, strlen($chars) - 1)];
    }
}
