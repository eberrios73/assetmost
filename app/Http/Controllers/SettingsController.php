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
            'landlord' => $this->landlordPayload(),
        ]);
    }

    /**
     * Settings > Landlord: the platform's own users and their tenant assignments.
     * Null for tenant users — the section simply doesn't exist for them.
     */
    private function landlordPayload(): ?array
    {
        $u = auth()->user();
        if (! $u?->isLandlord()) {
            return null;
        }
        return [
            'users' => \App\Models\User::query()->where('is_landlord', true)
                ->with('managedCompanies:companies.id')->orderBy('name')
                ->get(['id', 'name', 'last', 'email', 'role', 'can_login', 'active'])
                ->map(fn ($x) => [
                    'id' => $x->id, 'name' => trim($x->name.' '.$x->last), 'email' => $x->email,
                    'role' => $x->role, 'can_login' => $x->can_login, 'active' => $x->active,
                    'company_ids' => $x->managedCompanies->pluck('id')->values(),
                ]),
            'companies' => Company::query()->withoutGlobalScopes()->where('is_landlord', false)
                ->when(! $u->isSuperAdmin(), fn ($q) => $q->whereIn('id', $u->managedCompanyIds()))
                ->orderBy('name')->get(['id', 'name']),
            'roles' => Access::LANDLORD_ROLES,
            'editable' => $u->may('landlord.manage'),
            'isSuper' => $u->isSuperAdmin(),
        ];
    }

    /** Tenants the actor may hand out: requested ∩ visible-to-actor, tenants only. */
    private function assignableTenantIds(\App\Models\User $actor, array $requested): array
    {
        $tenants = Company::query()->withoutGlobalScopes()->where('is_landlord', false)
            ->when(! $actor->isSuperAdmin(), fn ($q) => $q->whereIn('id', $actor->managedCompanyIds()))
            ->pluck('id')->all();
        return array_values(array_intersect(array_map('intval', $requested), $tenants));
    }

    /** Create a landlord user. Landlord users sign in by definition. */
    public function storeLandlordUser(Request $request): JsonResponse
    {
        $u = auth()->user();
        abort_unless($u?->isLandlord() && $u->may('landlord.manage'), 403);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'role' => 'required|in:'.implode(',', Access::LANDLORD_ROLES),
            'password' => 'required|string|min:8',
            'company_ids' => 'nullable|array',
            'company_ids.*' => 'integer|exists:companies,id',
        ]);
        // Only a SuperAdmin can mint another SuperAdmin.
        abort_if($data['role'] === Access::SUPER_ADMIN && ! $u->isSuperAdmin(), 403);

        $user = \App\Models\User::create([
            'name' => $data['name'], 'email' => $data['email'], 'role' => $data['role'],
            'password' => $data['password'], 'is_landlord' => true, 'can_login' => true,
            'active' => true, 'company_id' => Company::landlord()?->id,
        ]);
        $user->managedCompanies()->sync($this->assignableTenantIds($u, $data['company_ids'] ?? []));
        Log::info('landlord.user.created', ['id' => $user->id, 'by' => $u->id]);

        return response()->json($user->fresh(), 201);
    }

    /** Change a landlord user's role, sign-in flag, or tenant assignments. */
    public function updateLandlordUser(Request $request, \App\Models\User $user): JsonResponse
    {
        $actor = auth()->user();
        abort_unless($actor?->isLandlord() && $actor->may('landlord.manage'), 403);
        abort_unless($user->isLandlord(), 404);
        // Touching a SuperAdmin — or granting it — is itself a SuperAdmin act.
        abort_if(($user->isSuperAdmin() || $request->input('role') === Access::SUPER_ADMIN)
            && ! $actor->isSuperAdmin(), 403);

        $data = $request->validate([
            'role' => 'nullable|in:'.implode(',', Access::LANDLORD_ROLES),
            'can_login' => 'nullable|boolean',
            'company_ids' => 'nullable|array',
            'company_ids.*' => 'integer|exists:companies,id',
        ]);

        // The install must keep its keys: the last active SuperAdmin can't be demoted or locked out.
        $demoting = ($data['role'] ?? null) && $data['role'] !== Access::SUPER_ADMIN;
        $lockingOut = array_key_exists('can_login', $data) && $data['can_login'] === false;
        if ($user->isSuperAdmin() && ($demoting || $lockingOut)) {
            $supers = \App\Models\User::query()->where('is_landlord', true)
                ->where('role', Access::SUPER_ADMIN)->where('active', true)->where('can_login', true)->count();
            abort_if($supers <= 1, 422, 'The last SuperAdmin cannot be demoted or locked out.');
        }

        $user->update(array_filter([
            'role' => $data['role'] ?? null,
        ]) + (array_key_exists('can_login', $data) && $data['can_login'] !== null ? ['can_login' => $data['can_login']] : []));

        if (array_key_exists('company_ids', $data) && $data['company_ids'] !== null) {
            // The actor only governs assignments within their own horizon: whatever this
            // user holds outside it stays untouched.
            $visible = $this->assignableTenantIds($actor, $data['company_ids']);
            $horizon = $actor->isSuperAdmin() ? null : $actor->managedCompanyIds();
            $keep = $horizon === null ? [] : $user->managedCompanies()->pluck('companies.id')
                ->reject(fn ($id) => in_array($id, $horizon, true))->all();
            $user->managedCompanies()->sync(array_values(array_unique(array_merge($keep, $visible))));
        }
        Log::info('landlord.user.updated', ['id' => $user->id, 'by' => $actor->id]);

        return response()->json($user->fresh()->load('managedCompanies:companies.id'));
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
        $path = \App\Models\Company::query()->withoutGlobalScopes()->whereNotNull('installers_url')->value('installers_url');
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
            'enabled' => 'nullable|boolean',
            'domain' => 'nullable|string|max:255',
            'tenant_id' => 'nullable|string|max:255',
            'client_id' => 'nullable|string|max:255',
            'client_secret' => 'nullable|string|max:512',
            'sync_on_login' => 'nullable|boolean',
        ]);

        // Blank means "leave the stored secret alone" — the form never receives it back,
        // so an empty field is absence of an edit, not an instruction to erase.
        if (blank($data['client_secret'] ?? null)) {
            unset($data['client_secret']);
        }
        // Untouched checkboxes arrive null — leave the stored flags alone.
        foreach (['enabled', 'sync_on_login'] as $b) {
            if (array_key_exists($b, $data) && $data[$b] === null) unset($data[$b]);
        }

        $provider = IdentityProvider::updateOrCreate(
            ['company_id' => $data['company_id'], 'provider' => $data['provider']],
            $data,
        );

        return response()->json($provider->fresh());
    }
}
