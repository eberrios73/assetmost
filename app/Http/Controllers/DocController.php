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
        $counts = DocPage::query()->selectRaw('space_id, COUNT(*) c')->groupBy('space_id')
            ->pluck('c', 'space_id');

        return response()->json(
            Space::query()->orderBy('position')->orderBy('name')->get()
                ->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->name, 'icon' => $s->icon,
                    'color' => $s->color, 'pages' => (int) ($counts[$s->id] ?? 0),
                ])
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
            ->when($request->filled('space'), fn ($q) => $q->where('space_id', (int) $request->query('space')))
            ->orderBy('position')->orderBy('title')
            ->get(['id', 'parent_id', 'title', 'icon', 'category']);

        $byParent = $pages->groupBy(fn ($p) => $p->parent_id ?? 0);
        $build = function ($parentId) use (&$build, $byParent) {
            return ($byParent[$parentId ?? 0] ?? collect())->map(fn ($p) => [
                'id' => $p->id, 'title' => $p->title, 'icon' => $p->icon, 'category' => $p->category,
                'children' => $build($p->id),
            ])->values();
        };

        return response()->json($build(0));
    }

    public function show(DocPage $page): JsonResponse
    {
        $page->load('editor:id,name,last');
        return response()->json([
            'id' => $page->id, 'parent_id' => $page->parent_id,
            'title' => $page->title, 'body' => $page->body, 'icon' => $page->icon,
            'category' => $page->category,
            'updated_at' => $page->updated_at,
            'editor' => $page->editor ? trim("{$page->editor->name} {$page->editor->last}") : null,
        ]);
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
        ]);
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

        return response()->json(['ok' => true, 'recompiled' => $recompiled]);
    }

    public function destroy(DocPage $page): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $page->delete(); // children cascade to null parent (become root)
        return response()->json(['ok' => true]);
    }
}
