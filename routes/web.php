<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// Land on People instead of the generic scaffold dashboard.
Route::get('/dashboard', fn () => redirect()->route('people.index'))
    ->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::post('/switch-company', function (\Illuminate\Http\Request $r) {
        $id = $r->input('company_id');
        app(\App\Support\Contracts\TenantResolver::class)->setActive($id ? (int) $id : null);
        return back();
    })->name('company.switch');

    // Top-level workspace groups (each renders sub-tabs in the list column)
    $group = fn ($g) => fn () => \Inertia\Inertia::render('Workspace', ['group' => $g]);
    Route::get('/people', $group('people'))->name('people.index');
    Route::get('/assets', $group('assets'))->name('assets.index');
    Route::get('/tasks', fn () => \Inertia\Inertia::render('Tasks/Index'))->name('tasks.index');
    Route::get('/docs', fn () => \Inertia\Inertia::render('Docs/Index'))->name('docs.index');

    // Docs wiki data
    $doc = \App\Http\Controllers\DocController::class;
    Route::get('/data/docs', [$doc, 'tree']);
    Route::post('/data/docs', [$doc, 'store']);
    Route::get('/data/docs/{page}', [$doc, 'show']);
    Route::patch('/data/docs/{page}', [$doc, 'update']);
    Route::delete('/data/docs/{page}', [$doc, 'destroy']);

    // Tasks
    $task = \App\Http\Controllers\TaskController::class;
    Route::get('/data/tasks', [$task, 'index']);
    Route::post('/data/tasks', [$task, 'store']);
    Route::get('/data/tasks/{task}', [$task, 'show']);
    Route::patch('/data/tasks/{task}', [$task, 'update']);
    Route::delete('/data/tasks/{task}', [$task, 'destroy']);

    // Legacy single-entity paths -> their new group
    foreach (['devices' => 'assets', 'rooms' => 'assets', 'locations' => 'assets',
              'vendors' => 'people', 'onboarding' => 'people', 'staff' => 'people'] as $old => $to) {
        Route::get("/$old", fn () => redirect()->route("$to.index"));
    }

    // Companies management (reachable directly; not in primary nav)
    Route::get('/companies', fn () => \Inertia\Inertia::render('Entity/Index', ['entity' => 'companies', 'title' => 'Companies']))->name('companies.index');
    Route::get('/clients', fn () => redirect()->route('companies.index'))->name('clients.index');

    // Settings (moved to header gear / user menu)
    Route::get('/settings', fn () => \Inertia\Inertia::render('Settings/Index'))->name('settings.index');
    Route::get('/m365', fn () => redirect()->route('settings.index'))->name('m365.index');

    // JSON data endpoints (infinite scroll + detail)
    $dc = \App\Http\Controllers\DataController::class;
    Route::get('/data/devices', [$dc, 'devices']);
    Route::post('/data/devices', [$dc, 'storeDevice']);
    Route::get('/data/device-types', [$dc, 'deviceTypes']);
    Route::get('/data/devices/{device}', [$dc, 'device']);
    Route::get('/data/people', [$dc, 'people']);
    Route::get('/data/departments', [$dc, 'departments']);
    Route::get('/data/people/{person}', [$dc, 'person']);
    Route::get('/data/people/{person}/logins', [$dc, 'personLogins']);
    Route::post('/data/people/{person}/logins', [$dc, 'storePersonLogin']);
    Route::get('/data/device-options', [$dc, 'deviceOptions']);
    Route::get('/data/people-options', [$dc, 'peopleOptions']);
    Route::get('/data/people/{person}/devices', [$dc, 'personDevices']);
    Route::post('/data/people/{person}/devices', [$dc, 'attachPersonDevice']);
    Route::delete('/data/people/{person}/devices/{device}', [$dc, 'detachPersonDevice']);
    Route::get('/data/people/{person}/subscriptions', [$dc, 'personSubscriptions']);
    Route::patch('/data/people/{person}', [$dc, 'updatePerson']);
    Route::patch('/data/devices/{device}', [$dc, 'updateDevice']);
    Route::patch('/data/vendors/{vendor}', [$dc, 'updateVendor']);
    Route::patch('/data/rooms/{room}', [$dc, 'updateRoom']);
    Route::patch('/data/locations/{location}', [$dc, 'updateLocation']);
    Route::patch('/data/companies/{company}', [$dc, 'updateCompany']);

    Route::get('/data/vendor-options', [$dc, 'vendorOptions']);
    Route::get('/data/logins/{login}', [$dc, 'login']);
    Route::patch('/data/logins/{login}', [$dc, 'updateLogin']);
    Route::delete('/data/logins/{login}', [$dc, 'destroyLogin']);
    Route::get('/data/logins/{login}/secret', [$dc, 'loginSecret']);
    Route::get('/data/devices/{device}/users', [$dc, 'deviceUsers']);
    Route::get('/data/vendors', [$dc, 'vendors']);
    Route::get('/data/vendors/{vendor}', [$dc, 'vendor']);
    Route::get('/data/vendors/{vendor}/logins', [$dc, 'vendorLogins']);
    Route::get('/data/vendors/{vendor}/subscriptions', [$dc, 'vendorSubscriptions']);
    Route::get('/data/rooms', [$dc, 'rooms']);
    Route::get('/data/rooms/{room}', [$dc, 'room']);
    Route::get('/data/locations', [$dc, 'locations']);
    Route::get('/data/locations/{location}', [$dc, 'location']);
    Route::get('/data/companies', [$dc, 'companies']);
    Route::post('/data/companies', [$dc, 'storeCompany']);
    Route::get('/data/companies/{company}', [$dc, 'company']);
    Route::get('/data/companies/{company}/clients', [$dc, 'companyClients']);

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
