<?php

namespace App\Http\Controllers;

use App\Models\DocPage;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Docs wiki. All queries auto-scoped to the active company via the model scope.
 * Pages live inside Spaces; the tree is requested one space at a time.
 */
class DocController extends Controller
{
    /** Spaces for the current company, with page counts. */
    public function spaces(): JsonResponse
    {
        // Counts come from the VISIBLE pages (own + shared into this company).
        $counts = DocPage::query()->selectRaw('space_id, COUNT(*) c')->groupBy('space_id')
            ->pluck('c', 'space_id');

        $own = Space::query()->orderBy('position')->orderBy('name')->get();
        // Spaces from other companies that hold docs shared into this one —
        // the shared playbook needs a visible home in the switcher.
        $foreign = Space::withoutGlobalScopes()
            ->whereIn('id', collect($counts->keys())->filter()->diff($own->pluck('id')))
            ->orderBy('name')->get();

        return response()->json(
            $own->concat($foreign)
                ->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->name, 'icon' => $s->icon,
                    'color' => $s->color, 'pages' => (int) ($counts[$s->id] ?? 0),
                ])->values()
        );
    }

    public function storeSpace(Request $request): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'icon' => 'nullable|string|max:16',
            'color' => 'nullable|string|max:16',
        ]);
        $space = Space::create($data + ['position' => (int) (Space::query()->max('position') + 1)]);
        return response()->json(['id' => $space->id], 201);
    }

    public function updateSpace(Request $request, Space $space): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $space->update($request->validate([
            'name' => 'sometimes|string|max:255',
            'icon' => 'sometimes|nullable|string|max:16',
            'color' => 'sometimes|nullable|string|max:16',
        ]));
        return response()->json(['ok' => true]);
    }

    public function destroySpace(Space $space): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $space->delete(); // pages' space_id set null (nullOnDelete)
        return response()->json(['ok' => true]);
    }

    /** Page tree for one space (or all pages if no space given). */
    public function tree(Request $request): JsonResponse
    {
        $pages = DocPage::query()
            ->whereNull('superseded_by_id')   // the tree shows CURRENT versions only
            ->when($request->filled('space'), fn ($q) => $q->where('space_id', (int) $request->query('space')))
            ->orderBy('position')->orderBy('title')
            ->get(['id', 'parent_id', 'title', 'icon', 'category', 'updated_at']);

        // A shared doc's parent may not be visible here — attach it at the root
        // instead of losing it.
        $visible = $pages->pluck('id')->flip();
        $byParent = $pages->groupBy(fn ($p) => ($p->parent_id && isset($visible[$p->parent_id])) ? $p->parent_id : 0);
        $build = function ($parentId) use (&$build, $byParent) {
            return ($byParent[$parentId ?? 0] ?? collect())->map(fn ($p) => [
                'id' => $p->id, 'title' => $p->title, 'icon' => $p->icon, 'category' => $p->category,
                'updated_at' => $p->updated_at?->toDateString(),
                'children' => $build($p->id),
            ])->values();
        };

        return response()->json($build(0));
    }

    public function show(DocPage $page): JsonResponse
    {
        $page->load('editor:id,name,last');

        // Version lineage (self-related): walk BACK from this page to list its old
        // versions, and FORWARD in case someone opened a superseded page directly.
        $versions = [];
        $cursor = $page;
        for ($i = 0; $i < 20; $i++) {
            $older = DocPage::withoutGlobalScopes()->where('superseded_by_id', $cursor->id)->first();
            if (! $older) break;
            $meta = json_decode($older->workflow_steps ?? '', true)['meta'] ?? [];
            $versions[] = ['id' => $older->id, 'version' => $meta['version'] ?? null, 'updated_at' => $older->updated_at];
            $cursor = $older;
        }
        $currentId = null;
        $cursor = $page;
        for ($i = 0; $cursor->superseded_by_id && $i < 20; $i++) {
            $cursor = DocPage::withoutGlobalScopes()->find($cursor->superseded_by_id) ?? $cursor;
            $currentId = $cursor->id;
        }

        return response()->json([
            'id' => $page->id, 'parent_id' => $page->parent_id,
            'title' => $page->title, 'body' => $page->body, 'icon' => $page->icon,
            'category' => $page->category,
            // A workflow page renders with its Info | SOP | Script tabs in Docs too.
            'workflow_type' => $page->workflow_type,
            'company_id' => $page->company_id,
            'shared_company_ids' => $page->sharedCompanies()->pluck('companies.id')->values(),
            'updated_at' => $page->updated_at,
            'rev' => $page->updated_at?->getTimestamp() ?? 0,
            'editor' => $page->editor ? trim("{$page->editor->name} {$page->editor->last}") : null,
            'versions' => $versions,          // older versions, newest first
            'current_id' => $currentId,       // set only when THIS page is superseded
        ]);
    }

    /**
     * New version: real supersession, not a loose duplicate. The new page becomes
     * THE document (it inherits the workflow slug); the old one is marked
     * superseded — hidden from the tree, search, and the workflow lists — and
     * shows up in the current page's version history. `blank: true` starts the
     * new version from the empty SOP scaffold instead of a copy.
     */
    public function newVersion(Request $request, DocPage $page): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        abort_if($page->superseded_by_id, 422, 'This is an old version — open the current one to version it.');
        $blank = (bool) $request->boolean('blank');

        $decoded = json_decode($page->workflow_steps ?? '', true) ?: [];
        $meta = $decoded['meta'] ?? [];
        $meta['version'] = preg_match('/^(\d+)\.(\d+)$/', $meta['version'] ?? '', $m)
            ? $m[1] . '.' . ($m[2] + 1)
            : '1.1';
        $meta['status'] = 'Draft';
        if (empty($meta['os']) && $page->form_factor) {
            $meta['os'] = self::osFromFormFactor($page->form_factor);
        }
        // A blank version is a fresh creation — its author is the default owner.
        if ($blank && empty($meta['owner']) && ($u = auth()->user())) {
            $meta['owner'] = trim("{$u->name} {$u->last}");
        }

        // The copy inherits the identity — including the slug — because it IS the
        // document now; replicate() copies workflow_slug along with everything else.
        $copy = $page->replicate();
        $copy->updated_by = auth()->id();
        if ($page->workflow_type) {
            $decoded['meta'] = $meta;
            if ($blank) $decoded['steps'] = [];
            $copy->workflow_steps = json_encode($decoded);
            $copy->body = $blank
                ? self::blankSopBody($meta)
                : \App\Support\SopDocParser::toHtml($decoded['steps'] ?? [], '', $meta);
        } elseif ($blank) {
            $copy->body = '<p></p>';
        }
        $copy->save();
        // Sharing travels with the document identity — the new version stays
        // visible in the same companies.
        $copy->sharedCompanies()->sync($page->sharedCompanies()->pluck('companies.id')->all());

        // The old version steps aside: out of every list, findable from the new one.
        $page->forceFill([
            'superseded_by_id' => $copy->id,
            'workflow_slug' => null,          // the slug lives on the current version only
            'workflow_active' => false,       // out of the onboarding lists and /refs
        ])->save();

        return response()->json(['ok' => true, 'id' => $copy->id], 201);
    }

    /**
     * Promote a plain doc into a runnable workflow: it gains the Info | SOP |
     * Script tabs, compiles its body into steps, shows in its type's onboarding
     * list, and its /form tokens produce record forms on generated tasks.
     */
    public function promote(Request $request, DocPage $page): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        abort_if($page->workflow_type, 422, 'Already a workflow.');
        $data = $request->validate(['type' => 'required|in:people,device']);

        $parsed = \App\Support\SopDocParser::parse($page->body ?? '');
        $page->forceFill([
            'workflow_type' => $data['type'],
            'workflow_active' => true,
            'workflow_shipped' => false,
            'workflow_wizard' => $data['type'] === 'people',   // people SOPs get the run wizard
            'workflow_steps' => json_encode($parsed),
        ])->save();

        return response()->json(['ok' => true]);
    }

    /** The OS a form factor implies, for the SOP header's OS row. */
    public static function osFromFormFactor(?string $ff): string
    {
        return match (true) {
            (bool) preg_match('/mac/i', $ff ?? '') => 'macOS',
            (bool) preg_match('/windows/i', $ff ?? '') => 'Windows',
            (bool) preg_match('/linux/i', $ff ?? '') => 'Linux',
            (bool) preg_match('/ios/i', $ff ?? '') => 'iOS',
            (bool) preg_match('/android/i', $ff ?? '') => 'Android',
            default => $ff ?? '',
        };
    }

    /**
     * The blank SOP scaffold: the FULL header table (empty rows ready to fill —
     * the compiled renderer skips empty fields, an authoring scaffold must not),
     * a Procedure heading, and one lean step card.
     */
    public static function blankSopBody(array $meta = []): string
    {
        $esc = fn ($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
        // Content rows first (values span the width); OS · Owner · Version share
        // one compact row as the LAST row.
        $rows = '';
        foreach (['Why' => '', 'How' => '', 'Scope' => '', 'Tools and Materials' => '', 'Safety Precautions' => ''] as $label => $value) {
            $rows .= "<tr><td><p><strong>{$label}:</strong></p></td><td colspan=\"7\"><p>{$esc($value)}</p></td></tr>";
        }
        $rows .= '<tr>'
            . "<td><p><strong>OS:</strong></p></td><td><p>{$esc($meta['os'] ?? '')}</p></td>"
            . "<td><p><strong>Owner:</strong></p></td><td><p>{$esc($meta['owner'] ?? '')}</p></td>"
            . "<td><p><strong>Version:</strong></p></td><td><p>{$esc($meta['version'] ?? '1.0')}</p></td>"
            . "<td><p><strong>Status:</strong></p></td><td><p>{$esc($meta['status'] ?? 'Draft')}</p></td>"
            . '</tr>';
        return "<table><tbody>{$rows}</tbody></table>"
            . '<h2>Procedure</h2><section data-sop-step><p><strong>New step</strong></p><p></p></section>';
    }

    /** Search every page the user can see — title first, then body. */
    public function search(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());
        if ($q === '') return response()->json([]);
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        return response()->json(
            DocPage::query()
                ->whereNull('superseded_by_id')   // search finds current versions only
                ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('body', 'like', $like))
                ->orderByRaw('title LIKE ? DESC', [$like])->orderBy('title')
                ->limit(25)
                ->get(['id', 'title', 'category', 'space_id'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'parent_id' => 'nullable|integer|exists:doc_pages,id',
            'space_id' => 'nullable|integer|exists:spaces,id',
            'body' => 'nullable|string',
            'icon' => 'nullable|string|max:16',
            'category' => 'nullable|string|max:40',
        ]);
        $page = DocPage::create([
            'title' => $data['title'] ?: 'Untitled',
            'parent_id' => $data['parent_id'] ?? null,
            'space_id' => $data['space_id'] ?? null,
            'body' => $data['body'] ?? null,
            'icon' => $data['icon'] ?? null,
            'category' => $data['category'] ?? null,
            'updated_by' => auth()->id(),
        ]);
        return response()->json(['id' => $page->id], 201);
    }

    /**
     * Editing presence heartbeat. Every open editor pings with its editor_id
     * (a per-mount random token) every ~45s; the response lists the OTHER
     * active editors so every view shows the lock — including "you, in another
     * window", which is how deleted steps used to resurrect. Cache-only, 90s
     * freshness, no schema.
     */
    public function editing(Request $request, DocPage $page): JsonResponse
    {
        $data = $request->validate(['editor_id' => 'required|string|max:40', 'release' => 'sometimes|boolean']);
        $key = "doc-editing:{$page->id}";
        $now = time();
        $others = collect(\Illuminate\Support\Facades\Cache::get($key, []))
            ->filter(fn ($e) => ($now - ($e['ts'] ?? 0)) < 90 && ($e['editor_id'] ?? '') !== $data['editor_id'])
            ->values();
        $store = $others->all();
        if (! $request->boolean('release')) {
            $store[] = ['editor_id' => $data['editor_id'], 'user_id' => auth()->id(),
                'user' => trim((auth()->user()->name ?? '') . ' ' . (auth()->user()->last ?? '')), 'ts' => $now];
        }
        \Illuminate\Support\Facades\Cache::put($key, $store, 120);
        return response()->json(['others' => $others
            ->map(fn ($e) => ['user' => $e['user'] ?? '?', 'you' => ($e['user_id'] ?? null) === auth()->id()])]);
    }

    public function update(Request $request, DocPage $page): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'body' => 'sometimes|nullable|string',
            'icon' => 'sometimes|nullable|string|max:16',
            'category' => 'sometimes|nullable|string|max:40',
            'parent_id' => 'sometimes|nullable|integer|exists:doc_pages,id',
            'space_id' => 'sometimes|nullable|integer|exists:spaces,id',
            'rev' => 'sometimes|nullable|integer',
            'shared_company_ids' => 'sometimes|array',
            'shared_company_ids.*' => 'integer|exists:companies,id',
        ]);
        // Sharing: visible in these companies IN ADDITION to the owner.
        if (array_key_exists('shared_company_ids', $data)) {
            $page->sharedCompanies()->sync(
                collect($data['shared_company_ids'])->reject(fn ($id) => (int) $id === (int) $page->company_id)->values()->all()
            );
            unset($data['shared_company_ids']);
        }
        // Optimistic lock: a stale buffer (another tab, the Onboarding SOP view,
        // a server-side edit) must never silently clobber newer content — that's
        // how deleted steps resurrect. The client sends the rev it loaded;
        // mismatch = 409 carrying the current truth, the client decides.
        if (array_key_exists('body', $data) && ($data['rev'] ?? null) !== null) {
            $current = $page->updated_at?->getTimestamp() ?? 0;
            if ((int) $data['rev'] !== $current) {
                return response()->json(['conflict' => true, 'rev' => $current, 'body' => $page->body], 409);
            }
        }
        unset($data['rev']);
        // Reparenting must not create a cycle: the new parent can't be the page
        // itself or any of its descendants.
        if (array_key_exists('parent_id', $data) && $data['parent_id']) {
            abort_if((int) $data['parent_id'] === (int) $page->id, 422, 'A page cannot be its own parent.');
            $cursor = DocPage::find($data['parent_id']);
            for ($i = 0; $cursor && $i < 50; $i++) {
                abort_if((int) $cursor->id === (int) $page->id, 422, 'That would move the page inside itself.');
                $cursor = $cursor->parent_id ? DocPage::find($cursor->parent_id) : null;
            }
        }
        $page->update($data + ['updated_by' => auth()->id()]);

        // If this page IS a workflow, editing the document is editing the runbook —
        // re-import the steps from the body now, so the wizard follows the doc without
        // a manual re-parse. (References like /eprotection stay as text here; they
        // resolve when a machine is built.) A body the parser can't read leaves the
        // existing steps untouched — the engine never loses its input to a bad edit.
        $recompiled = false;
        if (array_key_exists('body', $data) && $page->workflow_type) {
            $parsed = \App\Support\SopDocParser::parse($page->body ?? '');
            if (! empty($parsed['steps'])) {
                $page->forceFill(['workflow_steps' => json_encode($parsed)])->save();
                $recompiled = true;
            }
        }

        return response()->json(['ok' => true, 'recompiled' => $recompiled,
            'rev' => $page->fresh()->updated_at?->getTimestamp() ?? 0]);
    }

    public function destroy(DocPage $page): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $page->delete(); // children cascade to null parent (become root)
        return response()->json(['ok' => true]);
    }
}
