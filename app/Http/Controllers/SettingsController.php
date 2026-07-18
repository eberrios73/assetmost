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
                ->get(['id', 'name', 'tag_prefix', 'domain', 'local_domain', 'installers_url', 'city', 'state', 'active']),
            'providers' => IdentityProvider::query()->get(),
            'providerTypes' => IdentityProvider::PROVIDERS
                + \App\Models\ProvisionerDefinition::query()->where('enabled', true)->pluck('name', 'plugin_key')->all(),
            'pluginDefs' => \App\Models\ProvisionerDefinition::query()->get(['id', 'plugin_key', 'name', 'enabled']),
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

    /** Current installers config — fetched on mount so the saved path always shows. */
    public function installersConfig(): \Illuminate\Http\JsonResponse
    {
        abort_unless(\App\Support\Access::allows(auth()->user()?->role, 'settings.manage'), 403);
        return response()->json([
            'count' => \Illuminate\Support\Facades\DB::table('installers')->count(),
            'last_scan' => \Illuminate\Support\Facades\DB::table('installers')->max('indexed_at'),
            'companies' => \App\Models\Company::query()->withoutGlobalScopes()->orderBy('name')->get(['id','name','installers_url']),
        ]);
    }

    /** Set a company's installers share path (host/path, listed over SSH). */
    public function saveInstallersPath(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless(\App\Support\Access::allows(auth()->user()?->role, 'settings.manage'), 403);
        $data = $request->validate(['company_id' => 'required|exists:companies,id', 'path' => 'nullable|string|max:500']);
        Company::query()->withoutGlobalScopes()->whereKey($data['company_id'])->update(['installers_url' => $data['path'] ?: null]);
        return response()->json(['ok' => true]);
    }

    /** Scan the mounted installers share into the index. The directory IS the catalog. */
    public function scanInstallers(): \Illuminate\Http\JsonResponse
    {
        abort_unless(\App\Support\Access::allows(auth()->user()?->role, 'settings.manage'), 403);
        $path = \App\Models\Company::query()->withoutGlobalScopes()->whereNotNull('installers_path')->value('installers_path');
        if (! $path) {
            return response()->json(['ok' => false, 'error' => 'Set an installers path first (host/path).'], 422);
        }
        [$rows, $err] = \App\Console\Commands\IndexInstallers::scan($path);
        if ($err) {
            return response()->json(['ok' => false, 'error' => $err], 422);
        }
        \Illuminate\Support\Facades\Artisan::call('installers:index', ['--path' => $path]);
        return response()->json([
            'ok' => true,
            'count' => \Illuminate\Support\Facades\DB::table('installers')->count(),
            'last_scan' => \Illuminate\Support\Facades\DB::table('installers')->max('indexed_at'),
        ]);
    }

    /** Add or replace a declarative provisioning plugin (paste-in JSON). */
    public function savePluginDef(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless(\App\Support\Access::allows(auth()->user()?->role, 'settings.manage'), 403);
        $data = $request->validate(['definition' => 'required|array']);
        $d = $data['definition'];
        foreach (['plugin_key', 'name', 'request'] as $need) {
            abort_unless(isset($d[$need]), 422, "Plugin JSON needs '{$need}'.");
        }
        abort_unless(preg_match('/^[a-z0-9_-]{2,40}$/', $d['plugin_key']), 422, 'plugin_key: lowercase letters, digits, dashes.');

        $def = \App\Models\ProvisionerDefinition::updateOrCreate(
            ['plugin_key' => $d['plugin_key']],
            ['name' => $d['name'], 'definition' => json_encode($d), 'enabled' => true],
        );
        \Illuminate\Support\Facades\Log::info('provisioner.plugin.saved', ['key' => $d['plugin_key'], 'by' => auth()->id()]);

        return response()->json(['ok' => true, 'id' => $def->id]);
    }

    /** Create or update a company's identity provider. */
    public function saveProvider(Request $request): JsonResponse
    {
        abort_unless(Access::allows(auth()->user()?->role, 'settings.manage'), 403);

        $data = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'provider' => 'required|in:'.implode(',', array_merge(
                array_keys(IdentityProvider::PROVIDERS),
                \App\Models\ProvisionerDefinition::query()->pluck('plugin_key')->all(),
            )),
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
