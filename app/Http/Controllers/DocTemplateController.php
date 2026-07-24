<?php

namespace App\Http\Controllers;

use App\Models\DocTemplate;
use App\Support\Contracts\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Custom doc templates: shipped ones live in the app; these are the company's
 * own, editable in Docs > Templates and offered by every + New menu.
 */
class DocTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $companyId = app(TenantResolver::class)->id();
        return response()->json(
            DocTemplate::query()
                ->where(fn ($w) => $w->whereNull('company_id')
                    ->when($companyId, fn ($q) => $q->orWhere('company_id', $companyId)))
                ->orderBy('label')
                ->get(['id', 'label', 'hint', 'category', 'body'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $data = $request->validate(['label' => 'required|string|max:80']);
        $tpl = DocTemplate::create([
            'label' => $data['label'],
            'company_id' => app(TenantResolver::class)->id(),
            'category' => 'SOP',
            'body' => '<p></p>',
        ]);
        return response()->json($tpl, 201);
    }

    public function update(Request $request, DocTemplate $template): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $data = $request->validate([
            'label' => 'sometimes|string|max:80',
            'hint' => 'sometimes|nullable|string|max:255',
            'category' => 'sometimes|nullable|string|max:40',
            'body' => 'sometimes|nullable|string',
        ]);
        $template->update($data);
        return response()->json(['ok' => true]);
    }

    public function destroy(DocTemplate $template): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $template->delete();
        return response()->json(['ok' => true]);
    }
}
