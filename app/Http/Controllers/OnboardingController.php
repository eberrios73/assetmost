<?php

namespace App\Http\Controllers;

use App\Models\OnboardingTemplate;
use App\Support\Contracts\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Onboarding v2. The template (per-company steps as JSON) is this controller's
 * first job; the run-wizard that turns a template into a person + credentials +
 * a chained task project is the next.
 */
class OnboardingController extends Controller
{
    public function template(): JsonResponse
    {
        $companyId = app(TenantResolver::class)->id();
        $t = OnboardingTemplate::query()->where('company_id', $companyId)->first();

        return response()->json([
            'company_id' => $companyId,
            'name' => $t->name ?? 'Onboarding',
            'steps' => $t ? json_decode($t->steps, true) : null,
        ]);
    }

    public function saveTemplate(Request $request): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $companyId = app(TenantResolver::class)->id();
        abort_if(! $companyId, 422, 'Pick a company first — templates are per company.');

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'steps' => 'required|array',
            'steps.version' => 'required|integer',
            'steps.steps' => 'required|array',
        ]);

        $t = OnboardingTemplate::updateOrCreate(
            ['company_id' => $companyId],
            ['name' => $data['name'] ?? 'Onboarding', 'steps' => json_encode($data['steps'])],
        );

        return response()->json(['ok' => true, 'id' => $t->id]);
    }
}
