<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON list/detail endpoints for the entity screens.
 * Lists are offset-based for infinite scroll. All tenant-owned queries are
 * auto-scoped by the model global scope (the active company).
 */
class DataController extends Controller
{
    private const LIMIT = 40;

    /** Shared update: validate against $rules, save, return fresh model. Editors only. */
    private function applyUpdate($model, Request $request, array $rules): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), $rules);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        $model->update($v->validated());
        return response()->json($model->fresh());
    }

    public function updatePerson(Request $r, User $person): JsonResponse
    {
        return $this->applyUpdate($person, $r, [
            'name' => 'required|string|max:255', 'last' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255', 'title' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255', 'cell' => 'nullable|string|max:30',
            'ext' => 'nullable|string|max:11', 'active' => 'boolean',
        ]);
    }

    public function updateDevice(Request $r, Device $device): JsonResponse
    {
        return $this->applyUpdate($device, $r, [
            'asset_tag' => 'nullable|string|max:25', 'computer_name' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:255', 'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255', 'serial_num' => 'nullable|string|max:255',
            'active' => 'boolean',
        ]);
    }

    public function updateVendor(Request $r, Vendor $vendor): JsonResponse
    {
        return $this->applyUpdate($vendor, $r, [
            'name' => 'required|string|max:255', 'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:255',
            'website' => 'nullable|string|max:255', 'active' => 'boolean',
        ]);
    }

    public function updateRoom(Request $r, \App\Models\Room $room): JsonResponse
    {
        return $this->applyUpdate($room, $r, [
            'name' => 'required|string|max:255', 'room_type' => 'nullable|string|max:255',
            'room_number' => 'nullable|string|max:255', 'capacity' => 'nullable|integer',
        ]);
    }

    public function updateLocation(Request $r, \App\Models\Location $location): JsonResponse
    {
        return $this->applyUpdate($location, $r, [
            'name' => 'required|string|max:255', 'type' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255', 'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:2', 'zip' => 'nullable|string|max:10',
        ]);
    }

    public function updateCompany(Request $r, \App\Models\Company $company): JsonResponse
    {
        $before = $company->installers_url;
        $res = $this->applyUpdate($company, $r, [
            'name' => 'required|string|max:255', 'domain' => 'nullable|string|max:255',
            'local_domain' => 'nullable|string|max:255',
            'installers_url' => 'nullable|string|max:500',
            'contact_name' => 'nullable|string|max:255', 'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:255', 'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255', 'state' => 'nullable|string|max:2',
            'zip' => 'nullable|string|max:10', 'active' => 'boolean',
        ]);
        // Set or change the installers URL → re-scan the catalog from it, best-effort.
        $url = $company->fresh()->installers_url;
        if ($url && $url !== $before) {
            try {
                \Illuminate\Support\Facades\Artisan::call('installers:index', ['--url' => $url]);
            } catch (\Throwable) { /* the Assets screens surface scan errors; don't block the save */ }
        }
        return $res;
    }

    /** Apply a whitelisted sort; falls back to $default. */
    private function sort($q, Request $request, array $allowed, string $default): void
    {
        $col = $request->string('sort')->toString();
        $col = in_array($col, $allowed, true) ? $col : $default;
        $dir = $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc';
        $q->orderBy($col, $dir);
    }

    // ---- Devices ----
    public function devices(Request $request): JsonResponse
    {
        $q = Device::query()->with(['location:id,name', 'room:id,name', 'deviceType:id,name,code']);
        $this->sort($q, $request, ['asset_tag', 'computer_name', 'type'], 'asset_tag');

        if ($s = $request->string('search')->toString()) {
            $q->where(fn ($w) => $w->where('asset_tag', 'like', "%{$s}%")
                ->orWhere('computer_name', 'like', "%{$s}%")
                ->orWhere('serial_num', 'like', "%{$s}%"));
        }
        // Filter on the controlled type (by code), not the legacy free-text column.
        if ($code = $request->string('type')->toString()) {
            $q->whereHas('deviceType', fn ($t) => $t->where('code', $code));
        }
        if ($request->boolean('active_only', true)) {
            $q->where('active', true);
        }

        return $this->page($q, $request, fn ($d) => [
            'id' => $d->id,
            'primary' => $d->asset_tag ?: ($d->computer_name ?: "#{$d->id}"),
            'secondary' => trim((($d->deviceType?->name ?: $d->type) ? ($d->deviceType?->name ?: $d->type) . ' · ' : '') . trim("{$d->brand} {$d->model}")),
            'badge' => $d->computer_name,
        ]);
    }

    /** Onboard (create) a device. Company derives from the chosen location or the active company. */
    public function storeDevice(Request $request): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            // asset_tag is optional: leave it blank and the company's counter issues one
            // (PG-WS-1001). Supplying one keeps it — that's how legacy gear stays as-is.
            'asset_tag' => 'nullable|string|max:25', 'computer_name' => 'nullable|string|max:255',
            'device_type_id' => 'nullable|integer|exists:device_types,id',
            'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255', 'serial_num' => 'nullable|string|max:255',
            'location_id' => 'nullable|integer|exists:locations,id',
            'room_id' => 'nullable|integer|exists:rooms,id',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        // company from the location if picked, else the active company
        if (! empty($data['location_id'])) {
            $data['company_id'] = \App\Models\Location::withoutGlobalScopes()->find($data['location_id'])?->company_id;
        }
        $data['company_id'] ??= app(\App\Support\Contracts\TenantResolver::class)->id();
        $device = Device::create($data + ['active' => true]);   // tag issued in Device::booted
        return response()->json($device->fresh(), 201);
    }

    /** The controlled type list — {id, code, name} — for filters and the onboard form. */
    public function deviceTypes(): JsonResponse
    {
        return response()->json(
            \App\Models\DeviceType::query()->where('active', true)->ordered()
                ->get(['id', 'code', 'name'])
        );
    }

    public function device(Device $device): JsonResponse
    {
        $device->load(['company:id,name', 'location:id,name', 'room:id,name', 'users:id,name,last,email,title'])
            ->loadCount('users');
        return response()->json($device);
    }

    // ---- People ----
    public function people(Request $request): JsonResponse
    {
        $q = User::query();
        $this->sort($q, $request, ['name', 'last', 'department', 'ext'], 'name');
        if ($s = $request->string('search')->toString()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                ->orWhere('last', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")
                ->orWhere('department', 'like', "%{$s}%"));
        }
        if ($dept = $request->string('department')->toString()) {
            $q->where('department', $dept);
        }
        if ($request->boolean('active_only', true)) {
            $q->where('active', true);
        }
        // scope to active company through company_id (users table has it)
        $q->tap(fn ($qq) => app(\App\Support\Contracts\TenantResolver::class)->scopeIds() !== null
            ? $qq->whereIn('company_id', app(\App\Support\Contracts\TenantResolver::class)->scopeIds())
            : $qq);

        return $this->page($q, $request, fn ($u) => [
            'id' => $u->id,
            'primary' => trim("{$u->name} {$u->last}"),
            'secondary' => $u->title,
            'badge' => $u->ext,
        ]);
    }

    public function person(User $person): JsonResponse
    {
        $person->load(['company:id,name', 'location:id,name'])
            ->loadCount(['logins', 'devices']);
        // Licenses reach a person through the accounts they hold, so there's no relation
        // to loadCount — seats are consumed by accounts, not people.
        $person->licenses_count = $person->licenses()->count();
        return response()->json($person);
    }

    // Person sub-resources (detail tabs). Secrets are never returned in list form.
    public function personLogins(User $person): JsonResponse
    {
        return response()->json(
            $person->logins()->with('vendor:vendorID,name')->orderBy('login_name')->get()
                ->map(fn ($l) => [
                    'id' => $l->id, 'login_name' => $l->login_name, 'login_id' => $l->login_id,
                    'url' => $l->url, 'type' => $l->type, 'vendor' => $l->vendor?->name,
                    'is_restricted' => $l->is_restricted,
                ])
        );
    }

    public function personDevices(User $person): JsonResponse
    {
        return response()->json(
            $person->devices()->get(['devices.deviceID', 'asset_tag', 'computer_name', 'type', 'brand', 'model'])
        );
    }

    /** Company-scoped active people as {id,label} for assignment pickers. */
    public function peopleOptions(): JsonResponse
    {
        $q = User::query()->where('active', true)->orderBy('name')->orderBy('last');
        $q->tap(fn ($qq) => app(\App\Support\Contracts\TenantResolver::class)->scopeIds() !== null
            ? $qq->whereIn('company_id', app(\App\Support\Contracts\TenantResolver::class)->scopeIds())
            : $qq);

        return response()->json(
            $q->get(['id', 'name', 'last'])
                ->map(fn ($u) => ['id' => $u->id, 'label' => trim("{$u->name} {$u->last}") ?: "#{$u->id}"])
        );
    }

    /** Company-scoped active devices as {id,label} for the assign picker. */
    public function deviceOptions(): JsonResponse
    {
        return response()->json(
            Device::query()->where('active', true)->orderBy('asset_tag')
                ->get(['deviceID', 'asset_tag', 'computer_name', 'type'])
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'label' => trim(($d->asset_tag ?: $d->computer_name ?: "#{$d->id}") . ($d->type ? " · {$d->type}" : '')),
                ])
        );
    }

    public function storePersonLogin(Request $request, User $person): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'vendor_id' => 'nullable|integer|exists:vendors,vendorID',
            'login_name' => 'required|string|max:255', 'login_id' => 'nullable|string|max:255',
            'login_pass' => 'nullable|string|max:255', 'url' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:255', 'notes' => 'nullable|string',
            'is_active' => 'boolean', 'is_restricted' => 'boolean',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        $login = \App\Models\Login::create($v->validated() + [
            'user_id' => $person->id,
            'company_id' => $person->company_id,
            'is_active' => $request->boolean('is_active', true),
        ]);
        return response()->json(['id' => $login->id], 201);
    }

    public function attachPersonDevice(Request $request, User $person): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $data = $request->validate(['device_id' => 'required|integer|exists:devices,id']);
        $person->devices()->syncWithoutDetaching([$data['device_id']]);
        return response()->json(['ok' => true]);
    }

    public function detachPersonDevice(User $person, Device $device): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $person->devices()->detach($device->id);
        return response()->json(['ok' => true]);
    }

    public function personLicenses(User $person): JsonResponse
    {
        return response()->json(
            $person->licenses()->with(['vendor:vendorID,name', 'product:id,name'])->orderBy('renewal_date')->get()
                ->map(fn ($l) => [
                    'id' => $l->id, 'name' => $l->name,
                    'vendor' => $l->vendor?->name, 'product' => $l->product?->name,
                    'amount' => $l->amount, 'renewal_date' => $l->renewal_date?->toDateString(),
                    'account_number' => $l->account_number, 'is_active' => $l->is_active,
                ])
        );
    }

    public function departments(): JsonResponse
    {
        $ids = app(\App\Support\Contracts\TenantResolver::class)->scopeIds();
        return response()->json(
            User::query()
                ->when($ids !== null, fn ($qq) => $qq->whereIn('company_id', $ids))
                ->whereNotNull('department')->where('department', '<>', '')
                ->distinct()->orderBy('department')->pluck('department')
        );
    }

    // ---- Vendors ----
    public function vendors(Request $request): JsonResponse
    {
        $q = Vendor::query();
        $this->sort($q, $request, ['name', 'contact_name'], 'name');
        if ($s = $request->string('search')->toString()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                ->orWhere('contact_name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%"));
        }
        if ($request->boolean('active_only', true)) {
            $q->where('active', true);
        }
        // vendors relate to companies via pivot; scope through it when focused
        $ids = app(\App\Support\Contracts\TenantResolver::class)->scopeIds();
        if ($ids !== null) {
            $q->whereHas('companies', fn ($c) => $c->whereIn('companies.id', $ids));
        }

        return $this->page($q, $request, fn ($v) => [
            'id' => $v->id,
            'primary' => $v->name,
            'secondary' => $v->contact_name,
            'badge' => null,
        ]);
    }

    public function vendor(Vendor $vendor): JsonResponse
    {
        $vendor->load(['companies:id,name'])->loadCount(['logins', 'licenses', 'products']);
        return response()->json($vendor);
    }

    public function vendorLogins(Vendor $vendor): JsonResponse
    {
        // "User" is whoever holds it via login_access; legacy userID is the fallback for
        // rows never re-assigned in this app (ITer still writes it).
        return response()->json(
            $vendor->logins()->with(['user:id,name,last', 'holders:id,name,last'])->orderBy('login_name')->get()
                ->map(function ($l) {
                    $names = $l->holders->map(fn ($u) => trim("{$u->name} {$u->last}"));
                    if ($names->isEmpty() && $l->user) {
                        $names = collect([trim("{$l->user->name} {$l->user->last}")]);
                    }
                    return ['id' => $l->id, 'login_name' => $l->login_name,
                        'login_id' => $l->login_id, 'url' => $l->url, 'type' => $l->type,
                        'is_restricted' => $l->is_restricted,
                        'user' => $names->join(', ') ?: null];
                })
        );
    }

    public function vendorLicenses(Vendor $vendor): JsonResponse
    {
        return response()->json(
            $vendor->licenses()
                ->with(['product:id,name', 'logins.holders:id,name,last,email'])
                ->orderBy('renewal_date')->get()
                ->map(function ($l) {
                    // Holders come through the accounts consuming the seats — a license can
                    // legitimately have several, or none if seats sit unprovisioned.
                    $holders = $l->logins->flatMap->holders->unique('id')->values();
                    return ['id' => $l->id, 'name' => $l->name, 'product' => $l->product?->name,
                        'holders' => $holders->map(fn ($u) => trim("{$u->name} {$u->last}"))->all(),
                        'email' => $holders->first()?->email,
                        'seats_total' => $l->seats_total,
                        'seats_used' => $l->seats_used,
                        'seats_available' => $l->seats_available,
                        'amount' => $l->amount, 'renewal_date' => $l->renewal_date?->toDateString(),
                        'account_number' => $l->account_number];
                })
        );
    }

    /** Full license detail for the edit drawer. */
    public function license(\App\Models\License $license): JsonResponse
    {
        // Holders come via the accounts consuming the seats. Inactive people are kept and
        // flagged so an offboarded person still holding a seat shows up rather than looking
        // unassigned — that's the whole point of tracking.
        $license->loadMissing(['logins.holders:id,name,last,active', 'product:id,name']);
        $holders = $license->logins->flatMap->holders->unique('id')->values();

        return response()->json([
            'id' => $license->id,
            'name' => $license->name,
            'vendor_id' => $license->vendor_id,
            'product_id' => $license->product_id,
            'holders' => $holders->map(fn ($u) => [
                'id' => $u->id,
                'label' => trim("{$u->name} {$u->last}").($u->active ? '' : ' (inactive)'),
            ])->all(),
            // The accounts consuming the seats — editable in the drawer.
            'login_ids' => $license->logins->map->id->all(),
            'login_options' => $license->logins->map(fn ($l) => [
                'id' => $l->id, 'label' => $l->login_name.($l->login_id ? " ({$l->login_id})" : ''),
            ])->all(),
            'seats_total' => $license->seats_total,
            'seats_used' => $license->seats_used,
            'seats_available' => $license->seats_available,       // null = count unknown
            'over_allocated' => $license->isOverAllocated(),
            'account_number' => $license->account_number,
            'serial_number' => $license->serial_number,
            'amount' => $license->amount,
            'renewal_date' => $license->renewal_date?->toDateString(),
            'renewalfrequency' => $license->renewalfrequency,
            'is_active' => $license->is_active,
            'notes' => $license->notes,
        ]);
    }

    /** Create a license (a company's purchase of a product). company_id comes from the
     *  tenant scope on create — never from the client. */
    public function storeLicense(Request $request): JsonResponse
    {
        $v = validator($request->all(), [
            'name' => 'required|string|max:255',
            'vendor_id' => 'nullable|integer|exists:vendors,vendorID',
            'product_id' => 'nullable|integer|exists:products,id',
            'seats_total' => 'nullable|integer|min:0',   // null = not counted yet
            'account_number' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:255',
            'amount' => 'nullable|numeric',
            'renewal_date' => 'nullable|date',
            'renewalfrequency' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
            'login_ids' => 'nullable|array', 'login_ids.*' => 'integer|exists:logins,loginID',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $loginIds = $data['login_ids'] ?? [];
        unset($data['login_ids']);

        $license = \App\Models\License::create($data);
        if ($loginIds) {
            $license->logins()->sync($loginIds);
        }

        return response()->json($license->fresh(), 201);
    }

    public function updateLicense(Request $request, \App\Models\License $license): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'vendor_id' => 'nullable|integer|exists:vendors,vendorID',
            'product_id' => 'nullable|integer|exists:products,id',
            'seats_total' => 'nullable|integer|min:0',   // null = not counted yet
            'account_number' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:255',
            'amount' => 'nullable|numeric',
            'renewal_date' => 'nullable|date',
            'renewalfrequency' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
            'login_ids' => 'nullable|array', 'login_ids.*' => 'integer|exists:logins,loginID',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        // The accounts consuming this license's seats live on the pivot, not the row.
        // Absent key = untouched; [] = detach all.
        if (array_key_exists('login_ids', $data)) {
            $license->logins()->sync($data['login_ids'] ?? []);
            unset($data['login_ids']);
        }
        $license->update($data);
        return response()->json($license->fresh());
    }

    public function login(\App\Models\Login $login): JsonResponse
    {
        // River schema: PK loginID, FK vendorID — map back to the API's id/vendor_id
        $login->loadMissing('holders:id,name,last');
        return response()->json([
            'id' => $login->loginID, 'vendor_id' => $login->vendorID,
            'login_name' => $login->login_name, 'login_id' => $login->login_id,
            'url' => $login->url, 'type' => $login->type, 'notes' => $login->notes,
            'is_active' => (bool) $login->is_active, 'is_restricted' => (bool) $login->is_restricted,
            // Who holds this credential, editable in the drawer.
            'holder_ids' => $login->holders->pluck('id')->all(),
            'holder_options' => $login->holders->map(fn ($u) => [
                'id' => $u->id, 'label' => trim("{$u->name} {$u->last}"),
            ])->all(),
        ]); // password intentionally omitted (revealed only via the gated /secret endpoint)
    }

    /** People as {id,label} for pickers (assign a login holder, etc.). Tenant-scoped. */
    public function personOptions(): JsonResponse
    {
        return response()->json(
            User::query()->where('active', true)->orderBy('name')->orderBy('last')
                ->get(['id', 'name', 'last'])
                ->map(fn ($u) => ['id' => $u->id, 'label' => trim("{$u->name} {$u->last}")])
        );
    }

    /** Logins as {id,label} for pickers (attach accounts to a license). Tenant-scoped. */
    public function loginOptions(): JsonResponse
    {
        return response()->json(
            \App\Models\Login::query()->where('is_active', true)->orderBy('login_name')
                ->get()
                ->map(fn ($l) => ['id' => $l->id, 'label' => $l->login_name.($l->login_id ? " ({$l->login_id})" : '')])
        );
    }

    /** All vendors as {id,label} for the searchable vendor picker. */
    public function vendorOptions(): JsonResponse
    {
        return response()->json(
            Vendor::query()->orderBy('name')->get(['vendorID', 'name'])
                ->map(fn ($v) => ['id' => $v->id, 'label' => $v->name])
        );
    }

    /** Products as {id,label} for the license form; labelled "Vendor — Product" because
     *  two vendors can sell same-named things ("Standard", "Pro"). */
    public function productOptions(): JsonResponse
    {
        return response()->json(
            \App\Models\Product::query()->with('vendor:vendorID,name')->orderBy('name')->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'label' => $p->vendor ? "{$p->vendor->name} — {$p->name}" : $p->name,
                ])
                ->sortBy('label')->values()
        );
    }

    public function updateLogin(Request $request, \App\Models\Login $login): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'vendor_id' => 'nullable|integer|exists:vendors,vendorID',
            'login_name' => 'nullable|string|max:255', 'login_id' => 'nullable|string|max:255',
            'login_pass' => 'nullable|string|max:255', 'url' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:255', 'notes' => 'nullable|string',
            'sharing' => 'nullable|in:'.implode(',', \App\Models\Login::SHARING),
            'is_active' => 'boolean', 'is_restricted' => 'boolean',
            'holder_ids' => 'nullable|array', 'holder_ids.*' => 'integer|exists:users,id',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        if (empty($data['login_pass'])) {
            unset($data['login_pass']); // blank = keep existing secret
        }
        // Break glass is restricted by definition — not overridable from the form.
        if (($data['sharing'] ?? null) === 'breakglass') {
            $data['is_restricted'] = true;
        }
        // Holders live on the pivot, not the row. Absent key = untouched; [] = unassign all.
        if (array_key_exists('holder_ids', $data)) {
            $login->holders()->sync($data['holder_ids'] ?? []);
            unset($data['holder_ids']);
        }
        $login->update($data);
        return response()->json(['ok' => true]);
    }

    public function destroyLogin(\App\Models\Login $login): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $login->delete();
        return response()->json(['ok' => true]);
    }

    /** Reveal a login's password (for copy). Gated by Roles & access + restricted flag; audited. */
    public function loginSecret(\App\Models\Login $login): JsonResponse
    {
        $u = auth()->user();
        // The matrix decides this now, so unticking it on Roles & access actually revokes it.
        abort_unless($u?->may(\App\Support\Access::REVEAL), 403);
        abort_if($login->is_restricted && ! $u->isAdmin(), 403);
        \Illuminate\Support\Facades\Log::info('credential.revealed', ['login' => $login->id, 'by' => $u->id]);

        // Through holders(), not the old single user_id — a credential can be held by many
        // people (a shared mailbox, a pooled seat). `cell` is here for the SMS-the-code
        // flow, so it only means anything when exactly one person holds this.
        $login->loadMissing('holders:id,name,last,cell');
        $sole = $login->holders->count() === 1 ? $login->holders->first() : null;

        return response()->json([
            'password' => $login->login_pass, // plaintext on River (matches ITer)
            'cell' => $sole?->cell,
            'name' => $sole ? trim("{$sole->name} {$sole->last}") : null,
        ]);
    }

    public function deviceUsers(Device $device): JsonResponse
    {
        return response()->json(
            $device->users()->get(['users.id', 'name', 'last', 'email', 'title'])
        );
    }

    // ---- Rooms (scoped through their location's company) ----
    public function rooms(Request $request): JsonResponse
    {
        $q = \App\Models\Room::query()->with('location:id,name,company_id');
        $this->sort($q, $request, ['name', 'room_type', 'room_number'], 'name');
        if ($s = $request->string('search')->toString()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('room_number', 'like', "%{$s}%"));
        }
        if ($request->boolean('active_only', true)) $q->where('active', true);
        $ids = app(\App\Support\Contracts\TenantResolver::class)->scopeIds();
        if ($ids !== null) $q->whereHas('location', fn ($l) => $l->whereIn('company_id', $ids));

        return $this->page($q, $request, fn ($r) => [
            'id' => $r->id, 'primary' => $r->name,
            'secondary' => trim(($r->room_type ? $r->room_type . ' · ' : '') . ($r->location?->name ?? '')),
            'badge' => $r->room_number,
        ]);
    }

    public function room(\App\Models\Room $room): JsonResponse
    {
        $room->load(['location:id,name', 'devices:id,room_id,asset_tag,computer_name,type']);
        return response()->json($room);
    }

    // ---- Locations (Company ▸ Location) ----
    public function locations(Request $request): JsonResponse
    {
        $q = \App\Models\Location::query()->withCount(['rooms', 'devices']);
        $this->sort($q, $request, ['name', 'city', 'type'], 'name');
        if ($s = $request->string('search')->toString()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('city', 'like', "%{$s}%"));
        }
        if ($request->boolean('active_only', true)) $q->where('active', true);

        return $this->page($q, $request, fn ($l) => [
            'id' => $l->id, 'primary' => $l->name,
            'secondary' => trim(implode(' · ', array_filter([$l->type, implode(', ', array_filter([$l->city, $l->state]))]))),
            'badge' => $l->rooms_count ? "{$l->rooms_count} rooms" : null,
        ]);
    }

    public function location(\App\Models\Location $location): JsonResponse
    {
        $location->load(['rooms:id,location_id,name,room_type,room_number,capacity'])->loadCount('devices');
        return response()->json($location);
    }

    // ---- Companies (the tenants the user may access) ----
    public function companies(Request $request): JsonResponse
    {
        $allowed = app(\App\Support\Contracts\TenantResolver::class)->allowedIds();
        $q = \App\Models\Company::query()->whereIn('id', $allowed);
        $this->sort($q, $request, ['name', 'city'], 'name');
        if ($s = $request->string('search')->toString()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('domain', 'like', "%{$s}%"));
        }
        if ($request->boolean('active_only', true)) $q->where('active', true);

        return $this->page($q, $request, fn ($c) => [
            'id' => $c->id, 'primary' => $c->name,
            'secondary' => trim(implode(', ', array_filter([$c->city, $c->state]))),
            'badge' => $c->domain,
        ]);
    }

    public function company(\App\Models\Company $company): JsonResponse
    {
        $company->loadCount(['users', 'devices', 'locations']);
        return response()->json($company);
    }

    /** {id, label} locations for pickers (e.g. placing a room). */
    public function locationOptions(): JsonResponse
    {
        return response()->json(
            \App\Models\Location::query()->where('active', true)->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($l) => ['id' => $l->id, 'label' => $l->name])
        );
    }

    /** {id, label} companies the current user may file records under. */
    public function companyOptions(): JsonResponse
    {
        return response()->json(
            \App\Models\Company::query()->whereIn('id', auth()->user()->managedCompanyIds())
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->name])
        );
    }

    /** Create a staff member. Company defaults to the active one. */
    public function storePerson(Request $request): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => 'required|string|max:255', 'last' => 'nullable|string|max:255',
            // Unique because email is how a person is matched to their accounts; two
            // people sharing one silently merges their credentials later.
            'email' => 'nullable|email|max:255|unique:users,email',
            'title' => 'nullable|string|max:255', 'department' => 'nullable|string|max:255',
            'cell' => 'nullable|string|max:30', 'ext' => 'nullable|string|max:11',
            'company_id' => 'nullable|integer|exists:companies,id',
            // Most staff are directory records who never sign in, so this is optional —
            // set one only for someone who actually uses the app.
            'password' => 'nullable|string|min:8',
            'role' => 'nullable|in:'.implode(',', \App\Support\Access::ROLES),
            'can_login' => 'boolean',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        $data['company_id'] ??= app(\App\Support\Contracts\TenantResolver::class)->id();
        $data['role'] ??= \App\Support\Access::USER;
        $data['can_login'] = (bool) ($data['can_login'] ?? false);

        // A password without can_login would be a login nobody granted, and can_login
        // without a password is an account that can never be used. Keep the two honest.
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
            $data['can_login'] = false;
        }
        if (! $data['can_login']) {
            unset($data['password']);
        }

        $person = User::create($data + ['active' => true]);

        return response()->json($person->fresh(), 201);
    }

    /** Create a vendor. Vendors are shared across companies (m2m), so no company_id here. */
    public function storeVendor(Request $request): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:vendors,name',
            'contact_name' => 'nullable|string|max:255', 'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255', 'website' => 'nullable|string|max:255',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        $vendor = Vendor::create($v->validated() + ['active' => true]);
        // Link to the active company so it shows up for them; "all companies" leaves it
        // unlinked and visible anyway.
        if ($companyId = app(\App\Support\Contracts\TenantResolver::class)->activeId()) {
            $vendor->companies()->syncWithoutDetaching([$companyId]);
        }

        return response()->json($vendor->fresh(), 201);
    }

    /** Create a location. company_id null = a site shared by every company. */
    public function storeLocation(Request $request): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => 'required|string|max:255', 'type' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255', 'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:2', 'zip' => 'nullable|string|max:10',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        // Defaults to the active company, or shared when viewing "all companies" — a
        // building several companies work out of belongs to none of them.
        $data = $v->validated();
        $data['company_id'] ??= app(\App\Support\Contracts\TenantResolver::class)->activeId();
        $location = \App\Models\Location::create($data + ['active' => true]);

        return response()->json($location->fresh(), 201);
    }

    /** Create a room. Rooms hang off a location and inherit its visibility. */
    public function storeRoom(Request $request): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'location_id' => 'required|integer|exists:locations,id',
            'room_type' => 'nullable|string|max:255', 'room_number' => 'nullable|string|max:255',
            'capacity' => 'nullable|integer|min:0',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        $room = \App\Models\Room::create($v->validated() + ['active' => true]);

        return response()->json($room->fresh(), 201);
    }

    /** Create a company. Only SuperAdmin / IT Admin may add. Companies are unlimited. */
    public function storeCompany(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            // Required: without it the company can't issue an asset tag, and you only find
            // out when someone onboards its first device.
            'tag_prefix' => 'required|string|max:4|alpha_num|unique:companies,tag_prefix',
            'domain' => 'nullable|string|max:255',
            'local_domain' => 'nullable|string|max:255',
            'installers_url' => 'nullable|string|max:500',
            'email' => 'nullable|email|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:2',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        $data['tag_prefix'] = strtoupper($data['tag_prefix']);
        // Counter starts at 1001 per the tag scheme; see the asset-tag migration.
        $company = \App\Models\Company::create($data + ['active' => true, 'tag_next' => 1001]);
        return response()->json($company, 201);
    }

    /** Sub-clients of a company (River-style hierarchy) — POC placeholder. */
    public function companyClients(\App\Models\Company $company): JsonResponse
    {
        return response()->json([]); // clients table not yet migrated; empty POC
    }

    /** Shared offset pager -> { items, total, has_more }. */
    // ---- Accounts (credential-centric view of logins) ----

    /**
     * The People > Accounts list: every credential account — the logins that aren't
     * someone's directory email, pooled seats, shared mailboxes. Person-centric access
     * lives on the staff screen; this is the same data seen from the account side.
     */
    /**
     * Floating accounts — one row per credential IDENTITY, not per service login.
     * itmgr@plutonicgames.com is one account used in 29 services; it lists once.
     */
    public function accounts(Request $request): JsonResponse
    {
        $q = \App\Models\Account::query()->withCount('logins')->with('holders:id,name,last');
        $this->sort($q, $request, ['identifier', 'sharing'], 'identifier');

        if ($s = $request->string('search')->toString()) {
            $q->where(fn ($w) => $w->where('identifier', 'like', "%{$s}%")
                ->orWhere('notes', 'like', "%{$s}%")
                ->orWhereHas('logins', fn ($l) => $l->where('login_name', 'like', "%{$s}%")));
        }
        if ($sharing = $request->string('sharing')->toString()) {
            $q->where('sharing', $sharing);
        }
        if ($request->boolean('active_only', true)) {
            $q->where('is_active', true);
        }

        return $this->page($q, $request, fn ($a) => [
            'id' => $a->id,
            'primary' => $a->identifier,
            'secondary' => $a->logins_count === 1 ? '1 service' : "{$a->logins_count} services",
            'badge' => $a->sharing,
        ]);
    }

    /** One credential: how it's shared, who holds it, and the services it's used in. */
    public function account(\App\Models\Account $account): JsonResponse
    {
        $account->loadMissing(['holders:id,name,last,active', 'logins.vendor:vendorID,name', 'logins.device:deviceID,asset_tag,computer_name']);
        return response()->json([
            'id' => $account->id,
            'identifier' => $account->identifier,
            'sharing' => $account->sharing,
            'notes' => $account->notes,
            'is_active' => $account->is_active,
            'holder_ids' => $account->holders->pluck('id')->all(),
            'holder_options' => $account->holders->map(fn ($u) => [
                'id' => $u->id, 'label' => trim("{$u->name} {$u->last}").($u->active ? '' : ' (inactive)'),
            ])->all(),
            'holders' => $account->holders->map(fn ($u) => trim("{$u->name} {$u->last}"))->all(),
            // A use of the credential is a service OR a device — ITAdmin's uses are
            // mostly servers. `target` fuses them for display; both raw fields ride along.
            'services' => $account->logins->map(fn ($l) => [
                'id' => $l->id,
                'target' => ($l->device?->asset_tag ?: $l->device?->computer_name) ?: $l->login_name,
                'is_device' => $l->device !== null,
                'name' => $l->login_name, 'vendor' => $l->vendor?->name,
                'type' => $l->type, 'url' => $l->url,
                'is_active' => (bool) $l->is_active, 'is_restricted' => (bool) $l->is_restricted,
            ])->values()->all(),
            'created_at' => $account->created_at, 'updated_at' => $account->updated_at,
        ]);
    }

    /** Create a floating account (the identity only; service logins link to it). */
    public function storeAccount(Request $request): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'identifier' => 'required|string|max:255|unique:accounts,identifier',
            'sharing' => 'required|in:'.implode(',', \App\Models\Account::SHARING),
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
            'holder_ids' => 'nullable|array', 'holder_ids.*' => 'integer|exists:users,id',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        $holderIds = $data['holder_ids'] ?? [];
        unset($data['holder_ids']);
        $data['identifier'] = trim($data['identifier']);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['company_id'] = app(\App\Support\Contracts\TenantResolver::class)->id();

        $account = \App\Models\Account::create($data);
        if ($holderIds) {
            $account->holders()->sync($holderIds);
        }

        return response()->json($account->fresh(), 201);
    }

    public function updateAccount(Request $request, \App\Models\Account $account): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'identifier' => 'required|string|max:255|unique:accounts,identifier,'.$account->id,
            'sharing' => 'required|in:'.implode(',', \App\Models\Account::SHARING),
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
            'holder_ids' => 'nullable|array', 'holder_ids.*' => 'integer|exists:users,id',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        if (array_key_exists('holder_ids', $data)) {
            $account->holders()->sync($data['holder_ids'] ?? []);
            unset($data['holder_ids']);
        }
        $data['identifier'] = trim($data['identifier']);
        $account->update($data);

        return response()->json($account->fresh());
    }

    /** Floating-account identifiers for pickers. Identifier + id ONLY — the gated
     *  registry endpoints hold everything else. */
    public function accountOptions(): JsonResponse
    {
        return response()->json(
            \App\Models\Account::query()->where('is_active', true)->orderBy('identifier')
                ->get(['id', 'identifier'])
                ->map(fn ($a) => ['id' => $a->id, 'label' => $a->identifier])
        );
    }

    /** The /install list: whatever the indexed installers share contains. */
    public function installers(Request $request): JsonResponse
    {
        $q = \Illuminate\Support\Facades\DB::table('installers')->orderBy('name');
        if ($platform = $request->string('platform')->toString()) {
            $q->where('platform', $platform);
        }
        if ($term = $request->string('q')->toString()) {
            $q->where('name', 'like', "%{$term}%");
        }
        if ($arch = $request->string('arch')->toString()) {
            $q->where(fn ($w) => $w->where('arch', $arch)->orWhereNull('arch'));
        }
        return response()->json($q->limit(50)->get());
    }

    /** Unlock the Accounts registry by re-entering your own password. Throttled. */
    public function unlockAccounts(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);

        if (! \Illuminate\Support\Facades\Hash::check($request->string('password'), auth()->user()->password)) {
            \Illuminate\Support\Facades\Log::warning('accounts.unlock.failed', ['by' => auth()->id()]);
            return response()->json(['errors' => ['password' => ['That password is not correct.']]], 422);
        }

        $request->session()->put('accounts_confirmed_at', time());
        \Illuminate\Support\Facades\Log::info('accounts.unlocked', ['by' => auth()->id()]);

        return response()->json(['ok' => true]);
    }

    /** The ways a FLOATING account can be held (personal belongs to logins, not here). */
    public function sharingOptions(): JsonResponse
    {
        return response()->json([
            ['value' => 'pooled', 'label' => 'Pooled — one at a time'],
            ['value' => 'shared', 'label' => 'Shared — many at once'],
            ['value' => 'service', 'label' => 'Service — runs the system, no human holder'],
            ['value' => 'breakglass', 'label' => 'Break glass — sealed emergency access'],
        ]);
    }

    private function page($query, Request $request, callable $map): JsonResponse
    {
        $offset = max(0, (int) $request->integer('offset'));
        $total = (clone $query)->count();
        $rows = $query->offset($offset)->limit(self::LIMIT)->get();

        return response()->json([
            'items' => $rows->map($map)->values(),
            'total' => $total,
            'has_more' => $offset + $rows->count() < $total,
            'next_offset' => $offset + $rows->count(),
        ]);
    }
}
