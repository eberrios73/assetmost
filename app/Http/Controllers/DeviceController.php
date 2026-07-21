<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeviceController extends Controller
{
    // Devices are auto-scoped to the current company via the model's global scope.
    public function index(Request $request): Response
    {
        $devices = Device::query()
            ->with(['company:id,name', 'location:id,name', 'room:id,name'])
            ->when($request->string('search')->toString(), function ($q, $s) {
                $q->where(fn ($w) => $w->where('asset_tag', 'ilike', "%{$s}%")
                    ->orWhere('computer_name', 'ilike', "%{$s}%")
                    ->orWhere('type', 'ilike', "%{$s}%")
                    ->orWhere('serial_num', 'ilike', "%{$s}%"));
            })
            ->orderBy('asset_tag')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Devices/Index', [
            'devices' => $devices,
            'filters' => ['search' => $request->string('search')->toString()],
        ]);
    }

    public function show(Device $device): Response
    {
        $device->load(['company:id,name', 'location:id,name', 'room:id,name', 'users:id,name,last,email']);
        return Inertia::render('Devices/Index', [
            'devices' => Device::query()->orderBy('asset_tag')->paginate(30),
            'selected' => $device,
        ]);
    }
}
