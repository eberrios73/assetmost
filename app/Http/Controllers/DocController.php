<?php

namespace App\Http\Controllers;

use App\Models\DocPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Docs wiki. All queries auto-scoped to the active company via the model scope.
 */
class DocController extends Controller
{
    /** Full page tree for the current company. */
    public function tree(): JsonResponse
    {
        $pages = DocPage::query()->orderBy('position')->orderBy('title')
            ->get(['id', 'parent_id', 'title', 'icon']);

        $byParent = $pages->groupBy(fn ($p) => $p->parent_id ?? 0);
        $build = function ($parentId) use (&$build, $byParent) {
            return ($byParent[$parentId ?? 0] ?? collect())->map(fn ($p) => [
                'id' => $p->id, 'title' => $p->title, 'icon' => $p->icon,
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
            'body' => 'nullable|string',
            'icon' => 'nullable|string|max:16',
        ]);
        $page = DocPage::create([
            'title' => $data['title'] ?: 'Untitled',
            'parent_id' => $data['parent_id'] ?? null,
            'body' => $data['body'] ?? null,
            'icon' => $data['icon'] ?? null,
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
        ]);
        $page->update($data + ['updated_by' => auth()->id()]);
        return response()->json(['ok' => true]);
    }

    public function destroy(DocPage $page): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $page->delete(); // children cascade to null parent (become root)
        return response()->json(['ok' => true]);
    }
}
