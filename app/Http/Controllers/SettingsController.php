<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\IdentityProvider;
use App\Models\RolePermission;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Index', [
            'access' => [
                'roles' => Access::ROLES,
                'permissions' => Access::PERMISSIONS,
                'matrix' => Access::matrix(),
                'locked' => Access::SUPER_ADMIN,
                'editable' => Access::allows(auth()->user()?->role, 'settings.manage'),
            ],
            'companies' => Company::query()->withoutGlobalScopes()
                ->orderBy('name')
                ->get(['id', 'name', 'tag_prefix', 'domain', 'city', 'state', 'active']),
            'providers' => IdentityProvider::query()->get(),
            'providerTypes' => IdentityProvider::PROVIDERS,
        ]);
    }

    /**
     * Save the permission matrix, storing only what differs from the shipped defaults.
     *
     * SuperAdmin is not writable: the screen that grants permissions must not be able to
     * revoke the permission to reach it, or the last admin locks the install.
     */
    public function updateRoles(Request $request): JsonResponse
    {
        abort_unless(Access::allows(auth()->user()?->role, 'settings.manage'), 403);

        $data = $request->validate([
            'matrix' => 'required|array',
        ]);

        $editable = array_values(array_diff(Access::ROLES, [Access::SUPER_ADMIN]));

        foreach ($editable as $role) {
            foreach (Access::keys() as $key) {
                if (! array_key_exists($role, $data['matrix']) || ! array_key_exists($key, $data['matrix'][$role])) {
                    continue;
                }
                $allowed = (bool) $data['matrix'][$role][$key];
                $isDefault = in_array($key, Access::DEFAULTS[$role] ?? [], true);

                if ($allowed === $isDefault) {
                    RolePermission::where(compact('role'))->where('permission', $key)->delete();
                    continue;
                }
                RolePermission::updateOrCreate(
                    ['role' => $role, 'permission' => $key],
                    ['allowed' => $allowed],
                );
            }
        }

        Access::forget();
        Log::info('access.matrix.updated', ['by' => auth()->id()]);

        return response()->json(['matrix' => Access::matrix()]);
    }

    /** Reset every role back to the shipped defaults. */
    public function resetRoles(): JsonResponse
    {
        abort_unless(Access::allows(auth()->user()?->role, 'settings.manage'), 403);
        RolePermission::query()->delete();
        Access::forget();
        Log::info('access.matrix.reset', ['by' => auth()->id()]);

        return response()->json(['matrix' => Access::matrix()]);
    }

    /** Create or update a company's identity provider. */
    public function saveProvider(Request $request): JsonResponse
    {
        abort_unless(Access::allows(auth()->user()?->role, 'settings.manage'), 403);

        $data = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'provider' => 'required|in:'.implode(',', array_keys(IdentityProvider::PROVIDERS)),
            'enabled' => 'boolean',
            'domain' => 'nullable|string|max:255',
            'tenant_id' => 'nullable|string|max:255',
            'client_id' => 'nullable|string|max:255',
            'client_secret' => 'nullable|string|max:512',
            'sync_on_login' => 'boolean',
        ]);

        // Blank means "leave the stored secret alone" — the form never receives it back,
        // so an empty field is absence of an edit, not an instruction to erase.
        if (blank($data['client_secret'] ?? null)) {
            unset($data['client_secret']);
        }

        $provider = IdentityProvider::updateOrCreate(
            ['company_id' => $data['company_id'], 'provider' => $data['provider']],
            $data,
        );

        return response()->json($provider->fresh());
    }
}
