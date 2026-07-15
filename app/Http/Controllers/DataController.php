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
        return $this->applyUpdate($company, $r, [
            'name' => 'required|string|max:255', 'domain' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255', 'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:255', 'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255', 'state' => 'nullable|string|max:2',
            'zip' => 'nullable|string|max:10', 'active' => 'boolean',
        ]);
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
        $q = Device::query()->with(['location:id,name', 'room:id,name']);
        $this->sort($q, $request, ['asset_tag', 'computer_name', 'type'], 'asset_tag');

        if ($s = $request->string('search')->toString()) {
            $q->where(fn ($w) => $w->where('asset_tag', 'like', "%{$s}%")
                ->orWhere('computer_name', 'like', "%{$s}%")
                ->orWhere('serial_num', 'like', "%{$s}%"));
        }
        if ($category = $request->string('type')->toString()) {
            // param carries a clean category; match all raw types mapped to it
            $raw = \App\Support\DeviceCategory::rawTypesFor($category);
            if ($raw) {
                $ph = implode(',', array_fill(0, count($raw), '?'));
                $q->whereRaw(self::NORM_TYPE . " IN ($ph)", $raw);
            } else {
                $q->whereRaw('1=0'); // unknown category -> no rows
            }
        }
        if ($request->boolean('active_only', true)) {
            $q->where('active', true);
        }

        return $this->page($q, $request, fn ($d) => [
            'id' => $d->id,
            'primary' => $d->asset_tag ?: ($d->computer_name ?: "#{$d->id}"),
            'secondary' => trim(($d->type ? $d->type . ' · ' : '') . trim("{$d->brand} {$d->model}")),
            'badge' => $d->computer_name,
        ]);
    }

    /** Normalizes the messy type column (strip newlines, trim, lowercase). */
    private const NORM_TYPE = "LOWER(TRIM(REPLACE(REPLACE(type, '\\n', ''), '\\r', '')))";

    /** Onboard (create) a device. Company derives from the chosen location or the active company. */
    public function storeDevice(Request $request): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'asset_tag' => 'nullable|string|max:25', 'computer_name' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:255', 'brand' => 'nullable|string|max:255',
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
        $device = Device::create($data + ['active' => true]);
        return response()->json($device, 201);
    }

    public function deviceTypes(): JsonResponse
    {
        // clean, curated categories that actually have devices in the current scope
        $present = Device::query()
            ->whereNotNull('type')->where('type', '<>', '')
            ->selectRaw('DISTINCT ' . self::NORM_TYPE . ' as t')
            ->pluck('t')->all();

        $categories = array_values(array_filter(
            \App\Support\DeviceCategory::all(),
            fn ($cat) => count(array_intersect(\App\Support\DeviceCategory::rawTypesFor($cat), $present)) > 0
        ));

        return response()->json($categories);
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
            ->loadCount(['logins', 'devices', 'subscriptions']);
        return response()->json($person);
    }

    // Person sub-resources (detail tabs). Secrets are never returned in list form.
    public function personLogins(User $person): JsonResponse
    {
        return response()->json(
            $person->logins()->with('vendor:id,name')->orderBy('login_name')->get()
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
            $person->devices()->get(['devices.id', 'asset_tag', 'computer_name', 'type', 'brand', 'model'])
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
                ->get(['id', 'asset_tag', 'computer_name', 'type'])
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
            'vendor_id' => 'nullable|integer|exists:vendors,id',
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

    public function personSubscriptions(User $person): JsonResponse
    {
        return response()->json(
            $person->subscriptions()->with('vendor:id,name')->orderBy('renewal_date')->get()
                ->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->subscription_name, 'vendor' => $s->vendor?->name,
                    'amount' => $s->amount, 'renewal_date' => $s->renewal_date?->toDateString(),
                    'account_number' => $s->account_number, 'is_active' => $s->is_active,
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
        $vendor->load(['companies:id,name'])->loadCount(['logins', 'subscriptions']);
        return response()->json($vendor);
    }

    public function vendorLogins(Vendor $vendor): JsonResponse
    {
        return response()->json(
            $vendor->logins()->with('user:id,name,last')->orderBy('login_name')->get()
                ->map(fn ($l) => ['id' => $l->id, 'login_name' => $l->login_name,
                    'login_id' => $l->login_id, 'url' => $l->url, 'type' => $l->type, 'is_restricted' => $l->is_restricted,
                    'user' => $l->user ? trim("{$l->user->name} {$l->user->last}") : null])
        );
    }

    public function vendorSubscriptions(Vendor $vendor): JsonResponse
    {
        return response()->json(
            $vendor->subscriptions()
                ->with(['user:id,name,last,email', 'login.user:id,name,last,email'])
                ->orderBy('renewal_date')->get()
                ->map(function ($s) {
                    // subscription.user_id is often null — derive via the linked login
                    $u = $s->user ?: $s->login?->user;
                    return ['id' => $s->id, 'name' => $s->subscription_name,
                        'user' => $u ? trim("{$u->name} {$u->last}") : null,
                        'email' => $u?->email,
                        'amount' => $s->amount, 'renewal_date' => $s->renewal_date?->toDateString(),
                        'account_number' => $s->account_number];
                })
        );
    }

    /** Full subscription detail for the edit drawer. */
    public function subscription(\App\Models\Subscription $subscription): JsonResponse
    {
        return response()->json([
            'id' => $subscription->id,
            'subscription_name' => $subscription->subscription_name,
            'vendor_id' => $subscription->vendor_id,
            'user_id' => $subscription->user_id,
            'account_number' => $subscription->account_number,
            'serial_number' => $subscription->serial_number,
            'amount' => $subscription->amount,
            'renewal_date' => $subscription->renewal_date?->toDateString(),
            'renewalfrequency' => $subscription->renewalfrequency,
            'is_active' => $subscription->is_active,
            'notes' => $subscription->notes,
        ]);
    }

    public function updateSubscription(Request $request, \App\Models\Subscription $subscription): JsonResponse
    {
        return $this->applyUpdate($subscription, $request, [
            'subscription_name' => 'required|string|max:255',
            'vendor_id' => 'nullable|integer|exists:vendors,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'account_number' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:255',
            'amount' => 'nullable|numeric',
            'renewal_date' => 'nullable|date',
            'renewalfrequency' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);
    }

    public function login(\App\Models\Login $login): JsonResponse
    {
        // never return the decrypted password
        return response()->json($login->only(['id', 'vendor_id', 'login_name', 'login_id', 'url', 'type', 'notes', 'is_active', 'is_restricted']));
    }

    /** All vendors as {id,label} for the searchable vendor picker. */
    public function vendorOptions(): JsonResponse
    {
        return response()->json(
            Vendor::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($v) => ['id' => $v->id, 'label' => $v->name])
        );
    }

    public function updateLogin(Request $request, \App\Models\Login $login): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'vendor_id' => 'nullable|integer|exists:vendors,id',
            'login_name' => 'nullable|string|max:255', 'login_id' => 'nullable|string|max:255',
            'login_pass' => 'nullable|string|max:255', 'url' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:255', 'notes' => 'nullable|string',
            'is_active' => 'boolean', 'is_restricted' => 'boolean',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        if (empty($data['login_pass'])) {
            unset($data['login_pass']); // blank = keep existing secret
        }
        $login->update($data); // login_pass re-encrypts via cast
        return response()->json(['ok' => true]);
    }

    public function destroyLogin(\App\Models\Login $login): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $login->delete();
        return response()->json(['ok' => true]);
    }

    /** Reveal a login's password (for copy). Gated by role + restricted flag; audited. */
    public function loginSecret(\App\Models\Login $login): JsonResponse
    {
        $u = auth()->user();
        abort_if($u?->role === 'User', 403);
        abort_if($login->is_restricted && ! in_array($u->role, ['SuperAdmin', 'IT Admin'], true), 403);
        \Illuminate\Support\Facades\Log::info('credential.revealed', ['login' => $login->id, 'by' => $u->id]);
        $login->loadMissing('user:id,name,last,cell');
        return response()->json([
            'password' => $login->login_pass, // decrypted via cast
            'cell' => $login->user?->cell,
            'name' => $login->user ? trim("{$login->user->name} {$login->user->last}") : null,
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
        $location->load(['rooms:id,location_id,name,room_type'])->loadCount('devices');
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

    /** Create a company. Only SuperAdmin / IT Admin may add. Enforces the plan tenant cap. */
    public function storeCompany(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        if (\App\Support\Plan::atTenantCap()) {
            $cap = \App\Support\Plan::maxTenants();
            $price = \App\Support\Plan::extraTenantPrice();
            return response()->json([
                'message' => "You're at the {$cap}-tenant limit for this plan.",
                'errors' => ['_' => ["Tenant limit reached ({$cap}). Beyond {$cap} is Enterprise — additional tenants are \${$price}/year each. Contact sales to raise the limit."]],
            ], 422);
        }

        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:2',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        $company = \App\Models\Company::create($v->validated() + ['active' => true]);
        return response()->json($company, 201);
    }

    /** Sub-clients of a company (River-style hierarchy) — POC placeholder. */
    public function companyClients(\App\Models\Company $company): JsonResponse
    {
        return response()->json([]); // clients table not yet migrated; empty POC
    }

    /** Shared offset pager -> { items, total, has_more }. */
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
