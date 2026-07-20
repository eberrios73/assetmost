<?php

namespace App\Http\Controllers;

use App\Models\ScriptSnippet;
use App\Support\Contracts\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The commands registry (Docs > Commands). Visibility is shipped/global rows
 * (company_id NULL) plus the active company's own. Whatever exists here shows
 * in the SOP editor's slash menu and injects into generated bootstrap scripts.
 */
class ScriptSnippetController extends Controller
{
    /** Slash names owned by the core system — a snippet may not shadow them. */
    private const RESERVED = ['install', 'vpn', 'mdm', 'form', 'step', 'sop', 'table', 'fields',
        'p', 'h1', 'h2', 'h3', 'ul', 'ol', 'quote', 'code', 'hr'];

    public function index(): JsonResponse
    {
        $companyId = app(TenantResolver::class)->id();
        return response()->json(
            ScriptSnippet::query()
                ->where(fn ($w) => $w->whereNull('company_id')
                    ->when($companyId, fn ($q) => $q->orWhere('company_id', $companyId)))
                ->orderBy('command')
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id, 'command' => $s->command, 'label' => $s->label,
                    'params' => $s->params, 'active' => $s->active, 'shipped' => $s->company_id === null,
                    'mac_script' => $s->mac_script, 'windows_script' => $s->windows_script, 'linux_script' => $s->linux_script,
                ])
        );
    }

    public function store(Request $request): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $data = $request->validate([
            'command' => 'required|string|max:40|regex:/^[a-z0-9][a-z0-9-]{1,39}$/',
            'label' => 'nullable|string|max:255',
        ]);
        abort_if(in_array($data['command'], self::RESERVED, true), 422, 'That name is a built-in command.');
        $companyId = app(TenantResolver::class)->id();
        abort_if(ScriptSnippet::query()->where('command', $data['command'])
            ->where(fn ($w) => $w->whereNull('company_id')->orWhere('company_id', $companyId))->exists(),
            422, 'That command already exists.');

        $s = ScriptSnippet::create($data + ['company_id' => $companyId, 'active' => true]);
        return response()->json(['ok' => true, 'id' => $s->id], 201);
    }

    public function update(Request $request, ScriptSnippet $snippet): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $data = $request->validate([
            'label' => 'nullable|string|max:255',
            'params' => 'nullable|string|max:255',
            'mac_script' => 'nullable|string',
            'windows_script' => 'nullable|string',
            'linux_script' => 'nullable|string',
            'active' => 'sometimes|boolean',
        ]);
        $snippet->update($data);
        return response()->json(['ok' => true]);
    }

    public function destroy(ScriptSnippet $snippet): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $snippet->delete();
        return response()->json(['ok' => true]);
    }
}
