<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DocPage;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The power bar's resolver: one query in, every kind of target out.
 *
 * "@501" doesn't say what it is — asset tags are unique enough that it doesn't
 * have to. So the resolver searches every type at once and lets the ranked
 * result speak: devices by tag/name/IP, people by name/username/email, docs by
 * title, tasks by title. Tenant walls hold: devices/docs/tasks are globally
 * scoped to the active company, people filtered to managedCompanyIds here.
 *
 * Each row carries what its actions need (fqdn, ip, a person's primary device)
 * so the client never does a second round-trip between typing and Enter.
 */
class PaletteController extends Controller
{
    private const LIMIT = 6;

    public function search(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());
        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $results = [];

        foreach (Device::query()->with('company:id,name,local_domain')
            ->where(fn ($w) => $w->where('asset_tag', 'ilike', $like)
                ->orWhere('computer_name', 'ilike', $like)
                ->orWhere('ip_1', 'ilike', $like))
            ->where('active', true)->limit(self::LIMIT)->get() as $d) {
            $results[] = [
                'type' => 'device', 'id' => $d->id,
                'label' => $d->asset_tag ?: ($d->computer_name ?: "#{$d->id}"),
                'sub' => trim(implode(' · ', array_filter([$d->computer_name, $d->type, $d->ip_1]))),
                'fqdn' => $this->fqdn($d), 'ip' => $d->ip_1,
                'company' => $d->company?->name,
            ];
        }

        foreach (User::query()->whereIn('company_id', auth()->user()->managedCompanyIds())
            ->where('active', true)
            ->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('last', 'ilike', $like)
                ->orWhere('username', 'ilike', $like)->orWhere('email', 'ilike', $like))
            ->with('devices.company:id,local_domain')->limit(self::LIMIT)->get() as $p) {
            $dev = $p->devices->first();
            $results[] = [
                'type' => 'person', 'id' => $p->id,
                'label' => trim("{$p->name} {$p->last}"),
                'sub' => trim(implode(' · ', array_filter([$p->title, $p->department]))),
                'device_label' => $dev?->asset_tag, 'fqdn' => $dev ? $this->fqdn($dev) : null,
                'ip' => $dev?->ip_1,
            ];
        }

        foreach (DocPage::query()->where('title', 'ilike', $like)
            ->limit(self::LIMIT)->get(['id', 'title', 'category']) as $doc) {
            $results[] = ['type' => 'doc', 'id' => $doc->id, 'label' => $doc->title, 'sub' => $doc->category ?: 'doc'];
        }

        foreach (Task::query()->where('title', 'ilike', $like)->where('done', false)
            ->limit(self::LIMIT)->get(['id', 'title', 'kind']) as $t) {
            $results[] = ['type' => 'task', 'id' => $t->id, 'label' => $t->title, 'sub' => $t->kind];
        }

        // Exact-tag matches float; otherwise devices > people > docs > tasks as listed.
        usort($results, fn ($a, $b) =>
            (strcasecmp($b['label'], $q) === 0) <=> (strcasecmp($a['label'], $q) === 0));

        return response()->json(['results' => array_slice($results, 0, 14)]);
    }

    /**
     * Render a registry /command for an @device — the SOP engine, brought out of
     * the SOP. Same substitution, same prompts, same loud warnings; the target
     * supplies the context the generating SOP's device normally would.
     */
    public function render(Request $request): JsonResponse
    {
        $data = $request->validate([
            'command' => 'required|string|max:40',
            'args' => 'nullable|string|max:255',
            'device_id' => 'required|integer',
        ]);
        $device = Device::query()->with('company')->findOrFail($data['device_id']);
        $rendered = MachineOnboardController::renderForDevice($data['command'], $data['args'] ?? '', $device);
        abort_unless($rendered, 404, "No /{$data['command']} in the commands registry.");
        return response()->json($rendered);
    }

    /**
     * Backlinks: everything that @-mentions this record. Only sources the viewer
     * can see come back — the doc query runs under the company global scope.
     */
    public function refs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:'.implode(',', \App\Support\ObjectRefSync::TYPES),
            'id' => 'required|integer',
        ]);
        $edges = \App\Models\ObjectRef::query()
            ->where(['to_type' => $data['type'], 'to_id' => $data['id']])->get();

        $out = [];
        $docIds = $edges->where('from_type', 'doc')->pluck('from_id');
        foreach (DocPage::query()->whereIn('id', $docIds)->get(['id', 'title', 'category']) as $doc) {
            $out[] = ['type' => 'doc', 'id' => $doc->id, 'label' => $doc->title, 'sub' => $doc->category ?: 'doc'];
        }
        $taskIds = $edges->where('from_type', 'task')->pluck('from_id');
        foreach (Task::query()->whereIn('id', $taskIds)->get(['id', 'title', 'kind']) as $t) {
            $out[] = ['type' => 'task', 'id' => $t->id, 'label' => $t->title, 'sub' => $t->kind];
        }
        return response()->json(['refs' => $out]);
    }

    /** asset_tag doubles as hostname; the company's local domain completes it. */
    private function fqdn(Device $d): ?string
    {
        $domain = $d->company?->local_domain;
        return ($d->asset_tag && $domain) ? strtolower("{$d->asset_tag}.{$domain}") : null;
    }
}
